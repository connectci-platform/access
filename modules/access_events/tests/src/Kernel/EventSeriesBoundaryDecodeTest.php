<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Anchored recurrence boundaries decode reader-independently (D8-2838).
 *
 * A stored `{date}T12:00:00` boundary means "this calendar date" for every
 * viewer: the ten boundary getters must return that literal date at midnight
 * in the reader's timezone, no matter how far the reader sits from UTC. The
 * stock contrib getters convert the stored instant into the reader's zone
 * first, which slides the date a day for UTC+12..+14 readers (and slid it a
 * day EARLY for US readers on legacy T00 rows — the original bug).
 *
 * Every fixture uses DISTINCT start and end dates: a same-date fixture could
 * never catch an end getter accidentally reading the start column.
 *
 * @group access_events
 */
class EventSeriesBoundaryDecodeTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

  /**
   * Anchored calendar-date fixtures per rule field: [start, end].
   *
   * Dates are distinct within each pair and across fields (cross-field bleed
   * shows up as a wrong date, not a coincidental pass). daily sits on the US
   * spring-forward boundary (2026-03-08), weekly spans the NZ DST start, and
   * yearly crosses a year boundary.
   */
  private const ANCHORED = [
    'consecutive_recurring_date' => ['2026-02-03', '2026-02-27'],
    'daily_recurring_date' => ['2026-03-08', '2026-03-14'],
    'weekly_recurring_date' => ['2026-09-07', '2026-10-19'],
    'monthly_recurring_date' => ['2026-06-01', '2026-11-30'],
    'yearly_recurring_date' => ['2026-12-31', '2027-01-02'],
  ];

  /**
   * Non-anchored (legacy/wall-clock) fixtures per rule field: [start, end].
   *
   * Shapes the anchor signature must NOT match: legacy T00 rows and assorted
   * wall-clock times. Distinct dates within each pair and across fields, as
   * with the anchored set.
   */
  private const WALL_CLOCK = [
    'consecutive_recurring_date' => ['2026-02-03T00:00:00', '2026-02-27T00:00:00'],
    'daily_recurring_date' => ['2026-03-08T18:30:00', '2026-03-14T21:15:00'],
    'weekly_recurring_date' => ['2026-09-07T23:45:00', '2026-10-19T01:30:00'],
    'monthly_recurring_date' => ['2026-06-01T04:00:00', '2026-11-30T22:10:00'],
    'yearly_recurring_date' => ['2026-12-31T16:20:00', '2027-01-02T05:05:00'],
  ];

  /**
   * [start getter, end getter] per rule field.
   */
  private const GETTERS = [
    'consecutive_recurring_date' => ['getConsecutiveStartDate', 'getConsecutiveEndDate'],
    'daily_recurring_date' => ['getDailyStartDate', 'getDailyEndDate'],
    'weekly_recurring_date' => ['getWeeklyStartDate', 'getWeeklyEndDate'],
    'monthly_recurring_date' => ['getMonthlyStartDate', 'getMonthlyEndDate'],
    'yearly_recurring_date' => ['getYearlyStartDate', 'getYearlyEndDate'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and the affinity-group node insert hook (reads
    // field_affinity_group). Seed the empty site-level fields those hooks
    // touch, mirroring EventCrudCancelOccurrenceTest.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1, []],
      ['eventinstance', 'domain_access', 'string', -1, []],
      ['eventseries', 'field_other_authors', 'entity_reference', -1, ['target_type' => 'user']],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality, $settings]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
          'settings' => $settings,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
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

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A saved series to plant raw boundary rows on.
   *
   * One series hosts all five rule fields' columns (they are cardinality-1
   * base fields on the shared data table), so the decode tests plant every
   * pair on the same row regardless of the series' own recur_type.
   */
  private function makeFixtureSeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    return $this->makeCoordinatorRuleSeries($coordinator);
  }

  /**
   * Runs $callable as a reader whose timezone is $timezone.
   *
   * Mirrors how a real request serves that user: the account is switched (as
   * asActingUser does) AND PHP's default timezone follows the user's pref,
   * because the boundary getters — contrib's and ours — resolve the reader
   * zone via date_default_timezone_get().
   */
  private function asReaderIn(string $timezone, callable $callable) {
    $reader = $this->createUser([], NULL, FALSE, ['timezone' => $timezone]);
    return $this->asActingUser($reader, function () use ($timezone, $callable) {
      $previous = date_default_timezone_get();
      date_default_timezone_set($timezone);
      try {
        return $callable();
      }
      finally {
        date_default_timezone_set($previous);
      }
    });
  }

  /**
   * Anchored rows decode to their literal dates for NY, UTC, and Auckland.
   *
   * Also pins the return-object contract every consumer relies on: a fresh
   * DrupalDateTime at midnight in the reader's zone (IANA name matching
   * date_default_timezone_get()), seconds and microseconds zeroed.
   */
  public function testAnchoredBoundariesDecodeLiterallyForAllReaders(): void {
    $series = $this->makeFixtureSeries();
    foreach (self::ANCHORED as $field => [$start, $end]) {
      $this->setRawBoundary($series, $field, $start . 'T12:00:00', $end . 'T12:00:00');
    }

    foreach (['America/New_York', 'UTC', 'Pacific/Auckland'] as $timezone) {
      $this->asReaderIn($timezone, function () use ($series, $timezone): void {
        $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
        $storage->resetCache([$series->id()]);
        $loaded = $storage->load($series->id());

        foreach (self::ANCHORED as $field => [$startDate, $endDate]) {
          [$startGetter, $endGetter] = self::GETTERS[$field];
          foreach ([[$startGetter, $startDate], [$endGetter, $endDate]] as [$getter, $expected]) {
            $label = sprintf('%s under %s', $getter, $timezone);
            $date = $loaded->{$getter}();
            $this->assertInstanceOf(DrupalDateTime::class, $date, $label);
            $this->assertSame($expected, $date->format('Y-m-d'), $label);
            $this->assertSame('00:00:00', $date->format('H:i:s'), $label);
            $this->assertSame('00.000000', $date->format('s.u'), $label);
            $this->assertSame($timezone, $date->getTimezone()->getName(), $label);
            $this->assertSame(date_default_timezone_get(), $date->getTimezone()->getName(), $label);
          }
        }
      });
    }
  }

  /**
   * Non-anchored rows decode byte-equivalent to contrib's stock getters.
   *
   * The hybrid's legacy branch must keep unmigrated rows and wall-clock
   * values behaving exactly as today. The expectation is contrib's actual
   * getter chain (EventSeries::getDailyStartDate() et al. verbatim:
   * computed start_date/end_date → setTimezone(reader) → setTime(0,0,0))
   * applied to the raw properties of a separately loaded entity — not a
   * re-statement of our own decode recipe, which would be a tautology.
   */
  public function testWallClockBoundariesMatchContribStockDecode(): void {
    $series = $this->makeFixtureSeries();
    foreach (self::WALL_CLOCK as $field => [$start, $end]) {
      $this->setRawBoundary($series, $field, $start, $end);
    }

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      $this->asReaderIn($timezone, function () use ($series, $timezone): void {
        $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
        $storage->resetCache([$series->id()]);
        $subject = $storage->load($series->id());
        // The expectation runs contrib's chain on a SEPARATELY loaded entity:
        // that chain mutates the item's shared computed date object, so
        // running it on $subject would poison the getter's input; and building
        // the expectation from our own decode recipe would only prove the
        // recipe equals itself.
        $storage->resetCache([$series->id()]);
        $copy = $storage->load($series->id());
        $this->assertNotSame($subject, $copy);

        $tz = new \DateTimeZone(date_default_timezone_get());
        foreach (self::GETTERS as $field => [$startGetter, $endGetter]) {
          $cases = [
            [$startGetter, $copy->get($field)->start_date->setTimezone($tz)->setTime(0, 0, 0)],
            [$endGetter, $copy->get($field)->end_date->setTimezone($tz)->setTime(0, 0, 0)],
          ];
          foreach ($cases as [$getter, $expected]) {
            $label = sprintf('%s under %s', $getter, $timezone);
            $actual = $subject->{$getter}();
            // serialize() compares full object state (class, inner \DateTime,
            // zone, DateTimePlus internals) — object identity is meaningless
            // here and formatted-string equality alone would miss a wrong
            // zone or leftover time-of-day.
            $this->assertSame(serialize($expected), serialize($actual), $label);
          }
        }
      });
    }
  }

  /**
   * Getters hand out fresh objects and never poison the computed property.
   *
   * Contrib's stock getters return the field item's SHARED computed date
   * object and mutate it in place (setTimezone + setTime) — and a shallow
   * clone shares the inner \DateTime, so it mutates it just the same. Two
   * pins against that whole family of regressions:
   * - two successive getter calls return distinct objects (equal values);
   * - after a getter runs, the item's raw computed start_date still holds
   *   the stored UTC instant untouched — under a far-east reader, where a
   *   mutation would be visible as an Auckland-midnight rewrite.
   */
  public function testGettersReturnFreshObjectsWithoutPoisoningTheItem(): void {
    $series = $this->makeFixtureSeries();
    [$wallStart, $wallEnd] = self::WALL_CLOCK['daily_recurring_date'];
    [$anchorStart, $anchorEnd] = self::ANCHORED['monthly_recurring_date'];
    $this->setRawBoundary($series, 'daily_recurring_date', $wallStart, $wallEnd);
    $this->setRawBoundary($series, 'monthly_recurring_date', $anchorStart . 'T12:00:00', $anchorEnd . 'T12:00:00');

    $this->asReaderIn('Pacific/Auckland', function () use ($series, $wallStart): void {
      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$series->id()]);
      $loaded = $storage->load($series->id());

      foreach (['getDailyStartDate', 'getMonthlyStartDate'] as $getter) {
        $first = $loaded->{$getter}();
        $second = $loaded->{$getter}();
        $this->assertNotSame($first, $second, "$getter returns a fresh object per call");
        $this->assertSame(serialize($first), serialize($second), "$getter is stable across calls");
      }

      // The wall-clock item's shared computed date is unpoisoned: still the
      // stored UTC instant, not an Auckland midnight.
      $computed = $loaded->get('daily_recurring_date')->start_date;
      $this->assertSame($wallStart, $computed->format('Y-m-d\TH:i:s'));
      $this->assertSame('UTC', $computed->getTimezone()->getName());
    });
  }

  /**
   * The fixture trait plants its raw pair on the revision row too.
   *
   * Loading through loadRevision() bypasses the default-revision data table,
   * so a planted value showing up here proves the trait's second UPDATE (the
   * eventseries_field_revision write) actually lands — a moderation-flow load
   * of the fixture would otherwise silently see the pre-trait values.
   */
  public function testSetRawBoundaryWritesTheRevisionRow(): void {
    $series = $this->makeFixtureSeries();
    $this->setRawBoundary($series, 'weekly_recurring_date', '2026-09-07T12:00:00', '2026-10-19T12:00:00');

    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $revision = $storage->loadRevision($series->getRevisionId());
    $item = $revision->get('weekly_recurring_date');
    $this->assertSame('2026-09-07T12:00:00', $item->value);
    $this->assertSame('2026-10-19T12:00:00', $item->end_value);
  }

}
