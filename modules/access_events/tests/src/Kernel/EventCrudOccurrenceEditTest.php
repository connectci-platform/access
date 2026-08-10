<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\Role;

/**
 * Covers PATCH event-occurrences/{id} and POST event-series/{id}/occurrence.
 *
 * Two occurrence-level write endpoints:
 *  - edit_occurrence (PATCH /api/2.3/event-occurrences/{eventinstance}) changes
 *    ONE instance's date/location content fields. Editing an instance is a
 *    content edit on the instance, not a series recur-config change, so it
 *    does NOT trigger the module's recreate. A date change with FUTURE
 *    registrants attached on a LIVE (published) instance requires
 *    confirmed=TRUE and previews first (moving an occurrence's date must
 *    never be silent to someone already signed up); a date change with no
 *    future registrants, or a location-only edit, proceeds without
 *    confirmation. A DARK (non-published) instance's preview always reports
 *    registrants_to_notify: 0 plus a note that registrants are notified when
 *    the event is restored — the real post-save reaction only ever fires off
 *    a live, moderated instance. On a confirmed date change,
 *    instance_modification_notification (enabled + retemplated for a
 *    reschedule) emails the affected registrants:
 *    EventStateReactions::reactToInstanceModified() enqueues that key
 *    whenever the saved instance is published both before and after the
 *    save AND the date field actually changed, so this fires identically
 *    whether the save comes from this endpoint or the node/eventinstance
 *    edit form — this controller does not also enqueue. Unlike the old
 *    contrib hook_entity_update() this replaces, the reaction notifies off
 *    the UNION of the old and new end dates, not just the new one — a
 *    registrant who was future under the OLD schedule is still notified even
 *    if the edit moves the event into the past.
 *  - add_occurrence (POST /api/2.3/event-series/{eventseries}/occurrence)
 *    directly CREATES one new eventinstance on the series via the same
 *    contrib chain the series' own machinery uses (createEventInstance() →
 *    configureDefaultInheritances() → save()) — NOT a custom_date config
 *    write. The new instance's birth moderation_state follows the parent's
 *    published/archived status, so this works on BOTH a published and an
 *    archived series (the old rule-series recurrence_conflict refusal is
 *    gone: a direct create is not a recur-config change, so it also works on
 *    a rule series). A duplicate start-timestamp collision with an existing
 *    instance refuses duplicate_date; if the colliding instance is itself a
 *    cancelled (archived + individually_cancelled) twin, the message instead
 *    points the caller at restore_occurrence.
 *
 * A DRAFT series' new instance would be born archived like its dark parent —
 * never visibly published — so add_occurrence refuses invalid_state up front
 * rather than report a phantom success.
 */
class EventCrudOccurrenceEditTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'link',
    'datetime',
    'datetime_range',
    'field_inheritance',
    'recurring_events',
    'recurring_events_registration',
    'taxonomy',
    'node',
    'filter',
    'workflows',
    'content_moderation',
    'access_affinitygroup',
    'key',
    'access_events',
    'access_misc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and access_events_entity_access (reads
    // field_other_authors on every series access check). Seed the empty
    // site-level fields those hooks touch, mirroring EventCrudCancelOccurrenceTest.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }

    // edit_occurrence writes field_location on the instance; seed it.
    if (!FieldStorageConfig::loadByName('eventinstance', 'field_location')) {
      FieldStorageConfig::create([
        'entity_type' => 'eventinstance',
        'field_name' => 'field_location',
        'type' => 'string',
        'cardinality' => 1,
      ])->save();
      FieldConfig::create([
        'entity_type' => 'eventinstance',
        'field_name' => 'field_location',
        'bundle' => 'default',
        'label' => 'Location',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('eventseries', 'field_other_authors')) {
      FieldStorageConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
      FieldStorageConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => 1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    // In production every authenticated user holds 'add eventseries entity'.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A confirmed date change on a future instance with a registrant applies
   * + reports the registrant count.
   *
   * The date override is applied to the single targeted instance; the
   * envelope reports registrants_affected, drained from what the state
   * reaction actually recorded. Editing the instance is a content edit, not
   * a series recur-config change, so no recreate fires.
   */
  public function testEditOccurrenceChangesDate(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event Rescheduled')
      ->set('notifications.instance_modification_notification.body', 'The event has been rescheduled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    // Attach a registrant so the reported count is meaningfully non-zero.
    $this->registerUser($this->createUser(), $instance);

    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, ['confirmed' => TRUE], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
      'field_location' => 'Room 200',
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertStringContainsString('2099-07-01', $reloaded->get('date')->value);
    $this->assertSame('Room 200', $reloaded->get('field_location')->value);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame((int) $instance->id(), (int) $data['eventinstance_id']);
    $this->assertSame(1, $data['registrants_affected']);
  }

  /**
   * A date change on a future instance WITH a registrant, unconfirmed,
   * previews and writes nothing.
   *
   * Moving a registered occurrence's date must never be silent. Without
   * confirmed=TRUE the endpoint reports what would change and how many
   * registrants would be notified, but writes nothing — the instance's date
   * is unchanged on reload.
   */
  public function testEditOccurrenceDateChangeWithRegistrantWithoutConfirmedPreviews(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $originalDate = $instance->get('date')->value;
    $this->registerUser($this->createUser(), $instance);

    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $this->assertFalse($data['executed']);
    $this->assertSame(1, $data['registrants_to_notify']);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertSame($originalDate, $reloaded->get('date')->value);
  }

  /**
   * A confirmed date change on a future instance with a registrant enqueues
   * exactly ONE reschedule notification for that registrant.
   *
   * instance_modification_notification is enabled + retemplated for a
   * reschedule (see the parent config); contrib's own
   * recurring_events_registration_entity_update() enqueues it on save because
   * the instance's date field actually changed. This controller must not
   * ALSO enqueue — otherwise a registrant would get the email twice.
   */
  public function testEditOccurrenceConfirmedDateChangeNotifiesOnce(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event Rescheduled')
      ->set('notifications.instance_modification_notification.body', 'The event has been rescheduled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, ['confirmed' => TRUE], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $after = $queue->numberOfItems();
    $this->assertSame($before + 1, $after, 'Exactly one notification is enqueued, not zero and not two.');

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertStringContainsString('2099-07-01', $reloaded->get('date')->value);
  }

  /**
   * A date change on a future instance with NO registrants proceeds without
   * confirmed — nothing to notify, so no confirmation friction is needed.
   */
  public function testEditOccurrenceDateChangeNoRegistrantsProceedsWithoutConfirmed(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];

    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertStringContainsString('2099-07-01', $reloaded->get('date')->value);
  }

  /**
   * A location-only edit on a future instance WITH a registrant proceeds
   * without confirmed and enqueues no notification.
   *
   * No date field changed, so contrib's hook_entity_update() never enqueues
   * instance_modification_notification (it compares the date field's
   * serialized value before/after save) — a location correction is not a
   * reschedule and must not require confirmation or notify anyone.
   */
  public function testEditOccurrenceLocationOnlyProceedsWithoutConfirmedOrNotification(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event Rescheduled')
      ->set('notifications.instance_modification_notification.body', 'The event has been rescheduled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'field_location' => 'Room 200',
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);

    $after = $queue->numberOfItems();
    $this->assertSame($before, $after, 'A location-only edit enqueues no notification.');

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertSame('Room 200', $reloaded->get('field_location')->value);
  }

  /**
   * A PAST instance moved to the FUTURE requires confirm and reports the
   * true count — the prospective new date is what the preview gate warns
   * against, not the instance's current (past) stored date.
   *
   * Before the gate was keyed on the prospective date, this scenario gated on
   * countFutureForInstance() against the OLD (past) date — 0 — so it never
   * previewed and reported registrants_to_notify = 0, while the actual save
   * would silently mass-notify anyway (a live instance stays live across this
   * move, so EventStateReactions::reactToInstanceModified() fires either
   * way). The confirmed response's registrants_affected is asserted against
   * the REAL drained notified count, not a guess.
   */
  public function testEditOccurrencePastToFutureRequiresConfirmAndNotifies(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event Rescheduled')
      ->set('notifications.instance_modification_notification.body', 'The event has been rescheduled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $instance->set('date', ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00']);
    // The series must be published BEFORE the instance: the occurrence-publish-under-unpublished-event refusal refuses
    // publishing an occurrence while its parent series is still dark.
    $series->set('moderation_state', 'published')->save();
    $instance->set('moderation_state', 'published')->save();
    $this->registerUser($this->createUser(), $instance);

    // Unconfirmed: must preview, reporting the true (non-zero) count — the
    // old-date gate would have reported 0 and skipped the preview entirely.
    $previewResponse = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $previewResponse->getStatusCode(), $previewResponse->getContent());
    $previewData = json_decode($previewResponse->getContent(), TRUE);
    $this->assertSame('preview', $previewData['status']);
    $this->assertFalse($previewData['executed']);
    $this->assertSame(1, $previewData['registrants_to_notify']);
    // Nothing written yet.
    $this->assertSame(
      '2000-01-01T10:00:00',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id())->get('date')->value,
    );

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    // Confirmed: the move applies and the state reaction enqueues the notice
    // against the new (future) date.
    $confirmedResponse = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, ['confirmed' => TRUE], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $confirmedResponse->getStatusCode(), $confirmedResponse->getContent());
    $confirmedData = json_decode($confirmedResponse->getContent(), TRUE);
    $this->assertSame(1, $confirmedData['registrants_affected'], 'The real drained notified count, not a guess.');
    $after = $queue->numberOfItems();
    $this->assertSame($before + 1, $after, 'The past-to-future move enqueues exactly one notification.');

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertStringContainsString('2099-07-01', $reloaded->get('date')->value);
  }

  /**
   * A FUTURE instance corrected to the PAST requires NO confirm (the preview
   * gate is keyed on the PROSPECTIVE new date, which here is past) but STILL
   * notifies the registrant — they were legitimately future under the OLD
   * schedule and deserve to know the event moved, even though the row now
   * holds a past date.
   *
   * This is the opposite of what contrib's own hook_entity_update() did (it
   * checked ONLY the post-save new date, so a future→past correction fired
   * no notice at all) — EventStateReactions::reactToInstanceModified() /
   * CancellationNotifier::enqueueModificationGated() instead notify when
   * EITHER the old or the new end date is not verifiably past, which a "was
   * future, now past" edit satisfies via the OLD boundary. Confirming this
   * with a raw entity-level save (not the API) proves the DB genuinely holds
   * the past date at the point notification decides to fire — a "count
   * against the DB post-save" implementation would wrongly see nothing here.
   */
  public function testEditOccurrenceFutureToPastStillNotifies(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event Rescheduled')
      ->set('notifications.instance_modification_notification.body', 'The event has been rescheduled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    // Unconfirmed, no preview required: the prospective new date is past, so
    // the preview gate sees no reschedule RISK to confirm (nobody is stranded
    // by a move that lands in the past) — but the save proceeds straight
    // through and the real reaction still fires off the OLD boundary.
    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'date' => ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertArrayNotHasKey('status', $data, 'A future-to-past correction is never gated to a preview.');
    $this->assertSame(1, $data['registrants_affected'], 'The registrant was future under the OLD date — still notified.');

    $after = $queue->numberOfItems();
    $this->assertSame($before + 1, $after, 'The old-boundary registrant is still notified even though the new date is past.');

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertStringContainsString('2000-01-01', $reloaded->get('date')->value);
  }

  /**
   * A plain entity-level date change on a future, registered instance (the
   * browser edit form's save shape) also enqueues the same reschedule
   * notification — proving parity between this API and the node/eventinstance
   * edit form, both of which land on the same contrib hook_entity_update().
   */
  public function testEntityLevelDateChangeNotifiesLikeTheEditForm(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event Rescheduled')
      ->set('notifications.instance_modification_notification.body', 'The event has been rescheduled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    // Models the browser eventinstance edit form: a direct entity save with a
    // changed date, bypassing the API controller entirely.
    $instance->set('date', ['value' => '2099-09-01T10:00:00', 'end_value' => '2099-09-01T12:00:00']);
    $instance->save();

    $after = $queue->numberOfItems();
    $this->assertSame($before + 1, $after, 'A direct entity-level date save enqueues the same notification as the API.');
  }

  /**
   * A DARK (archived) instance's date-change preview reports
   * registrants_to_notify: 0 plus the restore-notice note, never a nonzero
   * count contrib's hook will never actually send.
   */
  public function testEditDarkOccurrencePreviewReportsZero(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);
    $this->doOccurrence('cancel', (int) $instance->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id())->get('moderation_state')->value,
    );

    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $this->assertSame(0, $data['registrants_to_notify']);
    $this->assertSame('Registrants are notified when the event is restored.', $data['note']);

    // Nothing written.
    $this->assertNotSame(
      '2099-07-01T10:00:00',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id())->get('date')->value,
    );
  }

  /**
   * add_occurrence directly creates one new instance on a published custom
   * series — no custom_date write, no rebuild of anything else.
   */
  public function testAddOccurrenceCreatesInstanceOnPublishedSeries(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $beforeIds = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => '2099-08-01T10:00:00', 'end_value' => '2099-08-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame('published', $data['moderation_state']);

    $newInstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($data['eventinstance_id']);
    $this->assertNotNull($newInstance);
    $this->assertNotContains((int) $newInstance->id(), $beforeIds, 'A genuinely new instance was created.');
    $this->assertSame('published', $newInstance->get('moderation_state')->value, 'Born published — the parent series is published.');
    $this->assertStringContainsString('2099-08-01', $newInstance->get('date')->value);

    // The pre-existing instances are UNTOUCHED — no rebuild.
    $afterIds = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));
    foreach ($beforeIds as $id) {
      $this->assertContains($id, $afterIds, 'A direct create must not delete/recreate any existing instance.');
    }
  }

  /**
   * add_occurrence on an ARCHIVED (dark) series still succeeds, and the new
   * instance is born ARCHIVED to match its dark parent.
   *
   * The old custom_date/rebuild path could only ever fire off a PUBLISHED
   * series; the direct-create replacement has no such restriction — the
   * birth-state alter follows the parent's isPublished() either way.
   */
  public function testAddOccurrenceOnArchivedSeriesBornArchived(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $series->set('moderation_state', 'archived')->save();
    $this->assertSame('archived', \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id())->get('moderation_state')->value);

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => '2099-08-01T10:00:00', 'end_value' => '2099-08-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame('archived', $data['moderation_state']);

    $newInstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($data['eventinstance_id']);
    $this->assertSame('archived', $newInstance->get('moderation_state')->value, 'Born archived — the parent series is dark.');
  }

  /**
   * add_occurrence on a RULE series now SUCCEEDS (direct create is not a
   * recur-config change) — extending a weekly series past its rule end with
   * a one-off date is the sanctioned "add a date" story.
   */
  public function testAddOccurrenceSucceedsOnRuleSeries(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);
    $series->set('moderation_state', 'published')->save();

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => '2099-08-01T10:00:00', 'end_value' => '2099-08-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);

    $newInstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($data['eventinstance_id']);
    $this->assertNotNull($newInstance);
    $this->assertStringContainsString('2099-08-01', $newInstance->get('date')->value);
  }

  /**
   * A duplicate start collides with an existing (non-cancelled) instance —
   * refused duplicate_date, nothing created.
   */
  public function testAddOccurrenceDuplicateDateRefused(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $existing = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $existingStart = $existing->get('date')->value;
    $before = count($this->loadInstances($series));

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => $existingStart, 'end_value' => $existing->get('date')->end_value],
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('duplicate_date', $data['error']);
    $this->assertSame('An occurrence already exists at this date.', $data['message']);

    $this->assertCount($before, $this->loadInstances($series));
  }

  /**
   * A duplicate start collides with an existing instance that is ITSELF
   * cancelled (archived + individually_cancelled) — the cancelled-twin
   * variant message points the caller at restore_occurrence instead.
   */
  public function testAddOccurrenceDuplicateDateCancelledTwinPointsAtRestore(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $existing = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $existingStart = $existing->get('date')->value;
    $existingEnd = $existing->get('date')->end_value;

    $this->doOccurrence('cancel', (int) $existing->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($existing->id())->get('moderation_state')->value,
    );

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => $existingStart, 'end_value' => $existingEnd],
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('duplicate_date', $data['error']);
    $this->assertSame(
      'An occurrence already exists at this date; it is cancelled — use restore_occurrence to bring it back instead of adding a duplicate.',
      $data['message'],
    );
  }

  /**
   * A draft custom series refuses invalid_state.
   *
   * A draft series' new instance would be born archived (following its dark
   * parent), never visibly published, so add_occurrence refuses up front
   * rather than report a phantom success.
   */
  public function testAddOccurrenceRefusesDraftSeries(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => '2099-08-01T10:00:00', 'end_value' => '2099-08-01T12:00:00'],
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A published series whose ONLY registrant is on a PAST instance proceeds
   * without incident — a direct create touches nothing but the new row, so
   * a past registrant elsewhere on the series is irrelevant to add_occurrence
   * (unlike the old rebuild-based path, which had to reason about it).
   */
  public function testAddOccurrenceProceedsWithPastOnlyRegistrants(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([(int) $coordinator->id()]);
    $series = EventSeries::create([
      'title' => 'Coordinator Custom Event, Past',
      'body' => 'A published custom coordinator-owned event with a past date.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
      'custom_date' => [
        ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
      ],
    ]);
    // The insert hook spawns one (past-dated) instance from the seeded custom_date.
    $series->save();
    $series->set('moderation_state', 'published')->save();
    $instances = $this->loadInstances($series);
    $pastInstance = $instances[array_key_first($instances)];
    $pastInstance->set('moderation_state', 'published')->save();
    $registrant = $this->registerUser($this->createUser(), $pastInstance);
    $pastInstanceId = (int) $pastInstance->id();
    $registrantId = (int) $registrant->id();

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'date' => ['value' => '2099-08-01T10:00:00', 'end_value' => '2099-08-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);

    // The past instance and its registrant are untouched — a direct create
    // never looks at, let alone deletes, any other instance.
    $survivingPastInstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($pastInstanceId);
    $this->assertNotNull($survivingPastInstance, 'The past instance was not touched by the direct create.');
    $survivingRegistrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrantId);
    $this->assertNotNull($survivingRegistrant, 'The past instance registrant was not touched by the direct create.');
  }

  /**
   * A non-coordinator is refused on both edit_occurrence and add_occurrence.
   */
  public function testNonCoordinatorRefusedOnBoth(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $stranger = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];

    $editResponse = $this->doOccurrence('edit', (int) $instance->id(), $stranger, [], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(409, $editResponse->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($editResponse->getContent(), TRUE)['error']);

    $addResponse = $this->doOccurrence('add', (int) $series->id(), $stranger, [], [
      'date' => ['value' => '2099-08-01T10:00:00', 'end_value' => '2099-08-01T12:00:00'],
    ]);
    $this->assertSame(409, $addResponse->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($addResponse->getContent(), TRUE)['error']);
  }

  /**
   * Full API walk, twice: cancel → edit dates → restore, and nothing leaks
   * between cycles in the collector.
   *
   * "Postpone" here means: cancel the occurrence, move its date out (edit
   * while dark — no notify promise), then restore it (which DOES notify, per
   * the reinstatement reaction). Each cycle reports one cancellation notice
   * and one reinstatement notice, and the collector is empty between cycles.
   *
   * The two cycles differ in one deliberate way: the second cycle's restore
   * SUPERSEDES the first cycle's still-unclaimed reinstatement notice for the
   * same registrant and occurrence (its "back on <first date>" wording is now
   * stale — the occurrence has since moved to a later date), so the second
   * cycle's net queue growth is one less than the first. The cancellation
   * notices are never collapsed — a cancel then a reinstate is a legitimate
   * pair — so both cycles' cancellations remain queued.
   */
  public function testPostponeCycleTwiceAccumulatesNothing(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->set('notifications.event_reinstated_notification.enabled', TRUE)
      ->set('notifications.event_reinstated_notification.subject', 'Event reinstated')
      ->set('notifications.event_reinstated_notification.body', 'The event is back on.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $collector = \Drupal::service('access_events.state_change_collector');
    $instanceId = (int) $instance->id();
    $seriesId = (int) $series->id();

    $runCycle = function (string $newDate) use ($coordinator, $instanceId, $queue): array {
      $before = $queue->numberOfItems();

      $cancelResponse = $this->doOccurrence('cancel', $instanceId, $coordinator, ['confirmed' => TRUE]);
      $this->assertSame(200, $cancelResponse->getStatusCode(), $cancelResponse->getContent());
      $cancelData = json_decode($cancelResponse->getContent(), TRUE);

      // The instance is dark (just cancelled) here — the preview branch would
      // otherwise still gate on the raw registrant count even though it
      // reports 0 for a dark instance (see testEditDarkOccurrencePreviewReportsZero),
      // so confirm explicitly to make the date write land.
      $editResponse = $this->doOccurrence('edit', $instanceId, $coordinator, ['confirmed' => TRUE], [
        'date' => ['value' => $newDate . 'T10:00:00', 'end_value' => $newDate . 'T12:00:00'],
      ]);
      $this->assertSame(200, $editResponse->getStatusCode(), $editResponse->getContent());

      $restoreResponse = $this->doOccurrence('restoreOccurrence', $instanceId, $coordinator);
      $this->assertSame(200, $restoreResponse->getStatusCode(), $restoreResponse->getContent());
      $restoreData = json_decode($restoreResponse->getContent(), TRUE);

      $after = $queue->numberOfItems();

      return [
        'cancel_notified' => $cancelData['notified'],
        'restore_notified' => $restoreData['notified'],
        'queue_delta' => $after - $before,
      ];
    };

    $first = $runCycle('2099-09-01');
    // Nothing left over in the collector between cycles.
    $this->assertSame([], $collector->drain('eventinstance', $instanceId));
    $this->assertSame([], $collector->drain('eventseries', $seriesId));

    $second = $runCycle('2099-10-01');
    $this->assertSame([], $collector->drain('eventinstance', $instanceId));
    $this->assertSame([], $collector->drain('eventseries', $seriesId));

    // Both cycles report the same notification outcome (one cancel, one
    // restore each).
    $this->assertSame(1, $first['cancel_notified']);
    $this->assertSame(1, $first['restore_notified']);
    $this->assertSame(1, $second['cancel_notified']);
    $this->assertSame(1, $second['restore_notified']);

    // The first cycle grows the queue by two (a cancellation + a
    // reinstatement). The second cycle grows it by only one: its cancellation
    // is added, its reinstatement is added, and the first cycle's now-stale
    // reinstatement is superseded and removed.
    $this->assertSame(2, $first['queue_delta'], 'One cancellation + one reinstatement notice on the first cycle.');
    $this->assertSame(1, $second['queue_delta'], 'The second cycle supersedes the first cycle\'s stale reinstatement.');

    // Standing queue after two cycles: two cancellations (never collapsed) and
    // exactly one reinstatement (the latest — the earlier one was superseded).
    $this->assertQueueCount('event_cancelled_notification', 2);
    $this->assertQueueCount('event_reinstated_notification', 1);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instanceId);
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
    $this->assertStringContainsString('2099-10-01', $reloaded->get('date')->value);
  }

}
