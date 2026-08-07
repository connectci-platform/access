<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests that a recur-config rebuild preserves past instances + registrants.
 *
 * recurring_events rebuilds ALL instances of a series when its recurrence
 * config changes. Contrib's stock RecreateEventInstanceCreator deletes and
 * recreates every instance, PAST ones included, destroying any attendance
 * history (and the underlying registrant rows) they carry.
 * PastPreservingEventInstanceCreator (wired in via
 * access_events_recurring_events_event_instance_creator_plugin_alter()) must
 * instead leave ended instances completely untouched and only rebuild the
 * future side.
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
    $registrantId = (int) $registrant->id();
    $preExistingIds = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));

    // Append a new future custom_date — a recur-config change on a published
    // series, which fires the instance-creator plugin.
    $existing = $series->get('custom_date')->getValue();
    $existing[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existing);
    $series->save();

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

}
