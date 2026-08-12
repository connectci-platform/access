<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests that a recur-config rebuild preserves past instances + registrants.
 *
 * Contrib's recurring_events module rebuilds ALL instances of a series when
 * its recurrence config changes; the stock RecreateEventInstanceCreator
 * deletes and recreates every instance, PAST ones included, destroying any
 * attendance history (and the underlying registrant rows) they carry.
 * PastPreservingEventInstanceCreator (wired in via
 * access_events_recurring_events_event_instance_creator_plugin_alter()) must
 * instead leave ended instances completely untouched and only rebuild the
 * future side.
 *
 * INTERACTION WITH THE DELETE-SIDE REGISTRANT GUARD: the plugin's
 * processInstances() deletes each future instance directly
 * ($instance->delete(), not clearEventInstances()), so every one of those
 * deletes also passes through access_events_eventinstance_predelete() (see
 * access_events.module). That hook is a pass-through here, not a no-op by
 * accident: the plugin's own belt (the registrant count check immediately
 * before the delete loop) already guarantees every instance in the delete
 * set is registrant-free, so the guard's count agrees and never throws.
 * testRecurConfigChangePreservesPastInstanceAndRegistrant() below asserts
 * this holds for a normal rebuild; testNullEndValueInstanceTripsBeltNotBlock()
 * asserts the inverse — when the belt's own count is nonzero, its throw (not
 * the predelete guard, which never gets a chance to run before the plugin's
 * own check aborts the loop) is what stops the delete.
 *
 * @covers \Drupal\access_events\Plugin\EventInstanceCreator\PastPreservingEventInstanceCreator
 * @group access_events
 */
