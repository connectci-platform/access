<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;

/**
 * The pre-compute recurrence-config validator.
 *
 * EffectiveCreationSet::validateConfig() is a crash-guard run BEFORE
 * compute() materializes a series' full date set. compute()'s own
 * calculateInstances() call trusts its config to be fully populated and
 * dereferences a fixed set of keys unconditionally: an empty weekly `days`
 * list is a foreach-over-null fatal, a `monthday` branch with no
 * `day_of_month` is an unguarded foreach fatal, and a `consecutive` config
 * whose duration+buffer step is non-positive (or whose units are an invalid
 * modify() phrase like "second ago") makes findSlotsBetweenTimes() loop
 * forever. calculateInstances() also materializes every occurrence before
 * any output cap can slice it, so an unbounded span/element count is a
 * memory DoS. validateConfig() rejects all of these up front with a
 * human-readable string, and — critically — returns NULL for any config
 * that is merely legitimate-but-empty or fully valid: it is a crash-guard,
 * not a re-implementation of contrib's date semantics, so it must never
 * over-reject.
 *
 * These tests call validateConfig() DIRECTLY on an unsaved EventSeries
 * (the controller wiring that turns a non-null return into a 422 is a
 * separate task) — build the series, call the method, assert string-or-null.
 *
 * @group access_events
 */
class PreviewValidatorTest extends EventKernelTestBase {

  /**
   * The service under test.
   */
  private function validator(): \Drupal\access_events\EffectiveCreationSet {
    $validator = \Drupal::service('access_events.effective_creation_set');
    assert($validator instanceof \Drupal\access_events\EffectiveCreationSet);
    return $validator;
  }

  /**
   * Builds an UNSAVED weekly series carrying the given recur config.
   *
   * The series is never saved: validateConfig() runs off
   * convertEntityConfigToArray($series), which reads the recur field
   * directly and needs no persisted row. Not saving also means a malformed
   * config never has to survive contrib's own save-time instance spawning
   * (which would itself crash on exactly the inputs under test).
   */
  private function weeklySeries(array $recur): EventSeries {
    return EventSeries::create([
      'title' => 'Weekly',
      'type' => 'default',
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $recur,
    ]);
  }

  /**
   * A well-formed weekly recur config over a bounded, future window.
   */
  private function validWeeklyConfig(): array {
    return [
      'value' => '2999-01-04T00:00:00',
      'end_value' => '2999-01-31T00:00:00',
      'time' => '10:00 AM',
      'end_time' => '11:00 AM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => 'monday,wednesday',
    ];
  }

