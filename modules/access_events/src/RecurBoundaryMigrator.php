<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\access_events\Entity\EventSeriesAccess;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Normalizes legacy recurrence-boundary rows to the T12 anchor invariant.
 *
 * Direct DB UPDATE, never entity saves: an entity-save loop would run the
 * reschedule guards (RuntimeException on registered series mid-migration) and
 * rebuild instances. The rewrite covers the five rule-field column pairs
 * ({field}__value / {field}__end_value) on exactly the two shared tables —
 * eventseries_field_data and eventseries_field_revision (the rules are
 * cardinality-1 base fields; there are no per-field tables).
 *
 * Each COLUMN is classified independently (a pair can legitimately mix
 * shapes) and both columns of a pair land in ONE UPDATE per row, so an
 * interrupted run can never leave a half-anchored pair that inverts a range:
 * - `T00:00:00` — a D8-2838 bug victim (the API era that stamped T00 onto
 *   bare dates): the sender meant that literal date, so it keeps its date
 *   part and gains the anchor. The owning series is victim-logged (id,
 *   title, registrant count) for scoped remediation;
 * - bare length-10 `YYYY-MM-DD` — literal date + anchor (defensive
 *   totality; no known writer produces it);
 * - `T12:00:00` — already anchored: skipped. On the FIRST run every such
 *   row is audit-logged (no legitimate anchors exist yet, so each one is a
 *   collision candidate); re-runs skip silently;
 * - anything else — browser wall-clock: the instant is recovered through
 *   the SITE default timezone (config system.date; the author's zone is not
 *   in the row) and the recovered date is anchored;
 * - NULL columns are never touched (every series has four empty rule
 *   pairs; anchoring them would fabricate epoch dates).
 *
 * The whole transform is idempotent: every rewrite produces an anchored
 * value, which the next run skips — so the drush re-run command is safe at
 * any time (also needed for rows written during a Drupal rollback, since
 * hook_update_N never re-fires).
 */
class RecurBoundaryMigrator {

  /**
   * The two shared eventseries tables and their row-identifying keys.
   */
  private const TABLES = [
    'eventseries_field_data' => ['id'],
    'eventseries_field_revision' => ['id', 'vid'],
  ];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected RegistrantCounter $registrantCounter,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Runs the normalization and returns a structured report.
   *
   * @param bool $firstRun
   *   TRUE for the initial hook_update_N run (audit-logs every T12-shaped
   *   row it skips); FALSE for drush re-runs (anchored rows skip silently —
   *   legitimate anchors exist by then, so auditing them would be noise).
   *
   * @return array
   *   Report with:
   *   - updated: rows rewritten, keyed by table;
   *   - shapes: per-COLUMN classification counts (t00, bare, wall_clock,
   *     anchored, unrecognized);
   *   - victims: [id, title, registrants] per series whose T00 branch fired
   *     (registrants counted as the reschedule guard counts — not-past);
   *   - audit: first-run T12-shaped rows ([table, id, vid, field, value,
   *     end_value]).
   */
  public function migrate(bool $firstRun = TRUE): array {
    $report = [
      'updated' => ['eventseries_field_data' => 0, 'eventseries_field_revision' => 0],
      'shapes' => ['t00' => 0, 'bare' => 0, 'wall_clock' => 0, 'anchored' => 0, 'unrecognized' => 0],
      'victims' => [],
      'audit' => [],
    ];
    $siteTz = new \DateTimeZone(
      $this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get()
    );
    $victimIds = [];
    $changedIds = [];

    foreach (self::TABLES as $table => $keys) {
      foreach (EventSeriesAccess::RULE_FIELDS as $field) {
        $valueCol = $field . '__value';
        $endCol = $field . '__end_value';
        $query = $this->database->select($table, 't')
          ->fields('t', array_merge($keys, [$valueCol, $endCol]));
        $query->condition($query->orConditionGroup()
          ->isNotNull($valueCol)
          ->isNotNull($endCol));

        foreach ($query->execute() as $row) {
          $updates = [];
          $rowAnchored = FALSE;
          foreach ([$valueCol, $endCol] as $column) {
            $raw = $row->{$column};
            if ($raw === NULL || $raw === '') {
              continue;
            }
            [$shape, $anchored] = $this->classify((string) $raw, $siteTz);
            $report['shapes'][$shape]++;
            if ($shape === 'anchored') {
              $rowAnchored = TRUE;
            }
            if ($shape === 't00') {
              $victimIds[(int) $row->id] = TRUE;
            }
            if ($anchored !== NULL && $anchored !== $raw) {
              $updates[$column] = $anchored;
            }
          }

          if ($rowAnchored && $firstRun) {
            $report['audit'][] = [
              'table' => $table,
              'id' => (int) $row->id,
              'vid' => isset($row->vid) ? (int) $row->vid : NULL,
              'field' => $field,
              'value' => $row->{$valueCol},
              'end_value' => $row->{$endCol},
            ];
          }

          if ($updates !== []) {
            // Both columns of the pair in this one statement — a death
            // between two writes must not leave a half-anchored pair.
            $update = $this->database->update($table)->fields($updates);
            foreach ($keys as $key) {
              $update->condition($key, $row->{$key});
            }
            $update->execute();
            $report['updated'][$table]++;
            $changedIds[(int) $row->id] = TRUE;
          }
        }
      }
    }

    foreach (array_keys($victimIds) as $id) {
      $title = $this->database->select('eventseries_field_data', 'd')
        ->fields('d', ['title'])
        ->condition('id', $id)
        ->execute()
        ->fetchField();
      $report['victims'][] = [
        'id' => $id,
        'title' => (string) $title,
        // The same count the reschedule guard keys on: registrants whose
        // instance is not verifiably past — the population a rebuild would
        // destroy, so remediation splits on it (0 → safe regenerate; >0 →
        // operator playbook per series).
        'registrants' => $this->registrantCounter->countNotPastForSeries($id),
      ];
    }

    if ($changedIds !== []) {
      $ids = array_keys($changedIds);
      $this->entityTypeManager->getStorage('eventseries')->resetCache($ids);
      $tags = ['eventseries_list'];
      foreach ($ids as $id) {
        $tags[] = 'eventseries:' . $id;
      }
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }

    $this->logReport($report);
    return $report;
  }

