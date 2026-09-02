<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Covers POST /api/2.3/event-series/{eventseries}/restore — restore-event.
 *
 * Restore is delete's inverse: it un-archives a series and each of its
 * instances (archived → published) via the `archived_published` moderation
 * transition. That transition is DISTINCT from `publish` (whose from-states —
 * draft/published/ready_for_review — never include archived), so a user who may
 * create+publish a NEW event (affinity_group_leader) still cannot restore an
 * ARCHIVED one. Per the live config only news_pm/administrator hold
 * `archived_published` on either workflow. The op is gated by:
 *  - the coordinator/entity-access helper (userMayManageSeries('update'));
 *  - the `archived_published` moderation transition permission.
 *
 * Because `archived_published` only exists from the archived state, validating
 * it on a non-archived series would throw an uncaught \InvalidArgumentException
 * (HTTP 500). The endpoint guards on hasTransitionFromStateToState() first: a
 * draft/never-archived series refuses cleanly (invalid_state, 409) and an
 * already-published series is an idempotent success — never a 500.
 */
class EventCrudRestoreTest extends EventKernelTestBase {

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
    // site-level fields those hooks touch, mirroring EventCrudDeleteTest.
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
   * Restore republishes the series and each of its instances.
   *
   * Only news_pm holds `archive`/`archived_published`, so the fixture is
   * archived via news_pm (not the series' own coordinator/author), then
   * restored via news_pm.
   */
  public function testRestoreRepublishesSeriesAndEachInstance(): void {
    $coordinator = $this->createUser();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instanceIds = array_map(fn ($i) => $i->id(), $series->event_instances->referencedEntities());
    $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);

    $response = $this->doCrud('restore', (int) $series->id(), $newsPm, []);
    $this->assertSame(200, $response->getStatusCode());
    $reloadedSeries = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloadedSeries->get('moderation_state')->value);
    foreach ($instanceIds as $iid) {
      $inst = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($iid);
      $this->assertSame('published', $inst->get('moderation_state')->value);
    }
    $this->assertSame(count($instanceIds), json_decode($response->getContent(), TRUE)['instances_restored']);
  }

  /**
   * A user with no entity access to the series is refused (not_coordinator).
   */
  public function testRestoreRejectsNonCoordinator(): void {
    $coordinator = $this->createUser();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $response = $this->doCrud('restore', (int) $series->id(), $this->createUser(), []);
    $this->assertSame(409, $response->getStatusCode());
  }

  /**
   * An author who owns the series lacks the `archived_published` transition.
   *
   * Entity access (userMayManageSeries('update')) passes for the author, but
   * the transition gate refuses — the author holds neither `archive` nor
   * `archived_published`. The series is archived by news_pm first so the
   * fixture reaches 'archived'.
   */
  public function testAuthorCannotRestoreOwnEventLacksArchivedPublishedTransition(): void {
    $author = $this->createUser();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($author);
    $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $response = $this->doCrud('restore', (int) $series->id(), $author, []);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * An affinity_group_leader holds series `publish` but NOT `archived_published`.
   *
   * `publish` (draft/ready_for_review → published) and `archived_published`
   * (archived → published) are different transitions. An AG-leader who can
   * create+publish a NEW event still cannot restore an ARCHIVED one.
   */
  public function testAgLeaderCannotRestoreOwnEventLacksArchivedPublishedTransition(): void {
    $agLeader = $this->createUser([], NULL, FALSE, ['roles' => ['affinity_group_leader']]);
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($agLeader);
    $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $response = $this->doCrud('restore', (int) $series->id(), $agLeader, []);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * news_pm holds `archived_published` on both workflows and may restore.
   */
  public function testNewsPmCanRestoreAnyArchivedEvent(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $series = $this->makePublishedCoordinatorSeries($this->createUser());
    $series->set('field_affinity_group_node', [$group->id()])->save();
    $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $response = $this->doCrud('restore', (int) $series->id(), $newsPm, []);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Restoring a never-archived DRAFT series refuses cleanly — no 500.
   *
   * The `archived_published` transition only exists from the archived state, so
   * a draft series has no such transition. Validating it would throw an uncaught
   * \InvalidArgumentException (HTTP 500); the endpoint must guard first and
   * refuse with invalid_state (409) instead.
   */
  public function testRestoreDraftSeriesRefusesInvalidStateNo500(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($newsPm); // draft, never archived
    $response = $this->doCrud('restore', (int) $series->id(), $newsPm, []);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('draft', $reloaded->get('moderation_state')->value);
  }

  /**
   * Restoring an already-PUBLISHED series is an idempotent success — no 500.
   *
   * An already-published series has no archived_published transition FROM
   * published, so validating it would throw (HTTP 500). Restore treats an
   * already-published series as a no-op success reflecting reality: nothing to
   * restore, zero instances touched.
   */
  public function testRestoreAlreadyPublishedSeriesIsIdempotentNo500(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($newsPm);
    $response = $this->doCrud('restore', (int) $series->id(), $newsPm, []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame(0, $data['instances_restored']);
    // A no-op restore notifies no one — the envelope reports it explicitly,
    // symmetric with the delete no-op branch.
    $this->assertSame(0, $data['notified']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * Restoring a cancelled series with a future registrant re-notifies them.
   *
   * Mirrors delete's cancellation notify: a "this event is back on" notice
   * goes out to the registrants the restore brings back, under the distinct
   * event_reinstated_notification key.
   */
  public function testRestoreNotifiesReinstatedRegistrants(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_reinstated_notification.enabled', TRUE)
      ->set('notifications.event_reinstated_notification.subject', 'Event Reinstated: [eventinstance:title]')
      ->set('notifications.event_reinstated_notification.body', "The [eventinstance:title] [eventinstance:reg_type] is back on. Your registration has been kept.")
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($this->createUser());
    $instance = $series->event_instances->referencedEntities()[0];
    $this->registerUser($this->createUser(), $instance);
    // Cancel then restore.
    $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $response = $this->doCrud('restore', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertGreaterThan(0, json_decode($response->getContent(), TRUE)['notified']);
  }

  /**
   * The core cycle: a series cancel must not revive an individually-cancelled
   * occurrence.
   *
   * A coordinator cancels instance A on its own (cancelOccurrence), which
   * sets individually_cancelled on it (the cancellation-email reaction,
   * since that save is not part of a sweep). The series-level delete's sweep
   * (EventStateReactions::
   * sweepCancel()) then archives only the still-published instance B — A is
   * already archived, so the sweep's publishedNotPastInstances() query skips
   * it. Restoring the series must republish ONLY B; sweepRestore() skips A
   * because it is flagged individually_cancelled, so it stays archived —
   * restoring it would falsely tell A's registrant "this event is back on"
   * for an event they were separately told was off.
   */
  public function testRestoreAfterSeriesCancelLeavesIndividuallyCancelledInstanceArchived(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->set('notifications.event_reinstated_notification.enabled', TRUE)
      ->set('notifications.event_reinstated_notification.subject', 'Event Reinstated: [eventinstance:title]')
      ->set('notifications.event_reinstated_notification.body', "The [eventinstance:title] [eventinstance:reg_type] is back on. Your registration has been kept.")
      ->save();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    [$instanceA, $instanceB] = array_values($this->loadInstances($series));
    $this->registerUser($this->createUser(), $instanceA);
    $this->registerUser($this->createUser(), $instanceB);

    // Cancel A individually — its registrant is notified once, here.
    $this->doOccurrence('cancel', (int) $instanceA->id(), $newsPm, ['confirmed' => TRUE]);
    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $afterCancelA = $queue->numberOfItems();
    $this->assertGreaterThan(0, $afterCancelA);

    // Series-level delete archives only the still-published B (and enqueues
    // B's registrant a "cancelled" notice, growing the queue by one more).
    $deleteResponse = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $deleteResponse->getStatusCode());
    $deleteData = json_decode($deleteResponse->getContent(), TRUE);
    $this->assertSame(1, $deleteData['instances_archived']);
    $afterDelete = $queue->numberOfItems();
    $this->assertSame($afterCancelA + 1, $afterDelete);

    // Restore the series: only B (the only unflagged archived instance)
    // should republish. A must stay archived.
    $restoreResponse = $this->doCrud('restore', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $restoreResponse->getStatusCode());
    $restoreData = json_decode($restoreResponse->getContent(), TRUE);
    $this->assertSame(1, $restoreData['instances_restored']);
    // A's registrant is NOT re-notified "back on" — the queue only grows by
    // B's registrant "back on" notice, not by a second notice for A.
    $this->assertSame($afterDelete + 1, $queue->numberOfItems());

    $reloadedA = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instanceA->id());
    $reloadedB = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instanceB->id());
    $this->assertSame('archived', $reloadedA->get('moderation_state')->value);
    $this->assertSame('published', $reloadedB->get('moderation_state')->value);
  }

  /**
   * Empty-set: every occurrence was individually cancelled before the series
   * cancel, so the sweep finds nothing published to archive and every
   * instance is flagged individually_cancelled. Restore must republish the
   * series and ZERO instances — sweepRestore() skips every one of them on
   * the flag.
   */
  public function testRestoreWithAllInstancesIndividuallyCancelledRepublishesZeroInstances(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    $instances = array_values($this->loadInstances($series));

    // Cancel every instance individually before the series is ever archived.
    foreach ($instances as $instance) {
      $this->doOccurrence('cancel', (int) $instance->id(), $newsPm, ['confirmed' => TRUE]);
    }

    // The series-level delete's sweep finds no published instances left to
    // archive.
    $deleteResponse = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $deleteResponse->getStatusCode());
    $deleteData = json_decode($deleteResponse->getContent(), TRUE);
    $this->assertSame(0, $deleteData['instances_archived']);

    $restoreResponse = $this->doCrud('restore', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $restoreResponse->getStatusCode());
    $restoreData = json_decode($restoreResponse->getContent(), TRUE);
    $this->assertSame(0, $restoreData['instances_restored']);

    $reloadedSeries = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloadedSeries->get('moderation_state')->value);
    foreach ($instances as $instance) {
      $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
      $this->assertSame('archived', $reloaded->get('moderation_state')->value);
    }
  }

  /**
   * Legacy/migrated data: a series whose instances were archived by a
   * SYNCING save (e.g. a migration or a pre-feature import that bypassed the
   * normal cancel path — a normal, non-sweep save would flag each instance
   * individually_cancelled) restores every archived instance.
   *
   * The archived/unflagged state on each instance IS the authority (see
   * EventStateReactions::sweepRestore()). A syncing save skips
   * instancePresave()'s cancellation-email reaction flag write entirely, so
   * these instances are archived but NOT individually_cancelled,
   * modeling data that predates (or bypassed) the individually_cancelled
   * flag mechanism.
   */
  public function testRestoreOfSyncingArchivedInstancesRestoresEveryUnflaggedInstance(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    $instances = array_values($this->loadInstances($series));

    // Archive each instance via a syncing save — bypasses instancePresave()'s
    // individually_cancelled flag write, modeling legacy/migrated data.
    foreach ($instances as $instance) {
      $instance->setSyncing(TRUE);
      $instance->set('moderation_state', 'archived')->save();
    }
    $series->setSyncing(TRUE);
    $series->set('moderation_state', 'archived')->save();

    $restoreResponse = $this->doCrud('restore', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $restoreResponse->getStatusCode());
    $restoreData = json_decode($restoreResponse->getContent(), TRUE);
    $this->assertSame(count($instances), $restoreData['instances_restored']);

    foreach ($instances as $instance) {
      $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
      $this->assertSame('published', $reloaded->get('moderation_state')->value);
    }
  }

  /**
   * Re-deleting an already-archived series must not disturb which instances
   * a later restore brings back.
   *
   * The individually_cancelled flag set on A when it was cancelled on its
   * own (before the series-level delete) is the persistent authority
   * sweepRestore() reads — not a per-request memory a redundant call could
   * clobber. The idempotent no-op branch in delete() (already-archived)
   * returns before the state-reaction save runs at all, so a re-delete does
   * not touch any instance's flag or state. A restore after the redundant
   * re-delete must still restore only B — A stays archived because it is
   * still individually_cancelled, not because of anything the re-delete did
   * or didn't record.
   */
  public function testRedeletingArchivedSeriesDoesNotDisturbRestoreScope(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    [$instanceA, $instanceB] = array_values($this->loadInstances($series));

    // Cancel A individually first, so the series-level delete only archives B.
    $this->doOccurrence('cancel', (int) $instanceA->id(), $newsPm, ['confirmed' => TRUE]);
    $first = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $first->getStatusCode());
    $this->assertSame(1, json_decode($first->getContent(), TRUE)['instances_archived']);

    // Re-delete: the series is already archived, so this is the idempotent
    // no-op branch — it must not touch either instance's individually_
    // cancelled flag.
    $second = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $second->getStatusCode());

    $restoreResponse = $this->doCrud('restore', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $restoreResponse->getStatusCode());
    $restoreData = json_decode($restoreResponse->getContent(), TRUE);
    // B (never individually cancelled) still restores correctly after the
    // no-op re-delete — not zero instances, which is what a flag wrongly
    // cleared or set by the re-delete would give.
    $this->assertSame(1, $restoreData['instances_restored']);

    $reloadedA = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instanceA->id());
    $reloadedB = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instanceB->id());
    $this->assertSame('archived', $reloadedA->get('moderation_state')->value);
    $this->assertSame('published', $reloadedB->get('moderation_state')->value);
  }

  /**
   * Archiving via a series cancel leaves a revision-log breadcrumb.
   *
   * The archived/unflagged state on each instance is the machine authority
   * for what a restore should republish (see EventStateReactions::
   * sweepRestore()); this log message is a breadcrumb for a human debugging
   * "why did this instance stay cancelled after a series restore" at
   * /events/{id}/revisions.
   */
  public function testSeriesDeleteLeavesRevisionLogBreadcrumbOnArchivedInstance(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($newsPm);
    $instance = array_values($this->loadInstances($series))[0];

    $response = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());

    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $reloaded = $storage->loadUnchanged($instance->id());
    $latestRevision = $storage->loadRevision($reloaded->getLoadedRevisionId());
    $this->assertStringContainsString('Archived by series cancel', $latestRevision->getRevisionLogMessage() ?? '');
  }

}
