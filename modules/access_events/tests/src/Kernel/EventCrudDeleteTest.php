<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\Role;

/**
 * Covers DELETE /api/2.3/event-series/{eventseries} — the delete-event endpoint.
 *
 * This is a soft-delete: a series that was ever published is ARCHIVED (the
 * series and each of its instances transition to moderation_state = archived),
 * while a never-published draft is HARD-deleted (which cascades its instance
 * deletes via the recurring_events predelete hook). The op is gated by:
 *  - the coordinator/entity-access helper (userMayManageSeries('delete'));
 *  - a preview/confirm step (nothing is written without confirmed=TRUE);
 *  - registrants are kept and notified on archive (no force gate — cancelling
 *    an event with registrants is the normal path, not a destructive one);
 *  - the `archive` moderation transition permission (published → archived),
 *    which per the live config only news_pm/administrator hold — so an author
 *    or affinity_group_leader who owns the series is refused the archive even
 *    though entity access lets them "manage" it.
 */
class EventCrudDeleteTest extends EventKernelTestBase {

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
    // site-level fields those hooks touch, mirroring EventCrudUpdateTest.
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
   * A published series and each of its instances is archived, not hard-deleted.
   */
  public function testDeletePublishedEventArchivesSeriesAndEachInstance(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instanceIds = array_map(fn ($i) => $i->id(), $series->event_instances->referencedEntities());
    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $reloadedSeries = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertNotNull($reloadedSeries);
    $this->assertSame('archived', $reloadedSeries->get('moderation_state')->value);
    foreach ($instanceIds as $iid) {
      $inst = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($iid);
      $this->assertNotNull($inst);
      $this->assertSame('archived', $inst->get('moderation_state')->value);
    }
  }

