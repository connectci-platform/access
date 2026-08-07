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
 *    registrants attached requires confirmed=TRUE and previews first (moving
 *    an occurrence's date must never be silent to someone already signed up);
 *    a date change with no future registrants, or a location-only edit,
 *    proceeds without confirmation. On a confirmed date change,
 *    instance_modification_notification (enabled + retemplated for a
 *    reschedule) emails the future registrants; contrib's own
 *    recurring_events_registration_entity_update() enqueues that key whenever
 *    an eventinstance's date field actually changes on save, so this fires
 *    identically whether the save comes from this endpoint or the node/
 *    eventinstance edit form — this controller does not also enqueue.
 *  - add_occurrence (POST /api/2.3/event-series/{eventseries}/occurrence)
 *    appends a one-off date to a CUSTOM series by writing the singular
 *    custom_date daterange field (start_date/end_date → value/end_value). A
 *    RULE series (weekly_recurring_date, …) is REFUSED with recurrence_conflict:
 *    a one-off cannot be appended to a rule series.
 *
 * The destructive edge is on add_occurrence: appending a date to a PUBLISHED
 * custom series changes the series' recur config, and recurring_events fires its
 * RecreateEventInstanceCreator on a published-series recur-config change — a
 * hard-delete + recreate of ALL instances, which would destroy any attached
 * registrants. Rather than a force-to-proceed escape hatch, add_occurrence
 * appends the date and calls $eventseries->validate(): the
 * EventSeriesRescheduleBlock constraint refuses the save (has_registrations,
 * 409) when the series has FUTURE registrants — past-only registrants do not
 * block, since a rebuild cannot harm a registrant whose instance already
 * ended. A DRAFT series never materializes an appended custom_date into a
 * real instance (the module recreate is gated on published), so it is
 * refused up front (invalid_state, 409) rather than reporting a phantom
 * success.
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
   * envelope reports registrants_affected. Editing the instance is a content
   * edit, not a series recur-config change, so no recreate fires.
   */
  public function testEditOccurrenceChangesDate(): void {
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
   * true count — the prospective new date is what contrib's hook notifies
   * against, not the instance's current (past) stored date.
   *
   * Before the gate was keyed on the prospective date, this scenario gated on
   * countFutureForInstance() against the OLD (past) date — 0 — so it never
   * previewed and reported registrants_to_notify = 0, while contrib's
   * post-save hook (keyed on the NEW date) would silently mass-notify anyway.
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
    $instance->set('moderation_state', 'published')->save();
    $series->set('moderation_state', 'published')->save();
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

    // Confirmed: the move applies and contrib's hook enqueues the notice
    // against the new (future) date.
    $confirmedResponse = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, ['confirmed' => TRUE], [
      'date' => ['value' => '2099-07-01T10:00:00', 'end_value' => '2099-07-01T12:00:00'],
    ]);
    $this->assertSame(200, $confirmedResponse->getStatusCode(), $confirmedResponse->getContent());
    $after = $queue->numberOfItems();
    $this->assertSame($before + 1, $after, 'The past-to-future move enqueues exactly one notification.');

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertStringContainsString('2099-07-01', $reloaded->get('date')->value);
  }

  /**
   * A FUTURE instance corrected to the PAST requires NO confirm and notifies
   * nobody — contrib's hook checks the NEW date post-save and never fires
   * once it lands in the past.
   *
   * Before the gate was keyed on the prospective date, this scenario gated on
   * countFutureForInstance() against the OLD (future) date — non-zero — so
   * it wrongly demanded confirmation and reported a registrants_to_notify
   * count that would never actually be notified (contrib's hook checks the
   * date AFTER save, sees it's now past, and never enqueues).
   */
  public function testEditOccurrenceFutureToPastRequiresNoConfirmAndNoNotify(): void {
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

    // Unconfirmed, no preview required: the prospective new date is past.
    $response = $this->doOccurrence('edit', (int) $instance->id(), $coordinator, [], [
      'date' => ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertArrayNotHasKey('status', $data, 'A future-to-past correction is never gated to a preview.');
    $this->assertSame(0, $data['registrants_affected'], 'The envelope must not promise a notify that never happens.');

    $after = $queue->numberOfItems();
    $this->assertSame($before, $after, 'contrib\'s hook never fires once the new date is past — nothing enqueued.');

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
   * add_occurrence appends a durable date to a custom series → a new instance.
   *
   * A published custom series seeded with one custom_date owns one instance.
   * Appending a second date changes the recur config, so on save the module
   * recreates instances from the two custom_dates → the instance count grows.
   * The appended custom_date is durable on the series.
   *
   * The rebuild's newly-created instance must be PUBLISHED, not left at its
   * workflow default of draft — createInstances() always spawns draft, and
   * contrib's own state-sync no-ops on this site's workflow-id mismatch (see
   * PastPreservingEventInstanceCreator's PUBLISH SYNC docblock). Asserted on
   * the newly-created instance SPECIFICALLY (the one NOT present before this
   * call) rather than via makePublishedCustomSeriesWithDate()'s own
   * hand-publish loop, which only ran once at series creation and would mask
   * a regression here — it never re-publishes an instance this later rebuild
   * spawns.
   */
  public function testAddOccurrenceAppendsToCustomSeries(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $beforeIds = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));
    $before = count($beforeIds);

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, ['confirmed' => TRUE], [
      'start_date' => '2099-08-01T10:00:00',
      'end_date' => '2099-08-01T12:00:00',
    ]);
    $this->assertSame(200, $response->getStatusCode());

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    // The custom_date field now carries both dates (durable append).
    $dates = array_column($reloaded->get('custom_date')->getValue(), 'value');
    $this->assertContains('2099-08-01T10:00:00', $dates);
    // A new instance spawned for the added date.
    $afterInstances = $this->loadInstances($reloaded);
    $this->assertGreaterThan($before, count($afterInstances));

    $newInstances = array_filter($afterInstances, fn ($i) => !in_array((int) $i->id(), $beforeIds, TRUE));
    $this->assertNotEmpty($newInstances, 'At least one new instance was created by the rebuild.');
    foreach ($newInstances as $newInstance) {
      $this->assertSame(
        'published',
        $newInstance->get('moderation_state')->value,
        'A published series\' rebuild-created instance must itself be published, not left at the workflow default of draft.',
      );
    }
  }

  /**
   * add_occurrence on a RULE series refuses recurrence_conflict, writes nothing.
   *
   * A one-off date cannot be appended to a rule-based series (weekly_recurring_
   * date, …): included_dates is a whitelist filter, not an append surface. The
   * refusal is a 409 recurrence_conflict and the series' custom_date stays empty.
   */
  public function testAddOccurrenceRefusesOnRuleSeries(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, ['confirmed' => TRUE], [
      'start_date' => '2099-08-01T10:00:00',
      'end_date' => '2099-08-01T12:00:00',
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('recurrence_conflict', json_decode($response->getContent(), TRUE)['error']);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame([], $reloaded->get('custom_date')->getValue());
  }

  /**
   * A published custom series WITH a future registrant refuses has_registrations.
   *
   * Appending a date to a published custom series is a recur-config change;
   * saving fires the module recreate, which would hard-delete the existing
   * instances and destroy their registrants. add_occurrence appends the date
   * and calls $eventseries->validate() — the EventSeriesRescheduleBlock
   * constraint (covered directly in EventSeriesRescheduleBlockTest) refuses the
   * save while the series has a FUTURE registrant. The refusal is a 409
   * has_registrations and nothing is written: the instance count is unchanged.
   */
  public function testAddOccurrenceRefusesPublishedSeriesWithFutureRegistrants(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);
    $before = count($this->loadInstances($series));

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, ['confirmed' => TRUE], [
      'start_date' => '2099-08-01T10:00:00',
      'end_date' => '2099-08-01T12:00:00',
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('has_registrations', json_decode($response->getContent(), TRUE)['error']);

    // Nothing added.
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertCount($before, $reloaded->get('event_instances')->referencedEntities());
  }

  /**
   * A draft custom series refuses invalid_state.
   *
   * A draft series' recur-config change does not fire the module recreate (it
   * is gated on published), so appending a custom_date would not materialize a
   * visible instance. Rather than report a phantom success, add_occurrence
   * refuses up front.
   */
  public function testAddOccurrenceRefusesDraftSeries(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, [], [
      'start_date' => '2099-08-01T10:00:00',
      'end_date' => '2099-08-01T12:00:00',
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A published series whose ONLY registrant is on a PAST instance proceeds.
   *
   * The reschedule-block constraint keys on FUTURE registrants only — a
   * rebuild cannot harm a registrant whose instance has already ended. This is
   * a deliberate behavior change from the old force-gate (which blocked on ANY
   * registrant, past or future): a series with only past registrants now
   * proceeds without needing a force escape hatch.
   *
   * The series is seeded with a PAST custom_date (rather than reparenting a
   * second instance onto a series that already owns a future one) so it owns
   * exactly one, past-dated, module-spawned instance going into add_occurrence
   * — the registrant is attached to that single instance.
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
    $before = count($this->loadInstances($series));
    $pastInstanceId = (int) $pastInstance->id();
    $registrantId = (int) $registrant->id();

    $response = $this->doOccurrence('add', (int) $series->id(), $coordinator, ['confirmed' => TRUE], [
      'start_date' => '2099-08-01T10:00:00',
      'end_date' => '2099-08-01T12:00:00',
    ]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);

    // The appended date is durable and a new instance spawned.
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $dates = array_column($reloaded->get('custom_date')->getValue(), 'value');
    $this->assertContains('2099-08-01T10:00:00', $dates);
    $this->assertGreaterThan($before, count($this->loadInstances($reloaded)));

    // The past instance survives the rebuild with the SAME entity id, and its
    // registrant is untouched — PastPreservingEventInstanceCreator (wired via
    // access_events_recurring_events_event_instance_creator_plugin_alter())
    // must never delete an ended instance, even though this add_occurrence
    // call is exactly the kind of recur-config change that would otherwise
    // fire contrib's destructive RecreateEventInstanceCreator.
    $survivingPastInstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($pastInstanceId);
    $this->assertNotNull($survivingPastInstance, 'The past instance was not deleted by the append.');
    $survivingRegistrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrantId);
    $this->assertNotNull($survivingRegistrant, 'The past instance registrant was not deleted by the append.');
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

    $addResponse = $this->doOccurrence('add', (int) $series->id(), $stranger, ['confirmed' => TRUE], [
      'start_date' => '2099-08-01T10:00:00',
      'end_date' => '2099-08-01T12:00:00',
    ]);
    $this->assertSame(409, $addResponse->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($addResponse->getContent(), TRUE)['error']);
  }

}