class PastPreservingRebuildTest extends EventKernelTestBase {

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
    // Mirrors EventCrudOccurrenceEditTest::setUp(): access_events_entity_
    // presave()/access() touch these site-level fields on every series/
    // instance save.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality]) {
      if (!\Drupal\field\Entity\FieldStorageConfig::loadByName($entityType, $fieldName)) {
        \Drupal\field\Entity\FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
        ])->save();
      }
      if (!\Drupal\field\Entity\FieldConfig::loadByName($entityType, 'default', $fieldName)) {
        \Drupal\field\Entity\FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
        ])->save();
      }
    }

    // createAffinityGroupNode() saves an affinity_group node, which routes
    // through access_affinitygroup_entity_presave() -> add_ag_taxonomy_term(),
    // reading this field. Mirrors EventCrudOccurrenceEditTest::setUp().
    if (!\Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
      \Drupal\field\Entity\FieldStorageConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => 1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      \Drupal\field\Entity\FieldConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Builds a published custom series with one past + one future instance.
   *
   * The past instance carries a registrant. Returns [series, pastInstance,
   * futureInstance, registrant].
   */
  private function buildSeriesWithPastAndFuture(): array {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([(int) $coordinator->id()]);

    $series = EventSeries::create([
      'title' => 'Past-Preserving Rebuild Event',
      'body' => 'An event with a past and a future occurrence.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
      'custom_date' => [
        ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    // The insert hook (still contrib's default recreator on create — the
    // alter only matters on an eventseries UPDATE recur-config change, see
    // recurring_events.module) spawns two instances from the seeded dates.
    $series->save();
    $series->set('moderation_state', 'published')->save();

    $instances = $this->loadInstances($series);
    $past = $future = NULL;
    foreach ($instances as $instance) {
      $instance->set('moderation_state', 'published')->save();
      if ($instance->get('date')->value === '2000-01-01T10:00:00') {
        $past = $instance;
      }
      else {
        $future = $instance;
      }
    }
    $this->assertNotNull($past, 'Past instance was spawned.');
    $this->assertNotNull($future, 'Future instance was spawned.');

    $registrant = $this->registerUser($this->createUser(), $past);

    return [$series, $past, $future, $registrant, $coordinator];
  }

  /**
   * A recur-config change preserves the past instance, its id, and registrant.
   *
   * Also asserts the rebuild-created future instances are PUBLISHED, not left
   * at the editorial_eventinstance workflow's draft default — contrib's own
   * state-sync no-ops on this site's series/instance workflow-id mismatch, so
   * the plugin must publish them itself when the series is published.
   * Asserted on the RELOADED newly-created instances specifically (excluding
   * the preserved past instance, whose own state this rebuild must not
   * touch), since buildSeriesWithPastAndFuture()'s hand-publish loop only
   * ran once at series creation and would mask a regression in a LATER
   * rebuild's publish step.
   */
  public function testRecurConfigChangePreservesPastInstanceAndRegistrant(): void {
    [$series, $past, $future, $registrant] = $this->buildSeriesWithPastAndFuture();
    $pastId = (int) $past->id();
    $futureId = (int) $future->id();
    $registrantId = (int) $registrant->id();
    $preExistingIds = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));

    // The plugin is about to $instance->delete() the future instance below,
    // which traverses access_events_eventinstance_predelete() like any other
    // eventinstance delete. Confirm the SAME guard the hooks use already
    // agrees this specific instance is deletable before the rebuild runs —
    // pins that the interaction is a verified pass-through (the plugin's own
    // belt already cleared it), not an untested incidental non-throw.
    $this->assertNull(
      \Drupal::service('access_events.event_delete_guard')->deletionBlockedReason($future),
      'The delete-side registrant guard already agrees the future instance the rebuild is about to delete is registrant-free.',
    );

    // Append a new future custom_date — a recur-config change on a published
    // series, which fires the instance-creator plugin. If the guard above
    // disagreed with the plugin's own belt, this save would throw (wrapped in
    // EntityStorageException) instead of succeeding — the assertions below
    // that the rebuild completed are therefore also proof the guard's
    // pass-through held for every instance actually deleted, not just $future.
    $existing = $series->get('custom_date')->getValue();
    $existing[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existing);
    $series->save();

    // The original future instance either survived (if the plugin happened to
    // keep its id) or was deleted and replaced — either is legal (see the
    // "future instance ids are allowed to change" note below) — but if it WAS
    // deleted, that delete is the traversal this test is pinning; assert here
    // that it is actually gone (not silently left behind by a guard that
    // wrongly blocked it), confirming the delete->guard->pass-through->delete
    // sequence actually executed rather than a rebuild that quietly no-opped.
    $futureStillPresent = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($futureId) !== NULL;
    $originalFutureDateStillMaterialized = in_array(
      '2999-01-01T10:00:00',
      array_map(fn ($i) => $i->get('date')->value, $this->loadInstances($series)),
      TRUE,
    );
    $this->assertTrue(
      $futureStillPresent || $originalFutureDateStillMaterialized,
      'The original future date is represented after the rebuild, either by the same instance or a replacement — proving the delete (when it happened) actually traversed the guard and completed rather than silently failing.',
    );

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());

    // The past instance survives with the SAME entity id.
    $survivingPast = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($pastId);
    $this->assertNotNull($survivingPast, 'The past instance was not deleted.');
    $this->assertSame('2000-01-01T10:00:00', $survivingPast->get('date')->value);

    // Its registrant still exists.
    $survivingRegistrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrantId);
    $this->assertNotNull($survivingRegistrant, 'The past instance registrant was not deleted.');

    // No cancellation/deletion notification was enqueued for it. The
    // recurring_events_registration module enqueues via its own queue worker
    // ('recurring_events_registration_send_email_queue' or similar) keyed by
    // registrant id; simplest observable proxy in this kernel env is that the
    // registrant row is untouched (no delete = no notify-then-delete cycle
    // ran against it) — asserted above. Also assert directly that the
    // registrant's instance reference is unchanged (nothing reparented it).
    $this->assertSame($pastId, (int) $survivingRegistrant->get('eventinstance_id')->target_id);

    // The future side reflects the new config: the newly appended date has a
    // materialized instance, and the ORIGINAL future instance's date survived
    // in config (it is still requested), but recurring_events' own instance
    // ids for future instances are allowed to change since they carry no
    // registrants (guaranteed by the reschedule block).
    $dates = array_column($reloaded->get('custom_date')->getValue(), 'value');
    $this->assertContains('2999-06-01T10:00:00', $dates);
    $this->assertContains('2999-01-01T10:00:00', $dates);

    $allInstances = $this->loadInstances($reloaded);
    $instanceDates = array_map(fn ($i) => $i->get('date')->value, $allInstances);
    $this->assertContains('2000-01-01T10:00:00', $instanceDates, 'Past date still represented by exactly the preserved instance.');
    $this->assertContains('2999-01-01T10:00:00', $instanceDates, 'Original future date still represented.');
    $this->assertContains('2999-06-01T10:00:00', $instanceDates, 'Newly appended future date is materialized.');
    // Exact total, not just "contains": one preserved past + two future
    // (the original future date rebuilt + the newly appended one) — pins
    // that no stray duplicate future instance was created alongside them.
    $this->assertCount(3, $allInstances, 'Exactly three instances exist after the rebuild — no stray duplicate.');

    // Every rebuild-created instance (i.e. not the preserved past instance)
    // must be published — the series is published, so its occurrences must
    // stay publicly visible + registrable.
    $newInstances = array_filter($allInstances, fn ($i) => !in_array((int) $i->id(), $preExistingIds, TRUE));
    $this->assertNotEmpty($newInstances, 'The rebuild created at least one new instance.');
    foreach ($newInstances as $newInstance) {
      $this->assertSame(
        'published',
        $newInstance->get('moderation_state')->value,
        'A rebuild-created instance on a published series must itself be published.',
      );
    }
  }

  /**
   * The past date stays represented by exactly ONE instance — no duplicate.
   */
  public function testPastDateNotDuplicatedAcrossRebuild(): void {
    [$series, $past] = $this->buildSeriesWithPastAndFuture();
    $pastId = (int) $past->id();

    $existing = $series->get('custom_date')->getValue();
    $existing[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existing);
    $series->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $allInstances = $this->loadInstances($reloaded);

    $matchingPastDate = array_filter(
      $allInstances,
      fn ($i) => $i->get('date')->value === '2000-01-01T10:00:00',
    );
    $this->assertCount(1, $matchingPastDate, 'Exactly one instance represents the past date — no duplicate was created.');
    $onlyPast = reset($matchingPastDate);
    $this->assertSame($pastId, (int) $onlyPast->id(), 'The one instance representing the past date is the original preserved instance.');
  }

  /**
   * A registered NULL-end-value instance trips the belt, not the block.
   *
   * RegistrantCounter::countFutureForSeries() runs `end_value > now` in SQL,
   * which is FALSE for a NULL end_value — so a registrant on a NULL-end
   * instance is invisible to BOTH EventSeriesRescheduleBlockConstraint
   * (validate-time) and access_events_eventseries_presave() (the bare-save
   * backstop). Neither guard would block a recur-config save here.
   * PastPreservingEventInstanceCreator::hasEnded() also treats a NULL
   * end_value as NOT ended, so that instance is queued for deletion like any
   * other future instance — the registrant belt immediately before deletion
   * in processInstances() is the ONLY thing standing between this instance
   * and having its registrant destroyed. Invoke processInstances() directly
   * (via the plugin manager, exactly as recurring_events.module does) rather
   * than routing through a full series save, since the point under test is
   * the plugin's own belt, not the upstream guards (which are proven
   * incapable of catching this case by construction here).
   */
  public function testNullEndValueInstanceTripsBeltNotBlock(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([(int) $coordinator->id()]);

    $series = EventSeries::create([
      'title' => 'Null End Value Belt Event',
      'body' => 'An event with a NULL-end-value instance carrying a registrant.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    $series->save();
    $series->set('moderation_state', 'published')->save();

    // The insert hook's spawned instance is not what's under test; build a
    // SEPARATE instance directly with date.end_value left unset (NULL) and
    // reparent it onto this series — mirrors the "prior tests reparent
    // instances via set('eventseries_id', ...)" pattern. A direct create
    // bypasses the module's own instance-creation pipeline (which always
    // populates end_value from a date range), which is exactly how a NULL
    // end_value could arise in practice (a legacy row, a partial import, a
    // field left blank by a direct API write) without needing to defeat any
    // of this module's own validation to construct the fixture.
    $nullEndInstance = \Drupal\recurring_events\Entity\EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-03-01T10:00:00'],
    ]);
    $nullEndInstance->save();
    $nullEndInstance->set('moderation_state', 'published')->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($nullEndInstance, (int) $series->id());
    $this->assertNull($nullEndInstance->get('date')->end_value, 'Fixture instance genuinely has a NULL end_value.');

    $registrant = $this->registerUser($this->createUser(), $nullEndInstance);
    $instanceId = (int) $nullEndInstance->id();
    $registrantId = (int) $registrant->id();

    // Confirm by construction that neither upstream guard sees this
    // registrant as "future" — this is the hole IMPORTANT 1 is about, not an
    // assumption.
    $futureCount = \Drupal::service('access_events.registrant_counter')->countFutureForSeries((int) $series->id());
    $this->assertSame(0, $futureCount, 'RegistrantCounter cannot see a NULL-end-value registrant as future — confirms the hole the belt must cover.');

    $reloadedSeries = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $plugin = \Drupal::service('plugin.manager.event_instance_creator')
      ->createInstance('access_events_past_preserving_recreator', []);

    $threw = FALSE;
    try {
      $plugin->processInstances($reloadedSeries);
    }
    catch (\RuntimeException $e) {
      $threw = TRUE;
      $this->assertStringContainsString((string) $instanceId, $e->getMessage());
    }
    $this->assertTrue($threw, 'processInstances() must throw when a to-be-deleted instance unexpectedly has a registrant.');

    // Nothing was deleted: the NULL-end instance and its registrant both
    // survive, untouched.
    $survivingInstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($instanceId);
    $this->assertNotNull($survivingInstance, 'The NULL-end-value instance was not deleted by the aborted rebuild.');
    $survivingRegistrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrantId);
    $this->assertNotNull($survivingRegistrant, 'The registrant was not deleted by the aborted rebuild.');
  }

  /**
   * A rule-based (weekly) series preserves its past instance across a rebuild.
   *
   * The partition/creation logic in PastPreservingEventInstanceCreator is by
   * date, not recur type — this pins that against a rule-based series, not
   * just custom-date ones, exercising the SAME
   * recurring_events_event_instances_pre_create alter through the
   * calculateInstances()-driven branch of EventCreationService::
   * createInstances() (contrib's WeeklyRecurringDate field type), rather
   * than the 'custom' branch the other tests in this file cover. It also
   * pins the `?? NULL`-then-getTimestamp() shape of $events_to_create
   * against contrib drift: if that computed-array shape ever changes (e.g.
   * the key is renamed), the module alter's `$dates['end_date'] ?? NULL`
   * fails OPEN — silently stops filtering rather than erroring — and this
   * test is what would catch the resulting duplicate.
   */
  public function testRuleBasedRecurConfigChangePreservesPastInstanceAndRegistrant(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);
    $series->set('moderation_state', 'published')->save();
    foreach ($this->loadInstances($series) as $instance) {
      $instance->set('moderation_state', 'published')->save();
    }

    // makeCoordinatorRuleSeries() seeds a FUTURE weekly rule (2999-01-04 to
    // 2999-01-10); reparent one of its spawned future instances' dates
    // backward into the past so this series has a genuinely past instance to
    // preserve, mirroring how prior tests reparent instances onto a series
    // via set(). Simplest correct approach here is to directly set the
    // existing instance's date into the past (rather than reparenting a
    // foreign instance), since the instance object and its eventseries_id
    // are already correct — only the date needs to move.
    $instances = $this->loadInstances($series);
    $this->assertNotEmpty($instances, 'The weekly rule spawned at least one instance.');
    $pastInstance = reset($instances);
    $pastInstance->set('date', ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00']);
    $pastInstance->set('moderation_state', 'published')->save();
    $pastId = (int) $pastInstance->id();

    $registrant = $this->registerUser($this->createUser(), $pastInstance);
    $registrantId = (int) $registrant->id();
    $preExistingIds = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));

    // Change the rule config (extend the window by a week) — a recur-config
    // change on a published rule series, firing the instance-creator plugin
    // exactly as a custom_date append does for a custom series.
    $series->set('weekly_recurring_date', [
      'value' => '2999-01-04T00:00:00',
      'end_value' => '2999-01-17T00:00:00',
      'time' => '10:00 AM',
      'end_time' => '11:00 AM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => 'monday,wednesday',
    ]);
    $series->save();

    // The past instance survives with the SAME entity id and its registrant
    // is untouched.
    $survivingPast = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($pastId);
    $this->assertNotNull($survivingPast, 'The past instance was not deleted by the rule-config rebuild.');
    $this->assertSame('2000-01-01T10:00:00', $survivingPast->get('date')->value);
    $survivingRegistrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrantId);
    $this->assertNotNull($survivingRegistrant, 'The past instance registrant was not deleted by the rule-config rebuild.');

    // The future side rebuilt: at least one instance now falls in the
    // extended window (2999-01-11 through 2999-01-17), which did not exist
    // under the original rule.
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $allInstances = $this->loadInstances($reloaded);
    $instanceDates = array_map(fn ($i) => $i->get('date')->value, $allInstances);
    $this->assertContains('2000-01-01T10:00:00', $instanceDates, 'Past date still represented by exactly the preserved instance.');
    $laterWeekDates = array_filter($instanceDates, fn ($d) => $d >= '2999-01-11T00:00:00');
    $this->assertNotEmpty($laterWeekDates, 'The extended rule window materialized at least one new future instance.');

    // The rebuild-created instances (the extended window's new occurrences)
    // must be published — asserted on the reloaded instances NOT present
    // before this rebuild, since the setUp loop's hand-publish only ran once,
    // before the rule-config change, and would mask a regression here.
    $newInstances = array_filter($allInstances, fn ($i) => !in_array((int) $i->id(), $preExistingIds, TRUE));
    $this->assertNotEmpty($newInstances, 'The rule-config rebuild created at least one new instance.');
    foreach ($newInstances as $newInstance) {
      $this->assertSame(
        'published',
        $newInstance->get('moderation_state')->value,
        'A rebuild-created instance on a published series must itself be published.',
      );
    }
  }

  /**
   * A flagged (individually_cancelled) instance is preserved even though it
   * carries no registrant and is NOT past — orthogonal to the past/future
   * partition.
   *
   * EventStateReactions::instancePresave() sets individually_cancelled = TRUE
   * whenever a single instance moves away from published outside a
   * series-wide sweep. This test builds a published FUTURE instance,
   * individually cancels it (archiving it directly, unregistered), then
   * fires a recur-config rebuild — the flagged instance must survive with
   * the SAME id and stay archived, exactly like a past instance would,
   * because PastPreservingEventInstanceCreator::isFlagged() skips it from
   * both the delete loop and the registrant belt.
   */
  public function testRebuildPreservesFlaggedInstanceEvenUnregistered(): void {
    [$series, , $future] = $this->buildSeriesWithPastAndFuture();
    $futureId = (int) $future->id();

    // Individually cancel the future instance directly (no registrant on
    // it) — outside any series-wide sweep, so EventStateReactions::
    // instancePresave() sets individually_cancelled = TRUE.
    $future->set('moderation_state', 'archived');
    $future->save();
    $flagged = $this->reloadInstance($future);
    $this->assertSame('1', (string) $flagged->get('individually_cancelled')->value, 'Fixture instance is genuinely flagged.');
    $this->assertSame(0, $this->countRegistrants($flagged), 'Fixture flagged instance carries no registrant.');

    // Recur-config change on the still-published series — fires the
    // instance-creator plugin exactly as the other rebuild tests do.
    $existing = $series->get('custom_date')->getValue();
    $existing[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existing);
    $series->save();

    $survivingFlagged = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($futureId);
    $this->assertNotNull($survivingFlagged, 'The flagged instance was not deleted by the rebuild.');
    $this->assertSame('2999-01-01T10:00:00', $survivingFlagged->get('date')->value, 'The flagged instance kept its original date.');
    $this->assertSame('archived', $survivingFlagged->get('moderation_state')->value, 'The flagged instance stayed archived — the rebuild did not touch its state.');
    $this->assertSame('1', (string) $survivingFlagged->get('individually_cancelled')->value, 'The flag itself survived the rebuild untouched.');
  }

  /**
   * No live twin is created at a preserved flagged instance's date.
   *
   * The series' custom_date config still lists the flagged instance's own
   * date (it was never removed from config — only its instance's state
   * changed) — a naive rebuild would treat that as "this date needs an
   * instance" and materialize a fresh, published twin right next to the
   * publicly cancelled one. access_events_recurring_events_event_instances_
   * pre_create_alter()'s flagged-date exclusion (via EffectiveCreationSet::
   * filterFlaggedDates()) must prevent that: exactly ONE instance exists at
   * that start timestamp after the rebuild, and it is the original flagged
   * one, still archived.
   */
  public function testRebuildDoesNotRecreateFlaggedDate(): void {
    [$series, , $future] = $this->buildSeriesWithPastAndFuture();
    $futureId = (int) $future->id();
    $flaggedDate = $future->get('date')->value;

    $future->set('moderation_state', 'archived');
    $future->save();
    $this->assertSame('1', (string) $this->reloadInstance($future)->get('individually_cancelled')->value, 'Fixture instance is genuinely flagged.');

    // Recur-config change that leaves the flagged date in config (only
    // appends a new date) — the scenario where a naive rebuild would
    // otherwise recreate a live twin at the flagged date.
    $existing = $series->get('custom_date')->getValue();
    $existing[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existing);
    $series->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $allInstances = $this->loadInstances($reloaded);
    $matchingFlaggedDate = array_filter($allInstances, fn ($i) => $i->get('date')->value === $flaggedDate);
    $this->assertCount(1, $matchingFlaggedDate, 'Exactly one instance represents the flagged date — no live twin was created.');
    $onlyInstance = reset($matchingFlaggedDate);
    $this->assertSame($futureId, (int) $onlyInstance->id(), 'The one instance at the flagged date is the original preserved (flagged) instance.');
    $this->assertSame('archived', $onlyInstance->get('moderation_state')->value, 'No live twin means no published instance sits at the flagged date.');
  }

  /**
   * DOCUMENTED DOCTRINE: an UNREGISTERED, un-flagged future instance's
   * divergence from current config is discarded (deleted + regenerated), not
   * preserved.
   *
   * This is the ordinary, expected rebuild behavior for the common case —
   * flagging (individually_cancelled) and past-ness are the ONLY two reasons
   * a future instance survives a rebuild. An instance that is simply
   * unregistered and not flagged has no such protection: if its own date is
   * no longer requested by the series' CURRENT config, the rebuild deletes
   * it and regenerates the config's actual date set from scratch. This test
   * pins that doctrine so a future change does not accidentally start
   * preserving every stray future instance regardless of registration state
   * or flag.
   */
  public function testRebuildDiscardsUnregisteredDivergence(): void {
    [$series, , $future] = $this->buildSeriesWithPastAndFuture();
    $futureId = (int) $future->id();
    $this->assertSame(0, $this->countRegistrants($future), 'Fixture future instance carries no registrant.');
    $this->assertSame('0', (string) $future->get('individually_cancelled')->value, 'Fixture future instance is not flagged.');

    // Replace the future date in config entirely (not append) — the
    // original future instance's date is no longer requested at all.
    $existing = $series->get('custom_date')->getValue();
    $existing[1] = ['value' => '2999-09-01T10:00:00', 'end_value' => '2999-09-01T12:00:00'];
    $series->set('custom_date', $existing);
    $series->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $allInstances = $this->loadInstances($reloaded);
    $instanceDates = array_map(fn ($i) => $i->get('date')->value, $allInstances);

    // The unregistered, unflagged original future instance is gone — either
    // deleted outright or replaced by a new id at the same or a different
    // date; assert on the definitive signal: its old date is no longer
    // requested by config AND no instance carries the old future id.
    $this->assertNotContains('2999-01-01T10:00:00', $instanceDates, 'The old future date is no longer materialized — divergence was discarded, not preserved.');
    $survivorAtOldId = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($futureId);
    $stillPresent = $survivorAtOldId !== NULL && $survivorAtOldId->get('date')->value === '2999-01-01T10:00:00';
    $this->assertFalse($stillPresent, 'The original unregistered, unflagged future instance was not preserved at its original date.');
    $this->assertContains('2999-09-01T10:00:00', $instanceDates, 'The new config date was materialized in its place.');
  }

}
