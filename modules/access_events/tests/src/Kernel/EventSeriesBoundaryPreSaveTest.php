<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * The bundle class preSave() normalizes recurrence boundaries on save.
 *
 * Storage-boundary normalization keys on CHANGE and WRITER, never on the
 * stored value's shape (an in-band `T12:00:00` sentinel is writer-reachable
 * and must not be trusted at write time). Three branches:
 * - a controller that already normalized flags the entity; its values are
 *   deliberate anchors and stored verbatim under any acting-user timezone;
 * - a value byte-identical to the stored original is never touched — a
 *   programmatic re-save (moderation, cron) under a far-east acting user
 *   must not re-derive an anchored date through that user's timezone;
 * - a changed value is recovered through the SAVER's timezone: the widget
 *   stored "chosen date + editor's wall clock" as UTC, so converting back
 *   into the saver's zone and taking the date part recovers exactly the
 *   date the editor chose, which is then anchored `T12:00:00`.
 *
 * The fixture series' active rule is weekly; these tests write the (inactive)
 * daily_recurring_date pair so a boundary change never triggers an instance
 * rebuild — preSave normalizes all five rule fields regardless of recur_type.
 *
 * @group access_events
 */
class EventSeriesBoundaryPreSaveTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

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
   * A saved series whose boundary columns the tests write and inspect.
   */
  private function makeFixtureSeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    return $this->makeCoordinatorRuleSeries($coordinator);
  }

  /**
   * Runs $callable as a saver whose timezone is $timezone.
   *
   * Mirrors how a real request saves for that user: the account is switched
   * (as asActingUser does) AND PHP's default timezone follows the user's
   * pref, because preSave resolves the saver zone via
   * date_default_timezone_get().
   */
  private function asSaverIn(string $timezone, callable $callable) {
    $saver = $this->createUser([], NULL, FALSE, ['timezone' => $timezone]);
    return $this->asActingUser($saver, function () use ($timezone, $callable) {
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
   * Sets a boundary pair on the series and saves, as a saver in $timezone.
   */
  private function saveBoundaryAs(string $timezone, int $seriesId, string $field, string $value, string $endValue): void {
    $this->asSaverIn($timezone, function () use ($seriesId, $field, $value, $endValue): void {
      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$seriesId]);
      $series = $storage->load($seriesId);
      $series->set($field, ['value' => $value, 'end_value' => $endValue]);
      $series->save();
    });
  }

  /**
   * Reads the stored bytes of a boundary pair straight from the data table.
   *
   * Direct SQL, not an entity load: the assertions here are about what the
   * STORAGE holds byte-for-byte, and an entity reload would tolerate any
   * representation that merely round-trips.
   *
   * @return array{0: string|null, 1: string|null}
   *   [{field}__value, {field}__end_value].
   */
  private function storedPair(int $seriesId, string $field): array {
    $row = \Drupal::database()->select('eventseries_field_data', 'd')
      ->fields('d', [$field . '__value', $field . '__end_value'])
      ->condition('id', $seriesId)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return [$row[$field . '__value'], $row[$field . '__end_value']];
  }

  /**
   * Changed wall-clock instants recover the saver's intended calendar date.
   *
   * Each input is what the browser date widget actually stores: the chosen
   * date at the editor's submit-moment wall clock, converted to UTC — which
   * lands on the WRONG calendar date for evening editors west of UTC.
   * Converting the instant back into the saver's own zone inverts that
   * write exactly, so preSave must store `{intended-date}T12:00:00`.
   */
  public function testChangedWallClockInputsAnchorToTheSaversIntendedDate(): void {
    $series = $this->makeFixtureSeries();
    $id = (int) $series->id();

    // timezone => [stored UTC input pair, intended date pair]. NY 21:30 and
    // LA 22:15 cross midnight in UTC (EDT -4, PDT -7); London 23:30 stays
    // same-day (BST +1) and pins that recovery is not a blind day-decrement.
    $cases = [
      'America/New_York' => [
        ['2026-10-02T01:30:00', '2026-10-09T01:30:00'],
        ['2026-10-01', '2026-10-08'],
      ],
      'America/Los_Angeles' => [
        ['2026-10-06T05:15:00', '2026-10-13T05:15:00'],
        ['2026-10-05', '2026-10-12'],
      ],
      'Europe/London' => [
        ['2026-10-10T22:30:00', '2026-10-17T22:30:00'],
        ['2026-10-10', '2026-10-17'],
      ],
    ];

    foreach ($cases as $timezone => [[$value, $endValue], [$startDate, $endDate]]) {
      $this->saveBoundaryAs($timezone, $id, 'daily_recurring_date', $value, $endValue);
      [$storedValue, $storedEnd] = $this->storedPair($id, 'daily_recurring_date');
      $this->assertSame($startDate . 'T12:00:00', $storedValue, "start saved under $timezone");
      $this->assertSame($endDate . 'T12:00:00', $storedEnd, "end saved under $timezone");
    }
  }

  /**
   * A changed T12-shaped value is still recovered through the saver's zone.
   *
   * The anchor signature is writer-reachable: a far-east editor saving at
   * local midnight stores a WRONG date wearing `T12:00:00` (Etc/GMT-12 —
   * UTC+12 under POSIX sign inversion — local 00:00 Oct 1 stores
   * `2026-09-30T12:00:00`). A signature-trusting preSave would freeze that
   * wrong date forever; keying on change instead recovers Oct 1, because
   * conversion through the saver's own zone inverts the wall-clock write.
   */
  public function testChangedAnchorShapedValueIsRecoveredNotTrusted(): void {
    $series = $this->makeFixtureSeries();
    $id = (int) $series->id();

    $this->saveBoundaryAs('Etc/GMT-12', $id, 'daily_recurring_date', '2026-09-30T12:00:00', '2026-10-07T12:00:00');

    [$storedValue, $storedEnd] = $this->storedPair($id, 'daily_recurring_date');
    $this->assertSame('2026-10-01T12:00:00', $storedValue);
    $this->assertSame('2026-10-08T12:00:00', $storedEnd);
  }

  /**
   * Unchanged boundary values re-save byte-identical in every saver zone.
   *
   * A no-op boundary re-save (title edit, moderation transition, cron) must
   * never re-derive a stored value through the acting user's timezone: a
   * correct anchor already reads back as next-day under a UTC+12/+13 user,
   * so any re-derivation would ratchet the date a day per save. Pinned on
   * anchored AND wall-clock (legacy-shaped) rows, under a western and a
   * far-east saver, across two consecutive re-saves.
   */
  public function testUnchangedValuesAreStoredByteIdenticalUnderAnySaver(): void {
    $series = $this->makeFixtureSeries();
    $id = (int) $series->id();

    // Plant legacy/wall-clock and anchored pairs via direct DB write — the
    // only way to manufacture these shapes once preSave exists.
    $wallClock = ['2026-03-08T18:30:00', '2026-03-14T21:15:00'];
    $anchored = ['2026-06-01T12:00:00', '2026-11-30T12:00:00'];
    $this->setRawBoundary($series, 'daily_recurring_date', ...$wallClock);
    $this->setRawBoundary($series, 'monthly_recurring_date', ...$anchored);
    // The active weekly rule was anchored on the initial save; capture its
    // exact bytes to prove consecutive far-east re-saves cannot ratchet it.
    $weekly = $this->storedPair($id, 'weekly_recurring_date');

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      $this->asSaverIn($timezone, function () use ($id, $timezone): void {
        $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
        $storage->resetCache([$id]);
        $loaded = $storage->load($id);
        $loaded->set('body', 'no boundary change, saved under ' . $timezone);
        $loaded->save();
      });

      $this->assertSame($wallClock, $this->storedPair($id, 'daily_recurring_date'), "wall-clock pair after a re-save under $timezone");
      $this->assertSame($anchored, $this->storedPair($id, 'monthly_recurring_date'), "anchored pair after a re-save under $timezone");
      $this->assertSame($weekly, $this->storedPair($id, 'weekly_recurring_date'), "weekly rule pair after a re-save under $timezone");
    }
  }

  /**
   * A flagged writer's changed anchors are stored verbatim in any zone.
   *
   * The API controller normalizes boundaries itself and marks the entity;
   * its values are deliberate anchors, correct under any acting-user
   * timezone. Without the flag branch, this changed pair saved under
   * Pacific/Auckland (UTC+13 in October) would re-derive to Oct 1 / Oct 8.
   */
  public function testFlaggedWriterAnchorsAreStoredVerbatim(): void {
    $series = $this->makeFixtureSeries();
    $id = (int) $series->id();

    $this->asSaverIn('Pacific/Auckland', function () use ($id): void {
      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$id]);
      $loaded = $storage->load($id);
      $loaded->set('daily_recurring_date', [
        'value' => '2026-09-30T12:00:00',
        'end_value' => '2026-10-07T12:00:00',
      ]);
      $loaded->recurBoundariesNormalized = TRUE;
      $loaded->save();
    });

    [$storedValue, $storedEnd] = $this->storedPair($id, 'daily_recurring_date');
    $this->assertSame('2026-09-30T12:00:00', $storedValue);
    $this->assertSame('2026-10-07T12:00:00', $storedEnd);
  }

  /**
   * The unchanged-value no-op is PER COLUMN, not per item.
   *
   * Editing only a range's start must leave the untouched end column
   * byte-identical: a whole-item "anything changed, recover both" variant
   * would re-derive the unchanged anchored end through the acting user's
   * zone — under Pacific/Auckland (UTC+13 on 2026-11-30) ratcheting it to
   * `2026-12-01T12:00:00` — while the changed start still recovers.
   */
  public function testOnlyTheChangedColumnIsRecovered(): void {
    $series = $this->makeFixtureSeries();
    $id = (int) $series->id();
    $this->setRawBoundary($series, 'daily_recurring_date', '2026-06-01T12:00:00', '2026-11-30T12:00:00');

    $this->asSaverIn('Pacific/Auckland', function () use ($id): void {
      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$id]);
      $loaded = $storage->load($id);
      // An Auckland editor (NZST, UTC+12 in June) picks June 15 at a 09:00
      // wall clock; the widget stores the UTC instant. end_value is not
      // touched at all — it must ride through as the stored original.
      $loaded->get('daily_recurring_date')->first()->set('value', '2026-06-14T21:00:00');
      $loaded->save();
    });

    [$storedValue, $storedEnd] = $this->storedPair($id, 'daily_recurring_date');
    $this->assertSame('2026-06-15T12:00:00', $storedValue, 'the changed start column is recovered');
    $this->assertSame('2026-11-30T12:00:00', $storedEnd, 'the untouched end column is byte-identical, not re-derived');
  }

  /**
   * The initial save (no stored original) anchors the boundaries too.
   *
   * On create $this->original is unset, so the unchanged-value branch can
   * never match — every non-empty column is new input and must be
   * recovered. Pinned under an explicit far-east saver so the expected
   * bytes are deterministic: the fixture's seeded T00 weekly rule
   * (`2999-01-04T00:00:00`) converts through Auckland (NZDT, UTC+13 in
   * January) to a 13:00 wall clock the SAME day, anchoring `T12:00:00`
   * without moving the date.
   */
  public function testCreateSaveAnchorsTheSeededRuleWithoutAnOriginal(): void {
    $series = $this->asSaverIn('Pacific/Auckland', fn (): EventSeries => $this->makeFixtureSeries());

    [$storedValue, $storedEnd] = $this->storedPair((int) $series->id(), 'weekly_recurring_date');
    $this->assertSame('2999-01-04T12:00:00', $storedValue, 'create-save anchors the seeded start');
    $this->assertSame('2999-01-10T12:00:00', $storedEnd, 'create-save anchors the seeded end');
  }

  /**
   * A changed bare `YYYY-MM-DD` is anchored LITERALLY in every saver zone.
   *
   * A bare calendar date has no instant: parsing it as one injects the
   * current wall clock and shifts the date depending on the saver's zone
   * and the time of day the save happens to run. The stored date part must
   * equal the input's own date substring — same bytes under a UTC-5 and a
   * UTC+13 saver, whatever o'clock it is now.
   */
  public function testChangedBareDateIsAnchoredLiterally(): void {
    $series = $this->makeFixtureSeries();
    $id = (int) $series->id();

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      $this->saveBoundaryAs($timezone, $id, 'daily_recurring_date', '2026-10-01', '2026-10-15');

      [$storedValue, $storedEnd] = $this->storedPair($id, 'daily_recurring_date');
      $this->assertSame('2026-10-01T12:00:00', $storedValue, "start saved under $timezone");
      $this->assertSame('2026-10-15T12:00:00', $storedEnd, "end saved under $timezone");
      // The literal pin, spelled out: output date == input date substring.
      $this->assertSame('2026-10-01', substr((string) $storedValue, 0, 10), "date part is the input's own date under $timezone");
    }
  }

}