  /**
   * Builds an UNSAVED custom series carrying $count date rows.
   *
   * Each row is a distinct future daterange; the series is never saved, so
   * validateConfig() reads the custom_date field directly via
   * convertEntityConfigToArray() without contrib's save-time instance
   * spawning (which would itself materialize one instance per row).
   */
  private function customSeries(int $count): EventSeries {
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
      // Space rows a day apart so each is a distinct, valid range.
      $day = 1 + ($i % 27);
      $month = 1 + intdiv($i, 27);
      $year = 2999 + intdiv($month, 12);
      $month = 1 + ($month % 12);
      $date = sprintf('%04d-%02d-%02dT10:00:00', $year, $month, $day);
      $end = sprintf('%04d-%02d-%02dT11:00:00', $year, $month, $day);
      $rows[] = ['value' => $date, 'end_value' => $end];
    }
    return EventSeries::create([
      'title' => 'Custom',
      'type' => 'default',
      'recur_type' => 'custom',
      'custom_date' => $rows,
    ]);
  }

  /**
   * Case 1: a weekly config with an empty days list is rejected.
   *
   * WeeklyRecurringDate::calculateInstances() does `foreach
   * ($form_data['days'] ...)` with no guard — an empty days list is a fatal,
   * so the validator must reject it before compute() runs.
   */
  public function testWeeklyEmptyDaysRejected(): void {
    $config = $this->validWeeklyConfig();
    $config['days'] = '';
    $error = $this->validator()->validateConfig($this->weeklySeries($config));
    $this->assertIsString($error);
  }

  /**
   * Case 2: a monthday config missing day_of_month is rejected.
   *
   * MonthlyRecurringDate::calculateInstances()'s monthday branch does an
   * UNGUARDED `foreach ($form_data['day_of_month'] ...)` — the
   * highest-likelihood hard crash.
   */
  public function testMonthlyMonthdayMissingDayOfMonthRejected(): void {
    $series = EventSeries::create([
      'title' => 'Monthly',
      'type' => 'default',
      'recur_type' => 'monthly_recurring_date',
      'monthly_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        'end_value' => '2999-06-01T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
        'type' => 'monthday',
        // day_of_month deliberately absent.
      ],
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
  }

  /**
   * Case 3: an unparseable start date is rejected, not fataled.
   *
   * Proves the try/catch around date handling: a garbage date must yield a
   * validator string, never a 500 (createFromFormat/DateTimePlus THROWS on
   * bad input rather than returning FALSE).
   */
  public function testGarbageStartDateRejected(): void {
    $config = $this->validWeeklyConfig();
    $config['value'] = 'not-a-date';
    $error = $this->validator()->validateConfig($this->weeklySeries($config));
    $this->assertIsString($error);
  }

  /**
   * Case 4: a consecutive config whose duration+buffer < 15 min is rejected.
   *
   * findSlotsBetweenTimes() steps the cursor by duration+buffer each loop; a
   * sub-15-minute (here zero) net step is the infinite-loop DoS this floor
   * guards.
   */
  public function testConsecutiveSubFloorStepRejected(): void {
    $series = EventSeries::create([
      'title' => 'Consecutive',
      'type' => 'default',
      'recur_type' => 'consecutive_recurring_date',
      'consecutive_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        'end_value' => '2999-01-02T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 0,
        'duration_units' => 'minutes',
        'buffer' => 0,
        'buffer_units' => 'minutes',
      ],
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
  }

  /**
   * Case 5: a consecutive config with an off-whitelist unit is rejected.
   *
   * `buffer_units: "second ago"` is concatenated raw into DateTime::modify();
   * a negative-direction phrase makes the loop never terminate. The unit
   * whitelist rejects anything not in the plain positive-unit enum.
   */
  public function testConsecutiveBadUnitRejected(): void {
    $series = EventSeries::create([
      'title' => 'Consecutive',
      'type' => 'default',
      'recur_type' => 'consecutive_recurring_date',
      'consecutive_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        'end_value' => '2999-01-02T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 30,
        'duration_units' => 'minutes',
        'buffer' => 5,
        'buffer_units' => 'second ago',
      ],
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
  }

  /**
   * Case 6: a span exceeding the max is rejected.
   *
   * A multi-year window over a dense recurrence materializes an unbounded
   * date set before any output cap; the span bound is the memory-DoS floor.
   */
  public function testOverlongSpanRejected(): void {
    $config = $this->validWeeklyConfig();
    $config['value'] = '2999-01-01T00:00:00';
    // ~5 years — comfortably past the ~2-year cap.
    $config['end_value'] = '3004-01-01T00:00:00';
    $error = $this->validator()->validateConfig($this->weeklySeries($config));
    $this->assertIsString($error);
  }

  /**
   * Case 7 (regression guard): a fully valid weekly config returns NULL.
   *
   * The validator is a crash-guard, not a semantics re-implementation. A
   * well-formed config must pass — over-rejection is a failure.
   */
  public function testValidWeeklyConfigPasses(): void {
    $error = $this->validator()->validateConfig($this->weeklySeries($this->validWeeklyConfig()));
    $this->assertNull($error);
  }

  /**
   * Case 8: a valid config whose dates are all in the past returns NULL.
   *
   * An empty computed set (every occurrence filtered as past) is legitimate,
   * not malformed — the validator only rejects configs that would CRASH
   * compute(), never ones that merely produce zero future dates.
   */
  public function testValidButAllPastConfigPasses(): void {
    $config = $this->validWeeklyConfig();
    $config['value'] = '2000-01-03T00:00:00';
    $config['end_value'] = '2000-01-31T00:00:00';
    $error = $this->validator()->validateConfig($this->weeklySeries($config));
    $this->assertNull($error);
  }

  /**
   * Product cap: a full-day 15-minute consecutive over max span is rejected.
   *
   * Every per-dimension bound passes (span = max, net step = the 15-min floor,
   * all keys present, units whitelisted), yet the PRODUCT is enormous:
   * ceil(731) days x ceil(1440/15)=96 slots/day = ~70,176 occurrences. The
   * estimate exceeds MAX_ESTIMATED_OCCURRENCES, so it gets a clean reject
   * rather than a large materialization.
   */
  public function testConsecutiveProductBlowupRejected(): void {
    $series = EventSeries::create([
      'title' => 'Consecutive',
      'type' => 'default',
      'recur_type' => 'consecutive_recurring_date',
      'consecutive_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        // Max span (~2 years).
        'end_value' => '3000-12-31T00:00:00',
        // Full day window.
        'time' => '12:00 AM',
        'end_time' => '11:59 PM',
        // Net step exactly at the 15-min floor.
        'duration' => 15,
        'duration_units' => 'minutes',
        'buffer' => 0,
        'buffer_units' => 'minutes',
      ],
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
    $this->assertStringContainsString('more than', $error);
  }

  /**
   * A weekly maxed on tokens over max span stays UNDER the product cap.
   *
   * Per the specified estimate (ceil(spanDays/7) * count(days)) a weekly is
   * bounded by the 31-element multiplier cap: at most ceil(731/7)=105 weeks x
   * 31 tokens = ~3,255 occurrences, which is below MAX_ESTIMATED_OCCURRENCES
   * (5000). So the multiplier-element cap already bounds a weekly's product;
   * the estimate backstop's real teeth are on the consecutive type (the only
   * minute-granular one). This pins that a maxed weekly is NOT over-rejected —
   * D4's truncation handles the merely-large 1000-5000 band.
   */
  public function testWeeklyMaxTokensUnderProductCap(): void {
    $config = $this->validWeeklyConfig();
    $config['value'] = '2999-01-01T00:00:00';
    $config['end_value'] = '3000-12-31T00:00:00';
    // 31 day-tokens (the multiplier-element cap).
    $config['days'] = implode(',', array_fill(0, 31, 'monday'));
    $error = $this->validator()->validateConfig($this->weeklySeries($config));
    $this->assertNull($error);
  }

  /**
   * A large-but-under-5000 config (daily over ~2 years) is NOT rejected.
   *
   * Daily over the max span is ~731 occurrences — well past D4's 1000-row
   * output cap is not even reached, and comfortably under the 5000 estimate
   * cap, so the product backstop must let it preview. Over-rejecting
   * merely-large data (which D4 truncates-with-a-flag) is a failure.
   */
  public function testLargeUnderCapDailyPasses(): void {
    $series = EventSeries::create([
      'title' => 'Daily',
      'type' => 'default',
      'recur_type' => 'daily_recurring_date',
      'daily_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        'end_value' => '3000-12-31T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
      ],
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertNull($error);
  }

  /**
   * Custom count cap: more than MAX_ESTIMATED_OCCURRENCES dates is rejected.
   *
   * A 'custom' recurrence returns early from validateConfig(), bypassing the
   * span check and the estimate backstop, so without its own count cap N
   * custom dates materialize ~2N DrupalDateTime objects bounded only by
   * post_max_size. The direct length compare closes that hole.
   */
  public function testCustomDatesOverCapRejected(): void {
    $series = $this->customSeries(self::occurrenceCap() + 1);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
    $this->assertStringContainsString('more than', $error);
  }

  /**
   * A normal handful of custom dates is NOT rejected.
   *
   * The count cap is an abuse backstop, not a workflow limit — an ordinary
   * multi-date custom event previews cleanly.
   */
  public function testCustomDatesNormalCountPasses(): void {
    $series = $this->customSeries(5);
    $error = $this->validator()->validateConfig($series);
    $this->assertNull($error);
  }

  /**
   * The occurrence cap constant, read off the class under test.
   */
  private static function occurrenceCap(): int {
    return \Drupal\access_events\EffectiveCreationSet::MAX_ESTIMATED_OCCURRENCES;
  }

  /**
   * A multiplier array over MAX_MULTIPLIER_ELEMENTS (31) is rejected.
   *
   * validateMultiplier caps days / day_of_month / day_occurrence element count;
   * 32 weekly day-tokens is over the cap. Pins the reject (only the pass-at-cap
   * case existed).
   */
  public function testWeeklyDaysOverElementCapRejected(): void {
    $config = $this->validWeeklyConfig();
    // 32 tokens — one past MAX_MULTIPLIER_ELEMENTS = 31.
    $config['days'] = implode(',', array_fill(0, 32, 'monday'));
    $error = $this->validator()->validateConfig($this->weeklySeries($config));
    $this->assertIsString($error);
  }

  /**
   * A monthday config with an out-of-range day_of_month is rejected.
   *
   * Each day_of_month element must be -1 or in 1..31; a 0 or 32 (here 32) is
   * neither, so validateMonthly rejects it. Pins the range guard.
   */
  public function testMonthlyDayOfMonthOutOfRangeRejected(): void {
    $series = EventSeries::create([
      'title' => 'Monthly',
      'type' => 'default',
      'recur_type' => 'monthly_recurring_date',
      'monthly_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        'end_value' => '2999-06-01T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
        'type' => 'monthday',
        // 32 is out of range (not -1, not 1..31).
        'day_of_month' => '32',
      ],
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
  }

  /**
   * An unknown recur_type is rejected via the getDefinition gate.
   *
   * A recur_type with no field-type plugin (quarterly_recurring_date) makes
   * getDefinition() throw PluginNotFoundException; validateConfig catches it
   * and returns "Unknown recur_type", so it never reaches
   * convertEntityConfigToArray(). Pins that gate.
   */
  public function testUnknownRecurTypeRejected(): void {
    $series = EventSeries::create([
      'title' => 'Quarterly',
      'type' => 'default',
      'recur_type' => 'quarterly_recurring_date',
    ]);
    $error = $this->validator()->validateConfig($series);
    $this->assertIsString($error);
    $this->assertStringContainsString('Unknown recur_type', $error);
  }

}