  /**
   * Classifies one raw column value and derives its anchored replacement.
   *
   * @param string $raw
   *   The stored column value (non-NULL, non-empty).
   * @param \DateTimeZone $siteTz
   *   The site default timezone wall-clock values recover through.
   *
   * @return array{0: string, 1: ?string}
   *   [shape, anchored replacement] — replacement is NULL when the value
   *   must stay untouched (anchored / unrecognized).
   */
  private function classify(string $raw, \DateTimeZone $siteTz): array {
    if (EventSeriesAccess::isAnchored($raw)) {
      return ['anchored', NULL];
    }
    // T00: a literal calendar date wearing midnight — keep the date part.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T00:00:00$/', $raw, $matches)) {
      return ['t00', $matches[1] . EventSeriesAccess::ANCHOR_SUFFIX];
    }
    // Bare date: literal by definition — it has no instant to convert.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      return ['bare', $raw . EventSeriesAccess::ANCHOR_SUFFIX];
    }
    // Wall-clock: a stored UTC instant; recover the calendar date the saver
    // meant by reading it in the site default zone. `!` zeroes unspecified
    // fields; the strict `\T` separator refuses odd shapes into the
    // untouched path below rather than corrupting them.
    $dt = \DateTime::createFromFormat('!Y-m-d\TH:i:s', $raw, new \DateTimeZone('UTC'));
    if ($dt === FALSE) {
      return ['unrecognized', NULL];
    }
    $dt->setTimezone($siteTz);
    return ['wall_clock', $dt->format('Y-m-d') . EventSeriesAccess::ANCHOR_SUFFIX];
  }

  /**
   * Sends the run's outcome to watchdog; a no-op run logs nothing.
   */
  private function logReport(array $report): void {
    $rowsUpdated = array_sum($report['updated']);
    if ($rowsUpdated === 0 && $report['victims'] === [] && $report['audit'] === []) {
      return;
    }
    $logger = $this->loggerFactory->get('access_events');

    $logger->notice('Recurrence boundary migration: @data data rows and @revision revision rows rewritten (columns: @t00 T00, @bare bare-date, @wall wall-clock; @anchored anchored skipped, @unrecognized unrecognized left untouched).', [
      '@data' => $report['updated']['eventseries_field_data'],
      '@revision' => $report['updated']['eventseries_field_revision'],
      '@t00' => $report['shapes']['t00'],
      '@bare' => $report['shapes']['bare'],
      '@wall' => $report['shapes']['wall_clock'],
      '@anchored' => $report['shapes']['anchored'],
      '@unrecognized' => $report['shapes']['unrecognized'],
    ]);

    foreach ($report['victims'] as $victim) {
      $logger->warning('Recurrence boundary bug-victim series @id ("@title", @count not-past registrants): its instances were generated from slid dates and need remediation (regenerate if unregistered; operator decision if registered).', [
        '@id' => $victim['id'],
        '@title' => $victim['title'],
        '@count' => $victim['registrants'],
      ]);
    }

    foreach ($report['audit'] as $entry) {
      $logger->notice('Recurrence boundary migration first-run audit: @table row for series @id (@field: @value / @end_value) already wears the anchor signature — collision candidate, verify the date is the intended one.', [
        '@table' => $entry['table'],
        '@id' => $entry['id'],
        '@field' => $entry['field'],
        '@value' => $entry['value'] ?? 'NULL',
        '@end_value' => $entry['end_value'] ?? 'NULL',
      ]);
    }
  }

}
