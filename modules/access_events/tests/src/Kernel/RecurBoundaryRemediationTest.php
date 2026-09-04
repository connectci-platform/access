<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;
use Psr\Log\LoggerInterface;

/**
 * Scoped remediation of bug-victim series (the post-migration step).
 *
 * The migration fixes a T00 victim's boundary COLUMNS but deliberately never
 * regenerates instances — so a victim's instances still sit on the slid
 * dates. RecurBoundaryMigrator::remediate() takes the migration's victim
 * list and, per victim:
 * - registrant count 0 (re-counted FRESH at remediation time, not trusted
 *   from the possibly-stale list): instances are regenerated through the
 *   normal creation path — the same active EventInstanceCreator plugin call
 *   contrib's own eventseries_update hook makes — so they land on the
 *   corrected boundary dates;
 * - registrant count > 0: the series is NEVER touched, only logged for the
 *   operator playbook (rebuilding a registered series destroys registrant
 *   linkage — the same reason the reschedule guard exists);
 * - the victim list is OVER-INCLUSIVE by design (a T00 shape on a
 *   non-default revision flags a series whose current instances were fine),
 *   so regenerating a false positive must be harmless: same boundaries in,
 *   same instance dates out.
 * The victim list also persists (state API) across the updb → operator
 * review → drush remediation gap, since post-migration no T00 shape remains
 * in the DB to re-derive it from.
 *
 * @covers \Drupal\access_events\RecurBoundaryMigrator
 * @group access_events
 */
class RecurBoundaryRemediationTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Series/instance saves resolve through access_events_entity_presave and
    // the affinity-group node insert hook; seed the empty site-level fields
    // those hooks touch, mirroring RecurBoundaryMigrationTest.
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
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * The migrator service under test.
   */
  private function migrator(): object {
    return \Drupal::service('access_events.recur_boundary_migrator');
  }

  /**
   * A saved rule series (weekly Mon+Wed, 2999-01-04 .. 2999-01-10).
   */
  private function makeFixtureSeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    return $this->makeCoordinatorRuleSeries($coordinator);
  }

  /**
   * The series' raw instance start values ({date}__value), sorted.
   *
   * Raw stored strings, not decoded objects: the pin is on exactly what the
   * regeneration wrote.
   */
  private function instanceStartValues(EventSeries $series): array {
    $values = array_map(
      fn(object $instance) => (string) $instance->get('date')->value,
      $this->loadInstances($series),
    );
    sort($values);
    return array_values($values);
  }

  /**
   * Maps instance id => raw start value, for untouched-victim assertions.
   */
  private function instanceMap(EventSeries $series): array {
    $map = [];
    foreach ($this->loadInstances($series) as $instance) {
      $map[(int) $instance->id()] = (string) $instance->get('date')->value;
    }
    ksort($map);
    return $map;
  }

  /**
   * Slides a series' stored weekly boundary +7 days wearing the bug's T00.
   *
   * Recreates a real victim's post-bug state: the instances were generated
   * from the ORIGINAL window (at series save), while the stored boundary now
   * claims the following week in the bug era's T00 shape — after migration
   * the boundary reads Jan 11..Jan 17 but the instances still sit a week
   * early.
   */
  private function slideBoundaryAsT00Victim(EventSeries $series): void {
    $this->setRawBoundary($series, 'weekly_recurring_date', '2999-01-11T00:00:00', '2999-01-17T00:00:00');
  }

  /**
   * Attaches a record-collecting logger to every channel.
   */
  private function collectLogs(): RemediationLogCollector {
    $collector = new RemediationLogCollector();
    \Drupal::service('logger.factory')->addLogger($collector);
    return $collector;
  }

  /**
   * An unregistered victim's instances regenerate on the corrected dates.
   */
  public function testUnregisteredVictimRegeneratesOnCorrectedDates(): void {
    $victim = $this->makeFixtureSeries();
    $before = $this->instanceStartValues($victim);
    $this->assertNotEmpty($before, 'the fixture series spawned instances');
    $this->slideBoundaryAsT00Victim($victim);

    $report = $this->migrator()->migrate(TRUE);
    $this->assertSame([(int) $victim->id()], array_column($report['victims'], 'id'));
    // Migration alone must NOT have regenerated anything.
    $this->assertSame($before, $this->instanceStartValues($victim));

    $remediation = $this->migrator()->remediate($report['victims']);

    $this->assertSame([(int) $victim->id()], array_column($remediation['regenerated'], 'id'));
    $this->assertSame([], $remediation['registered']);
    $this->assertSame([], $remediation['missing']);

    // The corrected window is the original one shifted exactly +7 days, so
    // the regenerated instance set must be the old set shifted exactly +7
    // days — a timezone-robust pin (same weekday pattern, same time-of-day
    // mapping, both windows inside the same DST regime).
    $expected = array_map(
      fn(string $value) => gmdate('Y-m-d\TH:i:s', strtotime($value . ' UTC') + 7 * 86400),
      $before,
    );
    $this->assertSame($expected, $this->instanceStartValues($victim));
  }

  /**
   * A registered victim is NEVER touched — logged for the operator playbook.
   */
  public function testRegisteredVictimIsUntouchedAndLogged(): void {
    $victim = $this->makeFixtureSeries();
    $this->slideBoundaryAsT00Victim($victim);
    $instances = $this->loadInstances($victim);
    $this->registerUserOnDraftInstance($this->createUser(), reset($instances));

    $report = $this->migrator()->migrate(TRUE);
    $mapBefore = $this->instanceMap($victim);

    $collector = $this->collectLogs();
    $remediation = $this->migrator()->remediate($report['victims']);

    $this->assertSame([], $remediation['regenerated']);
    $this->assertSame(
      [['id' => (int) $victim->id(), 'title' => $victim->label(), 'registrants' => 1]],
      $remediation['registered'],
    );

    // Same instance IDS and same raw dates: nothing was deleted, recreated,
    // or rewritten.
    $this->assertSame($mapBefore, $this->instanceMap($victim));

    // The skip reaches watchdog for the operator playbook.
    $logged = implode("\n", array_map(
      fn(array $record) => strtr($record['message'], array_map(
        strval(...),
        array_filter($record['context'], fn($v) => is_scalar($v) || $v instanceof \Stringable),
      )),
      $collector->records,
    ));
    $this->assertStringContainsString((string) $victim->id(), $logged);
    $this->assertStringContainsString('operator', $logged);
  }

  /**
   * The registrant count is re-derived at remediation time, not trusted.
   *
   * The victim list rides state across the updb → drush gap; a registrant
   * who signed up IN that gap must still protect the series, even though the
   * migrate-time list says 0.
   */
  public function testRegistrantAddedAfterMigrationStillProtectsTheSeries(): void {
    $victim = $this->makeFixtureSeries();
    $this->slideBoundaryAsT00Victim($victim);

    $report = $this->migrator()->migrate(TRUE);
    $this->assertSame(0, $report['victims'][0]['registrants']);

    // The gap: someone registers after the migration ran.
    $instances = $this->loadInstances($victim);
    $this->registerUserOnDraftInstance($this->createUser(), reset($instances));
    $mapBefore = $this->instanceMap($victim);

    $remediation = $this->migrator()->remediate($report['victims']);

    $this->assertSame([], $remediation['regenerated']);
    $this->assertSame([(int) $victim->id()], array_column($remediation['registered'], 'id'));
    $this->assertSame(1, $remediation['registered'][0]['registrants']);
    $this->assertSame($mapBefore, $this->instanceMap($victim));
  }

  /**
   * Regenerating a FALSE-POSITIVE victim leaves its instance dates alone.
   *
   * A series whose only T00 shape sat on a non-default revision is flagged
   * even though its current boundaries (and instances) were fine. The list
   * is over-inclusive by design, so remediation must be harmless there:
   * regeneration from the already-correct boundaries produces the same
   * dates.
   */
  public function testFalsePositiveVictimKeepsItsInstanceDates(): void {
    $falsePositive = $this->makeFixtureSeries();
    $before = $this->instanceStartValues($falsePositive);
    // The T00 shape lives ONLY on a divergent revision row — the default
    // revision (and the data table) keep the correct anchored window the
    // instances were generated from.
    $this->plantDivergentRevisionRow($falsePositive, 'weekly_recurring_date', '2999-01-04T00:00:00', '2999-01-10T00:00:00');

    $report = $this->migrator()->migrate(TRUE);
    $this->assertSame([(int) $falsePositive->id()], array_column($report['victims'], 'id'));

    $remediation = $this->migrator()->remediate($report['victims']);

    $this->assertSame([(int) $falsePositive->id()], array_column($remediation['regenerated'], 'id'));
    $this->assertSame($before, $this->instanceStartValues($falsePositive));
  }

  /**
   * Victims persist for the drush command; remediation prunes what it fixed.
   *
   * Post-migration no T00 shape remains in the DB, so the drush remediation
   * command cannot re-derive the victim list — migrate() persists it in
   * state, pendingVictims() serves it, and remediate() prunes regenerated
   * and missing entries while a still-registered victim stays pending for
   * the operator.
   */
  public function testVictimListPersistsAcrossTheMigrateRemediateGap(): void {
    $unregistered = $this->makeFixtureSeries();
    $this->slideBoundaryAsT00Victim($unregistered);

    $registered = $this->makeFixtureSeries();
    $this->slideBoundaryAsT00Victim($registered);
    $instances = $this->loadInstances($registered);
    $this->registerUserOnDraftInstance($this->createUser(), reset($instances));

    $this->migrator()->migrate(TRUE);

    $pending = $this->migrator()->pendingVictims();
    $pendingIds = array_column($pending, 'id');
    sort($pendingIds);
    $this->assertSame([(int) $unregistered->id(), (int) $registered->id()], $pendingIds);

    // A victim whose series vanished in the gap is reported and pruned, not
    // fatal.
    $pending[] = ['id' => 999999, 'title' => 'Deleted since migration', 'registrants' => 0];
    $remediation = $this->migrator()->remediate($pending);
    $this->assertSame([999999], $remediation['missing']);

    // Only the still-registered victim remains pending.
    $this->assertSame(
      [(int) $registered->id()],
      array_column($this->migrator()->pendingVictims(), 'id'),
    );
  }

}

/**
 * Collects every record routed through the logger factory.
 */
final class RemediationLogCollector implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Collected records: level, message, context.
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

}
