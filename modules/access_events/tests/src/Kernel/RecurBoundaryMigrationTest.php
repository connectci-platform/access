<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Entity\EventSeriesAccess;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;
use Psr\Log\LoggerInterface;

/**
 * Legacy recurrence-boundary rows migrate to the T12 anchor invariant.
 *
 * The migrator classifies each boundary COLUMN independently and rewrites
 * both columns of a pair in one UPDATE per row, on both shared tables
 * (eventseries_field_data + eventseries_field_revision):
 * - T00 rows (the D8-2838 bug's victims) keep their LITERAL date and are
 *   victim-logged with id, title, and registrant count;
 * - bare length-10 dates keep their literal date;
 * - already-anchored T12 rows are untouched (first run: audit-logged;
 *   re-runs: silent);
 * - wall-clock rows recover their date through the SITE default timezone;
 * - NULL columns are never touched.
 *
 * @covers \Drupal\access_events\RecurBoundaryMigrator
 * @group access_events
 */
class RecurBoundaryMigrationTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

  /**
   * All boundary columns of the five rule fields, for raw-row snapshots.
   */
  private const BOUNDARY_COLUMNS = [
    'consecutive_recurring_date__value', 'consecutive_recurring_date__end_value',
    'daily_recurring_date__value', 'daily_recurring_date__end_value',
    'weekly_recurring_date__value', 'weekly_recurring_date__end_value',
    'monthly_recurring_date__value', 'monthly_recurring_date__end_value',
    'yearly_recurring_date__value', 'yearly_recurring_date__end_value',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and the affinity-group node insert hook (reads
    // field_affinity_group). Seed the empty site-level fields those hooks
    // touch, mirroring EventSeriesBoundaryDecodeTest.
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

    // The migrator recovers wall-clock rows through the SITE default zone
    // (config system.date), independent of whoever runs updb/drush. Pin it
    // to the production value so the recovery assertions are meaningful:
    // PHP's test-runner default TZ (Australia/Sydney in kernel tests) would
    // produce DIFFERENT dates for the fixtures below if the migrator wrongly
    // used date_default_timezone_get().
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
  }

  /**
   * The migrator service under test.
   */
  private function migrator(): object {
    return \Drupal::service('access_events.recur_boundary_migrator');
  }

  /**
   * A saved series to plant raw boundary rows on.
   */
  private function makeFixtureSeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    return $this->makeCoordinatorRuleSeries($coordinator);
  }

  /**
   * Fetches [value, end_value] rows for a series' field from one table.
   *
   * @return array[]
   *   One [value, end_value] pair per row (the revision table can hold
   *   several rows per series).
   */
  private function rawPairs(string $table, int $seriesId, string $field): array {
    $rows = \Drupal::database()->select($table, 't')
      ->fields('t', [$field . '__value', $field . '__end_value'])
      ->condition('id', $seriesId)
      ->execute()
      ->fetchAll(\PDO::FETCH_NUM);
    $this->assertNotEmpty($rows, sprintf('series %d has rows in %s', $seriesId, $table));
    return $rows;
  }

  /**
   * Asserts a series' field pair reads ($value, $endValue) in BOTH tables.
   */
  private function assertPairInBothTables(int $seriesId, string $field, ?string $value, ?string $endValue): void {
    foreach (['eventseries_field_data', 'eventseries_field_revision'] as $table) {
      foreach ($this->rawPairs($table, $seriesId, $field) as $pair) {
        $this->assertSame([$value, $endValue], $pair, sprintf('%s.%s for series %d', $table, $field, $seriesId));
      }
    }
  }

  /**
   * Snapshots every boundary column of every row in both tables.
   */
  private function snapshotBoundaryColumns(): array {
    $snapshot = [];
    foreach (['eventseries_field_data' => ['id'], 'eventseries_field_revision' => ['id', 'vid']] as $table => $keys) {
      $query = \Drupal::database()->select($table, 't')
        ->fields('t', array_merge($keys, self::BOUNDARY_COLUMNS))
        ->orderBy('id');
      if (in_array('vid', $keys, TRUE)) {
        $query->orderBy('vid');
      }
      $snapshot[$table] = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    return $snapshot;
  }

  /**
   * Attaches a record-collecting logger to every channel.
   */
  private function collectLogs(): RecurBoundaryLogCollector {
    $collector = new RecurBoundaryLogCollector();
    \Drupal::service('logger.factory')->addLogger($collector);
    return $collector;
  }

  /**
   * Counts all eventinstance entities.
   */
  private function instanceCount(): int {
    return (int) \Drupal::entityTypeManager()->getStorage('eventinstance')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Every shape migrates per COLUMN, in both tables; NULL stays NULL.
   */
  public function testMigrationNormalizesAllShapesInBothTables(): void {
    $t00 = $this->makeFixtureSeries();
    $this->setRawBoundary($t00, 'weekly_recurring_date', '2026-04-02T00:00:00', '2026-04-30T00:00:00');

    $bare = $this->makeFixtureSeries();
    $this->setRawBoundary($bare, 'weekly_recurring_date', '2026-07-04', '2026-07-18');

    $t12 = $this->makeFixtureSeries();
    $this->setRawBoundary($t12, 'weekly_recurring_date', '2026-08-05T12:00:00', '2026-08-19T12:00:00');

    // 01:30 UTC Apr 3 is 21:30 Apr 2 in America/New_York; 02:15 UTC Oct 20
    // is 22:15 Oct 19 — both recover to the PREVIOUS calendar date, so a
    // wrong recovery zone (UTC or the runner's PHP default) fails loudly.
    $wall = $this->makeFixtureSeries();
    $this->setRawBoundary($wall, 'weekly_recurring_date', '2026-04-03T01:30:00', '2026-10-20T02:15:00');

    // A pair can legitimately MIX shapes; both columns must land correctly
    // from one row rewrite.
    $mixed = $this->makeFixtureSeries();
    $this->setRawBoundary($mixed, 'weekly_recurring_date', '2026-05-01T00:00:00', '2026-06-01T03:00:00');

    $report = $this->migrator()->migrate(TRUE);

    $this->assertPairInBothTables((int) $t00->id(), 'weekly_recurring_date', '2026-04-02T12:00:00', '2026-04-30T12:00:00');
    $this->assertPairInBothTables((int) $bare->id(), 'weekly_recurring_date', '2026-07-04T12:00:00', '2026-07-18T12:00:00');
    $this->assertPairInBothTables((int) $t12->id(), 'weekly_recurring_date', '2026-08-05T12:00:00', '2026-08-19T12:00:00');
    $this->assertPairInBothTables((int) $wall->id(), 'weekly_recurring_date', '2026-04-02T12:00:00', '2026-10-19T12:00:00');
    $this->assertPairInBothTables((int) $mixed->id(), 'weekly_recurring_date', '2026-05-01T12:00:00', '2026-05-31T12:00:00');

    // The four rule fields the fixtures never touched stay NULL — the WHERE
    // clause must not write epoch anchors into empty pairs.
    $this->assertPairInBothTables((int) $t00->id(), 'monthly_recurring_date', NULL, NULL);

    // One UPDATE per ROW: the t00/bare/wall/mixed rows update once each per
    // table; the t12 row updates in neither.
    $this->assertSame(
      ['eventseries_field_data' => 4, 'eventseries_field_revision' => 4],
      $report['updated'],
    );
  }

  /**
   * The victim log is exactly the T00 series, with registrant counts.
   */
  public function testVictimListAndFirstRunAuditLog(): void {
    $t00 = $this->makeFixtureSeries();
    $this->setRawBoundary($t00, 'weekly_recurring_date', '2026-04-02T00:00:00', '2026-04-30T00:00:00');
    // A registrant on the victim: its count feeds the remediation split
    // (unregistered → safe regenerate; registered → operator playbook).
    $instances = $this->loadInstances($t00);
    $this->registerUserOnDraftInstance($this->createUser(), reset($instances));

    $mixed = $this->makeFixtureSeries();
    $this->setRawBoundary($mixed, 'weekly_recurring_date', '2026-05-01T00:00:00', '2026-06-01T03:00:00');

    $t12 = $this->makeFixtureSeries();
    $this->setRawBoundary($t12, 'weekly_recurring_date', '2026-08-05T12:00:00', '2026-08-19T12:00:00');

    $wall = $this->makeFixtureSeries();
    $this->setRawBoundary($wall, 'weekly_recurring_date', '2026-04-03T01:30:00', '2026-10-20T02:15:00');

    $collector = $this->collectLogs();
    $report = $this->migrator()->migrate(TRUE);

    // Victims == exactly the series whose T00 branch fired (the pure-T00 one
    // and the mixed one — its value column is T00), each with the same
    // registrant count the reschedule guard uses.
    $victims = $report['victims'];
    usort($victims, fn(array $a, array $b) => $a['id'] <=> $b['id']);
    $this->assertSame([
      ['id' => (int) $t00->id(), 'title' => $t00->label(), 'registrants' => 1],
      ['id' => (int) $mixed->id(), 'title' => $mixed->label(), 'registrants' => 0],
    ], $victims);

    // First-run audit == exactly the T12-shaped rows (one per table for the
    // t12 series; nothing else wears the anchor before migration).
    $auditIds = array_values(array_unique(array_map(fn(array $row) => $row['id'], $report['audit'])));
    $this->assertSame([(int) $t12->id()], $auditIds);
    $auditTables = array_map(fn(array $row) => $row['table'], $report['audit']);
    sort($auditTables);
    $this->assertSame(['eventseries_field_data', 'eventseries_field_revision'], $auditTables);

    // Victims and the audit reach watchdog too.
    $logged = implode("\n", array_map(
      fn(array $record) => strtr($record['message'], array_map(
        strval(...),
        array_filter($record['context'], fn($v) => is_scalar($v) || $v instanceof \Stringable),
      )),
      $collector->records,
    ));
    $this->assertStringContainsString((string) $t00->id(), $logged);
    $this->assertStringContainsString($t00->label(), $logged);
    $this->assertStringContainsString((string) $t12->id(), $logged);
  }

  /**
   * A re-run leaves every byte in place and logs nothing.
   */
  public function testRerunIsByteIdenticalAndSilent(): void {
    $t00 = $this->makeFixtureSeries();
    $this->setRawBoundary($t00, 'weekly_recurring_date', '2026-04-02T00:00:00', '2026-04-30T00:00:00');
    $bare = $this->makeFixtureSeries();
    $this->setRawBoundary($bare, 'weekly_recurring_date', '2026-07-04', '2026-07-18');
    $wall = $this->makeFixtureSeries();
    $this->setRawBoundary($wall, 'weekly_recurring_date', '2026-04-03T01:30:00', '2026-10-20T02:15:00');
    $t12 = $this->makeFixtureSeries();
    $this->setRawBoundary($t12, 'weekly_recurring_date', '2026-08-05T12:00:00', '2026-08-19T12:00:00');

    $this->migrator()->migrate(TRUE);
    $snapshot = $this->snapshotBoundaryColumns();

    $collector = $this->collectLogs();
    $rerun = $this->migrator()->migrate(FALSE);

    $this->assertSame($snapshot, $this->snapshotBoundaryColumns());
    $this->assertSame(['eventseries_field_data' => 0, 'eventseries_field_revision' => 0], $rerun['updated']);
    $this->assertSame([], $rerun['victims']);
    // Anchored rows are skipped SILENTLY on re-runs: no audit entries (by
    // then legitimate anchors exist, so auditing would be noise) ...
    $this->assertSame([], $rerun['audit']);
    // ... and nothing at all reaches watchdog.
    $this->assertSame([], $collector->records);
  }

  /**
   * Post-migration loads decode the new rows; no instances are touched.
   *
   * The pre-migration load primes the entity cache, so the getters can only
   * see the rewritten columns if the migrator itself resets the storage
   * cache — no manual resetCache() here, deliberately.
   */
  public function testEntitiesDecodePostMigrationWithoutManualCacheReset(): void {
    $t00 = $this->makeFixtureSeries();
    $this->setRawBoundary($t00, 'weekly_recurring_date', '2026-04-02T00:00:00', '2026-04-30T00:00:00');
    $wall = $this->makeFixtureSeries();
    $this->setRawBoundary($wall, 'weekly_recurring_date', '2026-04-03T01:30:00', '2026-10-20T02:15:00');

    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    // Prime the cache with the pre-migration rows.
    $storage->load($t00->id());
    $storage->load($wall->id());
    $instancesBefore = $this->instanceCount();

    $this->migrator()->migrate(TRUE);

    $loadedT00 = $storage->load($t00->id());
    $this->assertSame('2026-04-02', $loadedT00->getWeeklyStartDate()->format('Y-m-d'));
    $this->assertSame('2026-04-30', $loadedT00->getWeeklyEndDate()->format('Y-m-d'));
    $loadedWall = $storage->load($wall->id());
    $this->assertSame('2026-04-02', $loadedWall->getWeeklyStartDate()->format('Y-m-d'));
    $this->assertSame('2026-10-19', $loadedWall->getWeeklyEndDate()->format('Y-m-d'));

    // Direct DB rewrite only — no entity saves, no instance regeneration.
    $this->assertSame($instancesBefore, $this->instanceCount());
  }

  /**
   * The update hook runs the first-run migration and reports a summary.
   */
  public function testUpdateHookDelegatesToTheMigrator(): void {
    // hook_update_N lives in the .install file, which is only included by
    // update.php/drush updb — load it explicitly, as the deploy-hook test
    // does for the .deploy.php file.
    require_once __DIR__ . '/../../../access_events.install';
    $this->assertTrue(function_exists('access_events_update_10008'));

    $t00 = $this->makeFixtureSeries();
    $this->setRawBoundary($t00, 'weekly_recurring_date', '2026-04-02T00:00:00', '2026-04-30T00:00:00');

    $summary = (string) access_events_update_10008();

    $this->assertPairInBothTables((int) $t00->id(), 'weekly_recurring_date', '2026-04-02T12:00:00', '2026-04-30T12:00:00');
    $this->assertNotSame('', $summary);
  }

}

/**
 * Collects every record routed through the logger factory.
 */
final class RecurBoundaryLogCollector implements LoggerInterface {

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
