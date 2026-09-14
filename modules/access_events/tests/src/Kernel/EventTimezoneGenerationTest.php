<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests that instance generation resolves the event's own timezone.
 *
 * Generation currently resolves its zone from date_default_timezone_get() —
 * the ambient zone of whoever's request is running — so a series authored in
 * one zone and later edited from another regenerates at a different instant.
 *
 * The zone enters generation at FOUR points, not one:
 *   1. the ~10 date getters on EventSeries (they setTime(0,0,0) after
 *      re-zoning, so the zone decides the calendar DATE);
 *   2. ConsecutiveRecurringDate::findSlotsBetweenTimes(), which builds its own
 *      ambient zone to compute the loop bound, so it decides the slot COUNT;
 *   3. YearlyRecurringDate::calculateInstances(), which reinterprets in
 *      ambient to filter by month, so it decides WHICH MONTHS exist;
 *   4. the exclusion hook, which parses its window with no timezone argument,
 *      so it decides WHETHER AN INSTANCE EXISTS.
 *
 * Each test below is shaped to fail on an implementation that fixes only some
 * of those, because the obvious weaker assertion passes a partial fix.
 *
 * @group access_events
 */
class EventTimezoneGenerationTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
    $this->seedGenerationDateFormats();
  }

  /**
   * Ensures the date_format config the instance pipeline formats through.
   */
  private function seedGenerationDateFormats(): void {
    foreach (['html_time' => 'H:i:s', 'html_date' => 'Y-m-d'] as $id => $pattern) {
      if (!\Drupal::entityTypeManager()->getStorage('date_format')->load($id)) {
        \Drupal::entityTypeManager()->getStorage('date_format')->create([
          'id' => $id,
          'label' => $id,
          'locked' => TRUE,
          'pattern' => $pattern,
        ])->save();
      }
    }
  }

  /**
   * Returns the stored UTC start values of a series' instances, in order.
   */
  private function instanceStarts(EventSeries $series): array {
    $ids = \Drupal::entityQuery('eventinstance')
      ->condition('eventseries_id', $series->id())
      ->accessCheck(FALSE)
      ->execute();
    $starts = [];
    foreach (\Drupal::entityTypeManager()->getStorage('eventinstance')->loadMultiple($ids) as $instance) {
      $starts[] = $instance->get('date')->value;
    }
    sort($starts);
    return $starts;
  }

  /**
   * Builds a weekly series in a given event zone, under a given ambient zone.
   */
  private function weeklySeriesUnderAmbient(string $eventZone, string $ambient): array {
    $original = date_default_timezone_get();
    date_default_timezone_set($ambient);
    try {
      $series = EventSeries::create([
        'title' => 'Weekly in ' . $eventZone,
        'type' => 'default',
        'recur_type' => 'weekly_recurring_date',
        'field_event_timezone' => $eventZone,
        'weekly_recurring_date' => [
          'value' => '2026-10-26T00:00:00',
          'end_value' => '2026-11-09T00:00:00',
          'time' => '02:00 PM',
          'end_time' => '03:00 PM',
          'duration' => 3600,
          'duration_or_end_time' => 'end_time',
          'days' => 'monday',
        ],
      ]);
      $series->save();
      return $this->instanceStarts($series);
    }
    finally {
      date_default_timezone_set($original);
    }
  }

  /**
   * (e) A DST-spanning series holds its local wall clock, so UTC shifts.
   *
   * Asserting only the local time would pass an implementation that pinned a
   * fixed offset instead of the IANA zone — the exact mistake the spec warns
   * against. So this asserts the stored UTC values differ across the
   * transition, which only an IANA zone produces.
   */
  public function testDstSpanningSeriesHoldsLocalWallClockAndShiftsUtc(): void {
    $starts = $this->weeklySeriesUnderAmbient('America/New_York', 'America/New_York');

    $this->assertNotEmpty($starts, 'the series generated instances');

    $locals = [];
    foreach ($starts as $utc) {
      $d = new \DateTime($utc, new \DateTimeZone('UTC'));
      $d->setTimezone(new \DateTimeZone('America/New_York'));
      $locals[] = $d->format('H:i');
    }
    $this->assertSame(['14:00'], array_values(array_unique($locals)),
      'every occurrence is 2:00 PM local, across the November transition');

    $utcTimes = array_map(
      static fn (string $v): string => substr($v, 11, 5),
      $starts
    );
    $this->assertGreaterThan(1, count(array_unique($utcTimes)),
      'and the stored UTC times differ, which only an IANA zone produces — a '
      . 'fixed offset would keep them identical');
  }

  /**
   * (a) The event's zone wins over the saving user's zone.
   *
   * Save the same series under three different ambient zones and assert the
   * generated instants are identical every time. Today they differ.
   */
  public function testGeneratedInstantsDoNotDependOnTheSavingUsersZone(): void {
    $underNy = $this->weeklySeriesUnderAmbient('America/Los_Angeles', 'America/New_York');
    $underLa = $this->weeklySeriesUnderAmbient('America/Los_Angeles', 'America/Los_Angeles');
    $underUtc = $this->weeklySeriesUnderAmbient('America/Los_Angeles', 'UTC');

    $this->assertSame($underLa, $underNy,
      'a Los Angeles event generates the same instants whoever saves it');
    $this->assertSame($underLa, $underUtc,
      'including from a UTC request, as cron and drush run');
  }

  /**
   * (d) Reading the zone twice on one entity must be idempotent.
   *
   * The getters return the entity's own cached date object rather than a copy,
   * and re-zoning it mutates in place. A single-call test passes a patch with
   * no clone; only calling twice catches the drift. This mirrors the live
   * double-read: recurring_events.module resolves the config for change
   * detection and again for instance creation in the same request.
   */
  public function testResolvingTheZoneRepeatedlyDoesNotDriftTheDate(): void {
    $series = EventSeries::create([
      'title' => 'Idempotence',
      'type' => 'default',
      'recur_type' => 'weekly_recurring_date',
      'field_event_timezone' => 'America/New_York',
      'weekly_recurring_date' => [
        'value' => '2026-10-26T00:00:00',
        'end_value' => '2026-11-09T00:00:00',
        'time' => '02:00 PM',
        'end_time' => '03:00 PM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
        'days' => 'monday',
      ],
    ]);
    $series->save();

    $storedBefore = $series->get('weekly_recurring_date')->value;

    $first = $series->getWeeklyStartDate()->format('Y-m-d');
    $series->getWeeklyEndDate();
    $third = $series->getWeeklyStartDate()->format('Y-m-d');

    $this->assertSame($first, $third,
      'resolving the start date twice yields the same calendar date');
    $this->assertSame($storedBefore, $series->get('weekly_recurring_date')->value,
      'and the stored column is untouched by reading it');
  }

  /**
   * (f) An empty timezone field generates exactly as today.
   *
   * The field is empty on every existing series until the backfill runs, so
   * the fallback path is what actually executes in production on day one. If
   * it is not byte-identical to today's behaviour, every series moves on its
   * next regeneration.
   */
  public function testEmptyTimezoneFallsBackToAmbientUnchanged(): void {
    $original = date_default_timezone_get();
    date_default_timezone_set('America/Chicago');
    try {
      $series = EventSeries::create([
        'title' => 'No zone set',
        'type' => 'default',
        'recur_type' => 'weekly_recurring_date',
        'weekly_recurring_date' => [
          'value' => '2026-10-26T00:00:00',
          'end_value' => '2026-11-09T00:00:00',
          'time' => '02:00 PM',
          'end_time' => '03:00 PM',
          'duration' => 3600,
          'duration_or_end_time' => 'end_time',
          'days' => 'monday',
        ],
      ]);
      $series->save();
      $starts = $this->instanceStarts($series);
    }
    finally {
      date_default_timezone_set($original);
    }

    $this->assertNotEmpty($starts, 'a series with no zone still generates');
    foreach ($starts as $utc) {
      $d = new \DateTime($utc, new \DateTimeZone('UTC'));
      $d->setTimezone(new \DateTimeZone('America/Chicago'));
      $this->assertSame('14:00', $d->format('H:i'),
        'and it generates in the ambient zone, exactly as before this change');
    }
  }

  /**
   * (f2) A malformed timezone value must not abort the save.
   *
   * The resolver runs inside the insert/update hook, and
   * new \DateTimeZone('Not/AZone') throws. An unhandled throw there takes the
   * whole save down, so the fallback has to key off validity rather than
   * emptiness — a whitespace-only string is non-empty and still invalid.
   */
  public function testMalformedTimezoneFallsBackRatherThanThrowing(): void {
    foreach (['', '   ', 'Not/AZone'] as $bad) {
      $series = EventSeries::create([
        'title' => 'Bad zone: ' . $bad,
        'type' => 'default',
        'recur_type' => 'weekly_recurring_date',
        'field_event_timezone' => $bad,
        'weekly_recurring_date' => [
          'value' => '2026-10-26T00:00:00',
          'end_value' => '2026-11-09T00:00:00',
          'time' => '02:00 PM',
          'end_time' => '03:00 PM',
          'duration' => 3600,
          'duration_or_end_time' => 'end_time',
          'days' => 'monday',
        ],
      ]);
      $series->save();

      $this->assertNotEmpty($this->instanceStarts($series),
        sprintf('a series with timezone %s still saves and generates', var_export($bad, TRUE)));
    }
  }

}