  /**
   * Without confirmed the op previews and writes nothing.
   */
  public function testDeleteWithoutConfirmedPreviewsAndWritesNothing(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], []);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * With registrants attached, the op proceeds (no force gate), keeps the
   * registration, archives the instance, and notifies.
   */
  public function testDeletePublishedEventWithRegistrantsProceedsAndNotifies(): void {
    // Enable the notification key so the notifier enqueues.
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $series->event_instances->referencedEntities()[0];
    $this->registerUser($this->createUser(), $instance);
    // No force. confirmed only.
    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertGreaterThan(0, $data['notified']);
    // Registration kept.
    $this->assertGreaterThan(0, \Drupal::service('access_events.registrant_counter')->countForInstance((int) $instance->id()));
    // Instance archived.
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
    $this->assertSame('archived', $reloaded->get('moderation_state')->value);
  }

  /**
   * delete_event does not re-notify an instance already cancelled individually.
   *
   * The series save's state-reaction sweep (EventStateReactions::
   * sweepCancel()) only archives instances currently in the 'published'
   * state, so an instance already archived via cancelOccurrence (whose
   * registrant was already notified once, there) is not swept again here.
   * The sweep notifies exactly the instances it archives THIS save, NOT
   * every future instance of the series — notifying by series scope instead
   * would double-send to that already-notified registrant. This asserts the
   * queue only grows by the OTHER (newly-archived) instance's registrant,
   * and `notified` reflects only the newly-archived set.
   */
  public function testDeleteDoesNotRenotifyAlreadyCancelledInstance(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    // loadInstances() queries directly rather than reading the series
    // entity's event_instances computed field, which reflects only the
    // instances that existed when $series was last loaded.
    [$first, $second] = array_values($this->loadInstances($series));
    $this->registerUser($this->createUser(), $first);
    $this->registerUser($this->createUser(), $second);

    // Cancel the first instance individually — its registrant is notified now.
    $this->doOccurrence('cancel', (int) $first->id(), $newsPm, ['confirmed' => TRUE]);
    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $afterCancel = $queue->numberOfItems();
    $this->assertGreaterThan(0, $afterCancel);

    // delete_event archives the (still-published) second instance; the first
    // is already archived, so it must be skipped, not re-notified.
    $response = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    // Only the second instance was archived by this call.
    $this->assertSame(1, $data['instances_archived']);
    // Only the second instance's registrant was notified by this call.
    $this->assertSame(1, $data['notified']);
    // The queue grew by exactly one more item (the second instance's
    // registrant), not two.
    $this->assertSame($afterCancel + 1, $queue->numberOfItems());

    $reloadedFirst = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($first->id());
    $reloadedSecond = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($second->id());
    $this->assertSame('archived', $reloadedFirst->get('moderation_state')->value);
    $this->assertSame('archived', $reloadedSecond->get('moderation_state')->value);
  }

  /**
   * delete_event does not falsely notify an instance that was never archived.
   *
   * The state-reaction sweep only archives instances currently in the
   * 'published' state, so an instance left in 'draft' is skipped and stays
   * draft. The sweep notifies exactly the instances it archives THIS save,
   * NOT every future instance of the series — notifying by series scope
   * instead would falsely tell that never-archived instance's registrant
   * their (never-published, never-cancelled) event was cancelled.
   */
  public function testDeleteDoesNotNotifyInstanceNeverArchived(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($newsPm);
    $published = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $published);

    // A second instance on the same series, left in its default 'draft'
    // state — never published, never individually cancelled. published →
    // draft is not a legal editorial transition (a direct ->set()->save()
    // silently no-ops), so this instance is built already-draft rather than
    // demoted from published.
    $draft = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-02-01T10:00:00',
        'end_value' => '2999-02-01T12:00:00',
      ],
    ]);
    $draft->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($draft, (int) $series->id());
    $this->assertSame('draft', $draft->get('moderation_state')->value);
    $this->registerUserOnDraftInstance($this->createUser(), $draft);

    $response = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    // Only the published instance was archived and notified.
    $this->assertSame(1, $data['instances_archived']);
    $this->assertSame(1, $data['notified']);

    $reloadedPublished = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($published->id());
    $reloadedDraft = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($draft->id());
    $this->assertSame('archived', $reloadedPublished->get('moderation_state')->value);
    $this->assertSame('draft', $reloadedDraft->get('moderation_state')->value);
  }

  /**
   * A never-published draft series is hard-deleted.
   */
  public function testDeleteNeverPublishedDraftHardDeletes(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $sid = $series->id();
    $response = $this->doCrud('delete', (int) $sid, $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($sid));
  }

  /**
   * A draft-delete preview with registrants attached refuses, not warns.
   *
   * Under the delete-side registrant guard, a draft series with ANY
   * registrations (past or future — attendance history is protected data)
   * can never be hard-deleted, so the preview states the refusal-to-come
   * rather than the old "will be permanently removed" warning. The
   * registrant is created via registerUserOnDraftInstance() (entity-level,
   * bypassing the registrant-presave publish gate via a transient publish)
   * to model legacy data predating that gate.
   */
  public function testDeleteDraftPreviewWithRegistrantsRefusesInsteadOfWarning(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $instance = $series->event_instances->referencedEntities()[0];
    $this->registerUserOnDraftInstance($this->createUser(), $instance);

    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Blocked: the preview correctly reports the delete will NOT proceed.
    $this->assertFalse($data['would_hard_delete']);
    $this->assertSame(1, $data['registrants_affected']);
    $this->assertArrayNotHasKey('warning', $data);
    $this->assertArrayHasKey('refusal', $data);
    $this->assertStringContainsStringIgnoringCase('cannot be deleted', $data['refusal']);
    // The guard's own ALL-TIME count, surfaced alongside the future-scoped
    // registrants_affected so the two never have to be reconciled by the
    // caller — see EventCrudApiController::delete()'s $registrants docblock.
    $this->assertSame(1, $data['registrations_total']);
  }

  /**
   * A draft with ONLY a past registrant still refuses, with a coherent count.
   *
   * The registrants_affected field is future-scoped (countFutureForSeries)
   * and reports 0 here — nobody would be notified or is at risk of a
   * future-facing loss.
   * But EventDeleteGuard blocks on ANY registration, past included, so the
   * preview must still refuse. This is the case where registrants_affected
   * and the refusal would visually disagree without registrations_total
   * making the guard's own count explicit.
   */
  public function testDeleteDraftPreviewWithPastOnlyRegistrantRefusesCoherently(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([(int) $coordinator->id()]);
    $series = EventSeries::create([
      'title' => 'Past-Only Registrant Draft',
      'body' => 'A draft series whose only instance is already in the past.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
    ]);
    $series->save();
    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
    ]);
    $instance->save();
    $this->registerUserOnDraftInstance($this->createUser(), $instance);

    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['would_hard_delete']);
    // Future-scoped count is 0 — the past instance carries the only registrant.
    $this->assertSame(0, $data['registrants_affected']);
    $this->assertArrayHasKey('refusal', $data);
    $this->assertStringContainsStringIgnoringCase('cannot be deleted', $data['refusal']);
    // The all-time count makes the refusal coherent despite
    // registrants_affected reading 0.
    $this->assertSame(1, $data['registrations_total']);
  }

  /**
   * A confirmed draft-delete with registrants attached is refused (409).
   *
   * Nothing is deleted.
   */
  public function testDeleteDraftWithRegistrantsConfirmedRefuses409(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $instance = $series->event_instances->referencedEntities()[0];
    $registrant = $this->registerUserOnDraftInstance($this->createUser(), $instance);
    $sid = (int) $series->id();

    $response = $this->doCrud('delete', $sid, $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('registrations_exist', $data['error']);

    $etm = \Drupal::entityTypeManager();
    $this->assertNotNull($etm->getStorage('eventseries')->loadUnchanged($sid));
    $this->assertNotNull($etm->getStorage('eventinstance')->loadUnchanged($instance->id()));
    $this->assertNotNull($etm->getStorage('registrant')->loadUnchanged($registrant->id()));
  }

  /**
   * A draft-delete preview with NO registrants carries no warning.
   */
  public function testDeleteDraftPreviewWithNoRegistrantsHasNoWarning(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);

    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['would_hard_delete']);
    $this->assertSame(0, $data['registrants_affected']);
    $this->assertArrayNotHasKey('warning', $data);
  }

  /**
   * A plain author who owns the series lacks the `archive` transition.
   *
   * Entity access (userMayManageSeries('delete')) passes for the author (they
   * own the series), but the transition gate refuses — proving delete_event
   * checks the transition specifically, not just "may manage" entity access.
   */
  public function testAuthorMayNotDeleteOwnPublishedEventLacksArchiveTransition(): void {
    $author = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($author);
    $response = $this->doCrud('delete', (int) $series->id(), $author, [], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * An affinity_group_leader holds series publish but NOT archive.
   */
  public function testAgLeaderMayNotDeleteOwnPublishedEventLacksArchiveTransition(): void {
    $agLeader = $this->createUser([], NULL, FALSE, ['roles' => ['affinity_group_leader']]);
    $series = $this->makePublishedCoordinatorSeries($agLeader);
    $response = $this->doCrud('delete', (int) $series->id(), $agLeader, [], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A series published once but now needs_adjustment can't be archived — no 500.
   *
   * wasEverPublished() scans ALL revisions, so a series that was published and
   * then moved to needs_adjustment (a DEFAULT-revision unpublished state,
   * reached via request_adjustment) still enters the archive branch. But the
   * only legal archive transition is published → archived; there is no
   * needs_adjustment → archived transition, so validating it must NOT throw an
   * uncaught \InvalidArgumentException (HTTP 500). The op refuses cleanly
   * instead: the event is not in a state that can be archived.
   */
  public function testDeleteWasPublishedNowNeedsAdjustmentRefusesInsteadOf500(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    // Move the series to needs_adjustment (a default-revision state, so it
    // becomes the current state) while leaving a published revision behind, so
    // wasEverPublished() stays TRUE.
    $series->set('moderation_state', 'needs_adjustment')->save();

    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('needs_adjustment', $reloaded->get('moderation_state')->value);
  }

  /**
   * Re-deleting an already-archived series is idempotent — no 500.
   *
   * The first delete archives the published series. A second delete finds it
   * everPublished (TRUE) and enters the archive branch again, but archived →
   * archived is not a legal transition, so validating it must NOT throw an
   * uncaught \InvalidArgumentException (HTTP 500). The already-archived series is
   * effectively already soft-deleted, so the re-delete returns a clean success
   * no-op with the series still archived.
   */
  public function testDeleteAlreadyArchivedSeriesIsIdempotentNo500(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);

    $first = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $first->getStatusCode());
    $archived = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('archived', $archived->get('moderation_state')->value);

    $second = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $second->getStatusCode());
    $data = json_decode($second->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('archived', $reloaded->get('moderation_state')->value);
  }

  /**
   * news_pm holds `archive` on both workflows and may delete any published event.
   */
  public function testNewsPmCanDeleteAnyPublishedEvent(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $series = $this->makePublishedCoordinatorSeries($this->createUser());
    $series->set('field_affinity_group_node', [$group->id()])->save();
    $response = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
  }

}
