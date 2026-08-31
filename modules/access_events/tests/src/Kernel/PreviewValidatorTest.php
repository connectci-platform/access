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

}
