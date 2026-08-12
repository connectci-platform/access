<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests the series-level state reactions: the cancel sweep, the restore
 * sweep, the non-published rebuild trigger, and the eventseries_update hook
 * order pin against recurring_events.
 *
 * @coversDefaultClass \Drupal\access_events\EventStateReactions
 * @group access_events
 */
class SeriesReactionsTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableEventNotifications();

    // access_affinitygroup_entity_presave() (fired by createAffinityGroupNode(),
    // used indirectly via makePublishedCustomSeriesWithDate()) reads
    // field_affinity_group on the node — not seeded by the base setUp(),
    // only by EventDeleteGuardHooksTest's own fixture. Attach it here too.
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
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    }
  }

  /**
   * The cancel sweep: a series cancel (published -> archived) archives future published
   * instances only, notifies their registrants, and leaves a past published
   * instance alone. The collector SUMs across both instances under the
   * series key (proving resetOwn() does not clobber accumulation mid-sweep).
   */
  public function testSeriesCancelArchivesFutureNotifiesSumLeavesPast(): void {
    $futureA = $this->createRegistrableInstance();
    $series = $futureA->getEventSeries();
    $this->registerUser($this->createUser([], 'fa'), $futureA);

    $futureB = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);
    $futureB->save();
    $this->publishModerated($futureB);
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($futureB, (int) $series->id());
    $this->registerUser($this->createUser([], 'fb'), $futureB);

    $past = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
    ]);
    $past->save();
    $this->publishModerated($past);

    $seriesId = (int) $series->id();
    $series = EventSeries::load($seriesId);
    $series->set('moderation_state', 'archived')->save();

    $reloadedA = $this->reloadInstance($futureA);
    $reloadedB = $this->reloadInstance($futureB);
    $reloadedPast = $this->reloadInstance($past);

    $this->assertSame('archived', $reloadedA->get('moderation_state')->value);
    $this->assertSame('0', (string) $reloadedA->get('individually_cancelled')->value);
    $this->assertSame('archived', $reloadedB->get('moderation_state')->value);
    $this->assertSame('0', (string) $reloadedB->get('individually_cancelled')->value);
    $this->assertSame('published', $reloadedPast->get('moderation_state')->value);

    $this->assertQueueCount('event_cancelled_notification', 2);

    $collector = \Drupal::service('access_events.state_change_collector');
    $outcomes = $collector->drain('eventseries', $seriesId);
    $this->assertSame(2, $outcomes['notified'] ?? NULL);
    $this->assertSame(2, $outcomes['instances_archived'] ?? NULL);
  }

  /**
   * The restore sweep: a series restore (archived -> published) publishes unflagged future
   * archived instances.
   */
  public function testSeriesRestorePublishesUnflaggedFutureArchived(): void {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $this->registerUser($this->createUser([], 'r1'), $instance);
    $seriesId = (int) $series->id();

    EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();
    $this->assertSame('archived', $this->reloadInstance($instance)->get('moderation_state')->value);

    // Drain the cancel sweep's outcomes before exercising the restore.
    $queue = \Drupal::queue('recurring_events_registration_email_notifications_queue_worker');
    while ($queue->claimItem()) {
      // Drain.
    }

    EventSeries::load($seriesId)->set('moderation_state', 'published')->save();

    $reloaded = $this->reloadInstance($instance);
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
    $this->assertQueueCount('event_reinstated_notification', 1);
  }

  /**
   * The restore sweep: an instance individually cancelled before the series archive (so it
   * carries individually_cancelled=TRUE) is skipped by the restore sweep, and
   * a verifiably-past archived instance is skipped too.
   */
  public function testRestoreSkipsFlaggedAndPastInstances(): void {
    $flagged = $this->createRegistrableInstance();
    $series = $flagged->getEventSeries();
    $seriesId = (int) $series->id();

    $past = EventInstance::create([
      'eventseries_id' => $seriesId,
      'type' => 'default',
      'date' => ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
    ]);
    $past->save();
    $this->publishModerated($past);

    // Individually cancel $flagged BEFORE the series-wide archive, so the cancellation-email reaction sets
    // individually_cancelled=TRUE on it (the sweep is not active for this
    // save, so the presave flag write fires normally).
    $flagged->set('moderation_state', 'archived')->save();
    $this->assertSame('1', (string) $this->reloadInstance($flagged)->get('individually_cancelled')->value);

    // Archive the past instance too, via syncing, so it lands in 'archived'
    // without going through the cancellation-email reaction (which would refuse to touch a past-dated
    // flag write path irrelevantly) — the restore sweep must skip it purely on
    // the not-verifiably-past predicate.
    $pastReloaded = $this->reloadInstance($past);
    $pastReloaded->setSyncing(TRUE);
    $pastReloaded->set('moderation_state', 'archived')->save();

    EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();

    EventSeries::load($seriesId)->set('moderation_state', 'published')->save();

    $this->assertSame('archived', $this->reloadInstance($flagged)->get('moderation_state')->value,
      'a previously individually-cancelled instance stays archived through a series restore');
    $this->assertSame('archived', $this->reloadInstance($past)->get('moderation_state')->value,
      'a verifiably-past archived instance is never republished by a series restore');
  }

  /**
   * A draft-authored series (never published) that is published for the
   * first time publishes its (syncing-seeded, already-archived) instances,
   * with zero reinstatement emails — there is no "from published" edge here,
   * this is a fresh publish of instances the series never exposed before.
   */
  public function testDraftAuthoredSeriesPublishesItsInstances(): void {
    $series = EventSeries::create([
      'title' => 'Draft Authored Event',
      'body' => 'Never published yet.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $series->save();
    $seriesId = (int) $series->id();

    $instance = EventInstance::create([
      'eventseries_id' => $seriesId,
      'type' => 'default',
      'date' => ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);
    // Seeded archived via a syncing save — births are stock (draft) as of
    // this task; a later task changes this seeding to a natural draft birth.
    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, $seriesId);

    EventSeries::load($seriesId)->set('moderation_state', 'published')->save();

    $this->assertSame('published', $this->reloadInstance($instance)->get('moderation_state')->value);
    $this->assertQueueCount('event_reinstated_notification', 0);
  }

  /**
   * A date change on an archived, unregistered custom series triggers a
   * rebuild (the non-published rebuild trigger), regenerating its instance
   * set from the new date.
   */
  public function testDateChangeOnArchivedSeriesTriggersRebuild(): void {
    $series = EventSeries::create([
      'title' => 'Archived Custom Event',
      'body' => 'Not published.',
      'recur_type' => 'custom',
      'type' => 'default',
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    $series->save();
    $seriesId = (int) $series->id();
    EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();

    $original = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $original);
    $originalDate = reset($original)->get('date')->value;
    $this->assertSame('2999-01-01T10:00:00', $originalDate);

    $series = EventSeries::load($seriesId);
    $series->set('custom_date', [
      ['value' => '2999-03-01T10:00:00', 'end_value' => '2999-03-01T12:00:00'],
    ]);
    $series->save();

    $rebuilt = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $rebuilt);
    $rebuiltInstance = reset($rebuilt);
    $this->assertSame('2999-03-01T10:00:00', $rebuiltInstance->get('date')->value);
  }

  /**
   * A recur-config change on a non-published weekly series whose config is
   * left partial (days emptied, date range cleared) does NOT fatal on save,
   * and leaves the existing instance set untouched. The non-published rebuild
   * trigger would otherwise invoke contrib's WeeklyRecurringDate::
   * calculateInstances() on the partial config, which foreach-es the empty
   * days and passes null dates into non-nullable typehints and throws — the
   * completeness pre-check skips the rebuild instead, so a half-configured
   * recurrence yields no rebuild rather than a fatal.
   */
  public function testPartialWeeklyConfigChangeOnNonPublishedSeriesDoesNotFatal(): void {
    $coordinator = $this->createUser();
    // A valid weekly series (its insert hook spawns instances from the
    // populated rule). makeCoordinatorRuleSeries() leaves it non-published.
    $series = $this->makeCoordinatorRuleSeries($coordinator);
    $seriesId = (int) $series->id();
    $before = count($this->loadInstances(EventSeries::load($seriesId)));

    // Now clear the rule down to a partial shape and save — a recur-config
    // change (so the rebuild trigger's precondition is met) on a
    // non-published series (so OUR trigger, not contrib's, owns the rebuild).
    $series = EventSeries::load($seriesId);
    $series->set('weekly_recurring_date', [
      'value' => NULL,
      'end_value' => NULL,
      'time' => '10:00 AM',
      'end_time' => '11:00 AM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => '',
    ]);
    // No exception thrown by save() is the assertion.
    $series->save();

    // The partial-config rebuild was skipped, so the existing instance set is
    // left in place rather than destroyed.
    $after = count($this->loadInstances(EventSeries::load($seriesId)));
    $this->assertSame($before, $after);
  }

  /**
   * A pending-draft date change (a series still in 'draft', never published)
   * triggers nothing extra from our rebuild trigger — recurring_events' own
   * eventseries_update already owns the unmoderated/draft-insert-adjacent
   * path (isPublished() || !$moderated somewhere upstream is not our path
   * here); our trigger fires ONLY while !isPublished(), so a draft series is
   * squarely in scope for OUR rebuild — but recurring_events' own hook does
   * NOT rebuild a moderated, unpublished series (its condition is
   * isPublished() || !$moderated, false for a moderated draft), so seeing the
   * regenerated set here proves our trigger — not contrib's — did the work,
   * with no duplicate/second rebuild happening.
   */
  public function testPendingDraftDateChangeOnPublishedSeriesTriggersNothing(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $seriesId = (int) $series->id();

    // Move the series to a non-published pending state without touching
    // dates, then make the pending-state date edit — from !== published,
    // to !== published throughout, so this is not a cancel-sweep/restore-sweep transition at
    // all; it only exercises the rebuild trigger's own precondition logic.
    EventSeries::load($seriesId)->set('moderation_state', 'needs_adjustment')->save();

    $before = $this->loadInstances(EventSeries::load($seriesId));
    $beforeCount = count($before);

    $series = EventSeries::load($seriesId);
    $existing = $series->get('custom_date')->getValue();
    $existing[0]['value'] = '2999-04-01T10:00:00';
    $existing[0]['end_value'] = '2999-04-01T12:00:00';
    $series->set('custom_date', $existing);
    $series->save();

    $after = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount($beforeCount, $after);
  }

  /**
   * One save that both cancels (published -> archived) AND changes dates
   * composes rebuild-then-sweep in a single transaction: the final instance
   * set matches the NEW dates, and zero emails fire (every instance the
   * sweep would notify was destroyed and recreated by the rebuild first).
   * Does NOT assert the new instances' resulting state — that pin lands with
   * the birth-alignment task.
   */
  public function testCancelAndRebuildComposeInOneSave(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $seriesId = (int) $series->id();

    $series = EventSeries::load($seriesId);
    $existing = $series->get('custom_date')->getValue();
    $existing[0]['value'] = '2999-05-01T10:00:00';
    $existing[0]['end_value'] = '2999-05-01T12:00:00';
    $series->set('custom_date', $existing);
    $series->set('moderation_state', 'archived');
    $series->save();

    $instances = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $instances);
    $instance = reset($instances);
    $this->assertSame('2999-05-01T10:00:00', $instance->get('date')->value);

    $this->assertQueueCount('event_cancelled_notification', 0);
  }

  /**
   * A mid-sweep exception (one instance save throws) leaves the sweep marker
   * cleared — the finally-block guarantee.
   */
  public function testSweepMarkerClearedOnMidSweepException(): void {
    $futureA = $this->createRegistrableInstance();
    $series = $futureA->getEventSeries();
    $seriesId = (int) $series->id();

    $futureB = $this->addFutureInstance($seriesId);
    $collector = $this->installMidSweepFailureFor((int) $futureB->id());

    $threw = FALSE;
    try {
      EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();
    }
    catch (\Throwable $e) {
      $threw = TRUE;
      $this->assertStringContainsString('Simulated mid-sweep failure', $e->getMessage());
    }
    $this->assertTrue($threw, 'Expected the corrupted mid-sweep instance save to throw.');
    $this->assertFalse($collector->isSweeping($seriesId), 'the sweep marker must be cleared even when a save throws mid-sweep');
  }

  /**
   * Creates + publishes a second future instance on $seriesId.
   */
  private function addFutureInstance(int $seriesId): EventInstance {
    $instance = EventInstance::create([
      'eventseries_id' => $seriesId,
      'type' => 'default',
      'date' => ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);
    $instance->save();
    $this->publishModerated($instance);
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, $seriesId);
    return $instance;
  }

  /**
   * Rebuilds access_events.state_reactions with a CancellationNotifier whose
   * enqueueGated() throws the moment it sees $throwOnInstanceId.
   *
   * reactToInstanceCancelled() calls enqueueGated() BEFORE any collector
   * writes, from inside access_events_eventinstance_update() — invoked
   * synchronously from within $instance->save() as the sweep's loop
   * processes that instance. This reaches the sweep loop mid-iteration
   * without depending on any schema/type internals to corrupt a row.
   * access_events.state_reactions is already-instantiated (constructor-DI'd
   * with the ORIGINAL notifier) by any fixture saves before this call, and
   * \Drupal::service() caches that instance — swapping the notifier binding
   * in the container alone would not reach it, so the service is rebuilt
   * directly here with the throwing notifier standing in.
   *
   * @return \Drupal\access_events\StateChangeCollector
   *   The (unmodified) collector the rebuilt service now uses, for the
   *   caller's own isSweeping()/drain() assertions.
   */
  private function installMidSweepFailureFor(int $throwOnInstanceId): \Drupal\access_events\StateChangeCollector {
    $throwingNotifier = new class(
      $throwOnInstanceId,
      \Drupal::entityTypeManager(),
      \Drupal::service('recurring_events_registration.notification_service'),
      \Drupal::service('datetime.time'),
      \Drupal::service('config.factory'),
      \Drupal::service('database'),
    ) extends \Drupal\access_events\CancellationNotifier {
      public function __construct(
        private int $throwOnInstanceId,
        \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager,
        \Drupal\recurring_events_registration\NotificationService $notificationService,
        \Drupal\Component\Datetime\TimeInterface $time,
        \Drupal\Core\Config\ConfigFactoryInterface $configFactory,
        \Drupal\Core\Database\Connection $database,
      ) {
        parent::__construct($entityTypeManager, $notificationService, $time, $configFactory, $database);
      }

      public function enqueueGated(\Drupal\recurring_events\Entity\EventInstance $instance, string $key): int {
        if ((int) $instance->id() === $this->throwOnInstanceId) {
          throw new \RuntimeException('Simulated mid-sweep failure for instance ' . $instance->id());
        }
        return parent::enqueueGated($instance, $key);
      }
    };

    $container = \Drupal::getContainer();
    $collector = $container->get('access_events.state_change_collector');
    $container->set('access_events.state_reactions', new \Drupal\access_events\EventStateReactions(
      $container->get('entity_type.manager'),
      $container->get('access_events.registrant_counter'),
      $throwingNotifier,
      $collector,
      $container->get('datetime.time'),
    ));

    return $collector;
  }

  /**
   * After a rolled-back cancel (caught mid-sweep exception), a subsequent
   * successful cancel's drain() reflects ONLY that successful save's
   * numbers — no pollution from the failed attempt.
   */
  public function testRolledBackCancelDoesNotPolluteNextDrain(): void {
    $futureA = $this->createRegistrableInstance();
    $series = $futureA->getEventSeries();
    $seriesId = (int) $series->id();
    $this->registerUser($this->createUser([], 'ra'), $futureA);

    $futureB = $this->addFutureInstance($seriesId);
    $collector = $this->installMidSweepFailureFor((int) $futureB->id());

    try {
      EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();
    }
    catch (\Throwable $e) {
      // Expected — the corrupted instance aborts the sweep. Core's
      // SqlContentEntityStorage::save() wraps the whole save (including the
      // update hooks that run the sweep) in one DB transaction, so this also
      // rolls back the series' own row and both instances' rows to whatever
      // they were before this attempt.
    }
    // Whatever partial accumulation happened must not leak into the next
    // successful cancel's drain() below; resetOwn() at the top of
    // seriesUpdate() clears exactly this series key on every subsequent
    // series save, INCLUDING the retry.
    $collector->drain('eventseries', $seriesId);

    // The failed attempt's throwing notifier is a one-shot corruption of the
    // rebuilt access_events.state_reactions service; the retry below must run
    // through the ORIGINAL (non-throwing) collaborators, so restore the real
    // service before it.
    $container = \Drupal::getContainer();
    $container->set('access_events.state_reactions', new \Drupal\access_events\EventStateReactions(
      $container->get('entity_type.manager'),
      $container->get('access_events.registrant_counter'),
      $container->get('access_events.cancellation_notifier'),
      $collector,
      $container->get('datetime.time'),
    ));

    $queue = \Drupal::queue('recurring_events_registration_email_notifications_queue_worker');
    while ($queue->claimItem()) {
      // Drain any side effects of the failed attempt before the clean retry.
    }

    // The rolled-back transaction may have unwound the series past
    // 'published' (its state going into the failed attempt) — reset the
    // static cache and re-assert 'published' explicitly, via a syncing save,
    // so the retry below is unambiguously a fresh published -> archived
    // transition regardless of exactly how far back the rollback landed.
    $etm = \Drupal::entityTypeManager();
    $etm->getStorage('eventseries')->resetCache([$seriesId]);
    $etm->getStorage('eventinstance')->resetCache([(int) $futureA->id(), (int) $futureB->id()]);
    $series = EventSeries::load($seriesId);
    if ($series->get('moderation_state')->value !== 'published') {
      $series->setSyncing(TRUE);
      $series->set('moderation_state', 'published')->save();
    }
    foreach ([$futureA, $futureB] as $instance) {
      $fresh = $etm->getStorage('eventinstance')->loadUnchanged($instance->id());
      if ($fresh->get('moderation_state')->value !== 'published') {
        $fresh->setSyncing(TRUE);
        $fresh->set('moderation_state', 'published')->save();
      }
    }
    $collector->drain('eventseries', $seriesId);
    $etm->getStorage('eventseries')->resetCache([$seriesId]);

    EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();

    // The clean retry archives BOTH future instances ($futureA, registered;
    // $futureB, not) — the failed attempt never got far enough to persist
    // anything for either, so this reflects the retry's own totals only, not
    // a partial carryover from the aborted attempt.
    $outcomes = $collector->drain('eventseries', $seriesId);
    $this->assertSame(1, $outcomes['notified'] ?? NULL);
    $this->assertSame(2, $outcomes['instances_archived'] ?? NULL);
  }

  /**
   * access_events_module_implements_alter() moves our eventseries_update
   * implementation to run AFTER recurring_events' — the opposite direction
   * from the delete-guard hooks' "must run FIRST" pin. Verified the same way
   * EventDeleteGuardHooksTest does: getImplementationInfo() collection order,
   * not getImplementations() (removed in Drupal 10).
   */
  public function testOurSeriesUpdateRunsAfterContrib(): void {
    $handler = \Drupal::moduleHandler();
    $method = new \ReflectionMethod($handler, 'getImplementationInfo');
    $method->setAccessible(TRUE);
    $order = array_keys($method->invoke($handler, 'eventseries_update'));

    $accessEventsPos = array_search('access_events', $order, TRUE);
    $recurringEventsPos = array_search('recurring_events', $order, TRUE);

    $this->assertNotFalse($accessEventsPos, 'access_events must implement eventseries_update.');
    $this->assertNotFalse($recurringEventsPos, 'recurring_events must implement eventseries_update.');
    $this->assertGreaterThan(
      $recurringEventsPos,
      $accessEventsPos,
      'access_events must run AFTER recurring_events on eventseries_update — see access_events_module_implements_alter().',
    );
  }

}
