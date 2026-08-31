<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventCreationService;

/**
 * Computes the set of dates a series' CURRENT recur config would produce.
 *
 * EventCreationService::createInstances() is the only place recurring_events
 * itself computes a series' full date set (for both the 'custom' branch and
 * every rule-based recur type), but it does so as one inseparable step with
 * actually CREATING AND SAVING eventinstance entities — there is no
 * dates-only read path in contrib. This class replays just the
 * date-computation half: convertEntityConfigToArray() + the recur type's
 * field-plugin calculateInstances() (or the raw custom_dates list for a
 * 'custom' series), fires the same recurring_events_event_instances_pre_create
 * alter contrib fires (so any module reacting to that alter — including this
 * one's own PastPreservingEventInstanceCreator/past-date filtering) sees an
 * identical computed set — and returns the dates without persisting
 * anything.
 *
 * Two additions on top of the raw contrib computation:
 *  - Messenger snapshot/restore: convertEntityConfigToArray()'s callees are
 *    silent, but firing the pre_create alter with a series that is not
 *    actually being created can cause an alter implementation (this module's
 *    own included, since it does not distinguish computation from a real
 *    rebuild) to add a status/warning message meant for a real save. A
 *    dates-only computation must never leak a message onto the current
 *    request; the messenger queue is snapshotted before the alter fires and
 *    restored immediately after, so any message added during compute() is
 *    discarded rather than surfaced to whichever page happens to trigger it.
 *  - Future-only filter: same not-verifiably-past boundary as
 *    RegistrantCounter::endIsNotVerifiablyPast() / PastPreservingEventInstance
 *    Creator::hasEnded() — a past date is dropped from the effective set.
 *
 * FLAGGED-DATE FILTER: filterFlaggedDates() is the ONE function both this
 * class and access_events_recurring_events_event_instances_pre_create_alter()
 * call to drop any computed date whose start exactly matches a preserved
 * flagged (individually_cancelled) instance's start — one function, not two
 * independent derivations of "does this date collide with a flagged
 * instance", so the helper (this class) and the alter (the actual rebuild
 * path) can never drift out of agreement about what counts as a collision.
 */
class EffectiveCreationSet {

  /**
   * Maximum span (in days) between a recurrence's start and end date.
   *
   * calculateInstances() materializes EVERY occurrence in the window before
   * any output cap can slice it (compute() builds the full array first), so an
   * unbounded span over a dense recurrence is a memory-DoS vector. ~2 years.
   */
  public const MAX_SPAN_DAYS = 731;

  /**
   * Minimum net step (in minutes) for a consecutive recurrence's slot loop.
   *
   * ConsecutiveRecurringDate::findSlotsBetweenTimes() advances its cursor by
   * duration + buffer each iteration; a non-positive net step never
   * terminates. This floor is the load-bearing bound for the only
   * minute-granular recur type.
   */
  public const MIN_CONSECUTIVE_SLOT_MINUTES = 15;

  /**
   * Maximum element count for a multiplier array (days / day_of_month / …).
   *
   * calculateInstances() multiplies the occurrence count by the size of these
   * arrays; capping them bounds the materialized set alongside the span cap.
   */
  public const MAX_MULTIPLIER_ELEMENTS = 31;

  /**
   * Maximum estimated occurrence count a config may preview.
   *
   * The per-dimension bounds (span, multiplier element count, the consecutive
   * slot floor) each cap one factor but never their PRODUCT: a config that
   * clears all of them can still describe a large-but-finite set (a full-day
   * 15-minute consecutive over the max span is ~70,000 occurrences; a weekly
   * with 31 day-tokens over the max span is ~3,200). That is no hang and no
   * OOM, but a repeatable expensive materialization for any authenticated user
   * and legitimate-looking data D4 would be forced to truncate. This is a
   * small multiple (~5x) of D4's 1000-row OUTPUT cap: a config D4 would merely
   * truncate-with-a-flag (up to a few thousand) still previews, while a
   * pathological product-blowup gets a clean 422 instead of a large
   * allocation. An abuse backstop, deliberately generous, NOT a
   * real-workflow limit.
   */
  public const MAX_ESTIMATED_OCCURRENCES = 5000;

  /**
   * Whitelist of duration/buffer unit phrases accepted by a consecutive rule.
   *
   * The units are concatenated RAW into DrupalDateTime::modify(); anything
   * outside this positive-direction enum (notably signed forms like
   * "second ago") can drive the slot loop non-terminating.
   */
  public const ALLOWED_UNITS = [
    'second', 'seconds',
    'minute', 'minutes',
    'hour', 'hours',
    'day', 'days',
    'week', 'weeks',
    'month', 'months',
    'year', 'years',
  ];

  /**
   * The number of seconds each whitelisted unit represents (for the floor).
   */
  private const UNIT_SECONDS = [
    'second' => 1,
    'seconds' => 1,
    'minute' => 60,
    'minutes' => 60,
    'hour' => 3600,
    'hours' => 3600,
    'day' => 86400,
    'days' => 86400,
    'week' => 604800,
    'weeks' => 604800,
    // month/year are variable-length; use conservative lower bounds so the
    // 15-minute floor can never be under-counted into a false pass.
    'month' => 2419200,
    'months' => 2419200,
    'year' => 31536000,
    'years' => 31536000,
  ];

  public function __construct(
    protected EventCreationService $eventCreationService,
    protected FieldTypePluginManagerInterface $fieldTypePluginManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected MessengerInterface $messenger,
    protected TimeInterface $time,
  ) {}

  /**
   * Pre-compute crash-guard for a series' recurrence config.
   *
   * compute() delegates each rule type to its contrib field plugin's
   * calculateInstances(), which trusts its config to be fully populated: it
   * dereferences a fixed set of keys unconditionally (an empty weekly `days`
   * is a foreach-over-null fatal; a `monthday` branch with no `day_of_month`
   * is an unguarded foreach fatal), it steps a consecutive recurrence's slot
   * loop by duration + buffer with no sign/zero guard (a non-positive net step
   * never terminates), and it materializes the ENTIRE occurrence set before
   * any caller can slice it (a multi-year span over a dense recurrence is a
   * memory DoS). This method runs BEFORE compute() and rejects those inputs
   * with a human-readable string; the controller turns a non-null return into
   * a 422.
   *
   * It validates the CONVERTED config (convertEntityConfigToArray) — the exact
   * array compute() feeds calculateInstances() — not the raw request body, so
   * it checks the round-tripped keys (monthly_type, day_of_month as an array,
   * start_date/end_date as DrupalDateTime objects) rather than the widget's
   * pre-conversion shape.
   *
   * It is a crash-guard, NOT a re-implementation of contrib's date semantics:
   * a config that is merely legitimate-but-empty (all dates past) or fully
   * valid returns NULL. Over-rejection is a bug.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The (unsaved) series whose current recur config is validated.
   *
   * @return string|null
   *   A human-readable error when the config would crash compute(); NULL when
   *   it is well-formed enough to compute.
   */
  public function validateConfig(EventSeries $series): ?string {
    $recurType = $series->getRecurType();
    if (!$recurType) {
      // No recurrence configured yet: nothing to compute, nothing to crash on.
      return NULL;
    }

    // The authoritative recur-type validity gate: every rule type must resolve
    // to a field-type plugin; 'custom' is the one type that bypasses the
    // plugin manager. An unknown type would throw PluginNotFoundException from
    // convertEntityConfigToArray() below, so reject it here with a clear
    // message rather than let that surface as a fatal.
    if ($recurType !== 'custom') {
      try {
        $this->fieldTypePluginManager->getDefinition($recurType);
      }
      catch (\Throwable $e) {
        return sprintf('Unknown recur_type "%s".', $recurType);
      }
    }

    // Validate the CONVERTED config — the same array compute() computes over.
    // convertEntityConfigToArray() trusts its field shapes: a caller sending a
    // multiplier (weekly `days`, monthly `day_of_month`/`day_occurrence`) as a
    // JSON ARRAY rather than the expected comma-string makes contrib's
    // explode(',', $array) throw a TypeError inside the convert. That would be
    // an uncaught 500 (a bare framework error), so treat any conversion throw
    // as a malformed-config rejection — the same fail-safe calculateDates()
    // applies around its own contrib call.
    try {
      $config = $this->eventCreationService->convertEntityConfigToArray($series);
    }
    catch (\Throwable $e) {
      return 'The recurrence configuration is malformed.';
    }

    if ($recurType === 'custom') {
      return $this->validateCustom($config);
    }

    // Every rule type shares the same span bound.
    $spanError = $this->validateSpan($config);
    if ($spanError !== NULL) {
      return $spanError;
    }

    $typeError = match ($recurType) {
      'daily_recurring_date' => $this->validateDaily($config),
      'weekly_recurring_date' => $this->validateWeekly($config),
      // Yearly inherits monthly's entire crash surface (its
      // calculateInstances() calls the monthly parent first).
      'monthly_recurring_date', 'yearly_recurring_date' => $this->validateMonthly($config),
      'consecutive_recurring_date' => $this->validateConsecutive($config),
      // A recognized plugin whose validator is not enumerated above has no
      // known unconditional-deref crash surface: fail open rather than
      // over-reject.
      default => NULL,
    };
    if ($typeError !== NULL) {
      return $typeError;
    }

    // Product backstop: the per-dimension bounds above each cap one factor
    // (span, multiplier element count, the consecutive slot floor) but never
    // their PRODUCT. A config that clears them all can still describe a
    // large-but-finite set — cheaply upper-bound the occurrence count from the
    // now-validated bounds (integer arithmetic only; never calls compute()/
    // calculateInstances, the expensive step this guards) and reject a
    // pathological product-blowup.
    if ($this->estimateOccurrences($recurType, $config) > self::MAX_ESTIMATED_OCCURRENCES) {
      return sprintf('This recurrence would produce more than %d occurrences; narrow the date range or interval.', self::MAX_ESTIMATED_OCCURRENCES);
    }

    return NULL;
  }

  /**
   * Cheap upper-bound estimate of a validated config's occurrence count.
   *
   * Deliberately over-estimates from the already-validated bounds using only
   * integer arithmetic — no date iteration, no calculateInstances() call. Runs
   * ONLY after the per-type validation has passed, so start_date/end_date are
   * present DrupalDateTime values and the multiplier/consecutive fields are
   * well-formed. An unrecognized type returns 0 (the fail-open default types
   * have no known crash/blowup surface).
   *
   * @param string $recurType
   *   The recur-type plugin id.
   * @param array<string, mixed> $config
   *   The converted, already-validated recurrence config.
   */
  private function estimateOccurrences(string $recurType, array $config): int {
    $spanDays = $this->spanDays($config);

    return match ($recurType) {
      'daily_recurring_date' => (int) ceil($spanDays),
      'weekly_recurring_date' => (int) ceil($spanDays / 7) * count($config['days']),
      'monthly_recurring_date' => (int) ceil($spanDays / 30) * $this->monthlyMultiplier($config),
      // Yearly reuses the monthly per-period multiplier, applied per year.
      'yearly_recurring_date' => (int) ceil($spanDays / 365) * $this->monthlyMultiplier($config),
      'consecutive_recurring_date' => (int) ceil($spanDays) * $this->consecutiveSlotsPerDay($config),
      default => 0,
    };
  }

  /**
   * The span in days between the validated start and end dates.
   *
   * @param array<string, mixed> $config
   *   The converted, already-validated recurrence config.
   */
  private function spanDays(array $config): float {
    $start = $config['start_date'];
    $end = $config['end_date'];
    assert($start instanceof DrupalDateTime && $end instanceof DrupalDateTime);
    return ($end->getTimestamp() - $start->getTimestamp()) / 86400;
  }

  /**
   * The per-period occurrence multiplier for a monthly/yearly config.
   *
   * `monthday` multiplies by the day_of_month count; `weekday` by
   * day_occurrence x days. Reads only fields the monthly validator has already
   * confirmed present and non-empty for the active branch.
   *
   * @param array<string, mixed> $config
   *   The converted, already-validated recurrence config.
   */
  private function monthlyMultiplier(array $config): int {
    if (($config['monthly_type'] ?? NULL) === 'monthday') {
      return count($config['day_of_month']);
    }
    if (($config['monthly_type'] ?? NULL) === 'weekday') {
      return count($config['day_occurrence']) * count($config['days']);
    }
    return 1;
  }

  /**
   * Upper-bound slots-per-day for a validated consecutive config.
   *
   * slotsPerDay = ceil(windowMinutes / netStepMinutes). The window is
   * (end_time - time); a zero/negative or unparseable window is treated as a
   * full day (1440 min) so the estimate stays an upper bound rather than
   * collapsing to zero. netStepMinutes reuses the same duration+buffer sum the
   * floor check validated (>= MIN_CONSECUTIVE_SLOT_MINUTES, so never zero).
   *
   * @param array<string, mixed> $config
   *   The converted, already-validated recurrence config.
   */
  private function consecutiveSlotsPerDay(array $config): int {
    $netStepMinutes = (
      ((int) $config['duration']) * self::UNIT_SECONDS[$config['duration_units']]
      + ((int) $config['buffer']) * self::UNIT_SECONDS[$config['buffer_units']]
    ) / 60;

    $windowMinutes = $this->timeWindowMinutes($config['time'] ?? NULL, $config['end_time'] ?? NULL);
    if ($windowMinutes === NULL || $windowMinutes <= 0) {
      // A full day is the widest a single day's slots can span.
      $windowMinutes = 1440;
    }

    return (int) ceil($windowMinutes / $netStepMinutes);
  }

  /**
   * Minutes between two `h:i a` / `H:i` time-of-day strings, or NULL.
   *
   * @param mixed $start
   *   The start time-of-day string (converted config `time`).
   * @param mixed $end
   *   The end time-of-day string (converted config `end_time`).
   */
  private function timeWindowMinutes(mixed $start, mixed $end): ?int {
    $startMinutes = $this->minutesOfDay($start);
    $endMinutes = $this->minutesOfDay($end);
    if ($startMinutes === NULL || $endMinutes === NULL) {
      return NULL;
    }
    return $endMinutes - $startMinutes;
  }

  /**
   * Parses a time-of-day string to minutes-since-midnight, or NULL.
   *
   * The converted config carries times as 12-hour `h:i a` (upper-cased, e.g.
   * "10:00 AM"); strtotime() over a bare time on the current day yields a
   * comparable minutes-of-day value. Failure returns NULL so the caller falls
   * back to a full-day window rather than a wrong estimate.
   *
   * @param mixed $value
   *   The time-of-day string.
   */
  private function minutesOfDay(mixed $value): ?int {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    $timestamp = strtotime($value . ' UTC', 0);
    if ($timestamp === FALSE) {
      return NULL;
    }
    // $timestamp is seconds since the epoch's midnight (base 0), so it already
    // is seconds-of-day for a bare time.
    return intdiv($timestamp % 86400, 60);
  }

  /**
   * Validates a 'custom' recurrence: a non-empty list of parseable ranges.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateCustom(array $config): ?string {
    $dates = $config['custom_dates'] ?? [];
    if (!is_array($dates) || $dates === []) {
      return 'A custom recurrence needs at least one date.';
    }
    // A 'custom' recurrence returns early from validateConfig(), before the
    // span check and the estimate backstop — and its occurrence count is the
    // custom_dates length directly, so those quantitative bounds never see it.
    // Materializing ~2N DrupalDateTime objects is otherwise bounded only by
    // post_max_size. Cap it by the same occurrence ceiling as every other
    // type — a direct length compare, since custom is an exact count, not an
    // estimate.
    if (count($dates) > self::MAX_ESTIMATED_OCCURRENCES) {
      return sprintf('This event has more than %d dates; reduce the number of dates.', self::MAX_ESTIMATED_OCCURRENCES);
    }
    foreach ($dates as $range) {
      if (!$this->isValidDate($range['start_date'] ?? NULL) || !$this->isValidDate($range['end_date'] ?? NULL)) {
        return 'Every custom date needs a valid start and end.';
      }
    }
    return NULL;
  }

  /**
   * Validates the keys DailyRecurringDate::calculateInstances() dereferences.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateDaily(array $config): ?string {
    $dateError = $this->validateStartEnd($config);
    if ($dateError !== NULL) {
      return $dateError;
    }
    if (empty($config['time'])) {
      return 'A daily recurrence needs a start time.';
    }
    return $this->validateDurationOrEndTime($config);
  }

  /**
   * Validates the keys WeeklyRecurringDate::calculateInstances() dereferences.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateWeekly(array $config): ?string {
    $dateError = $this->validateStartEnd($config);
    if ($dateError !== NULL) {
      return $dateError;
    }
    $daysError = $this->validateMultiplier($config['days'] ?? NULL, 'A weekly recurrence needs at least one day of the week.');
    if ($daysError !== NULL) {
      return $daysError;
    }
    if (empty($config['time'])) {
      return 'A weekly recurrence needs a start time.';
    }
    return $this->validateDurationOrEndTime($config);
  }

  /**
   * Validates the keys the monthly (and inherited yearly) branch dereferences.
   *
   * Branches on the CONVERTED `monthly_type` (not the raw body's `type`):
   * `monthday` foreaches `day_of_month` unguarded, `weekday` needs
   * `day_occurrence` + `days`.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateMonthly(array $config): ?string {
    $dateError = $this->validateStartEnd($config);
    if ($dateError !== NULL) {
      return $dateError;
    }
    if (empty($config['time'])) {
      return 'A monthly recurrence needs a start time.';
    }

    $monthlyType = $config['monthly_type'] ?? NULL;
    if ($monthlyType === 'monthday') {
      $error = $this->validateMultiplier($config['day_of_month'] ?? NULL, 'A monthly (by day-of-month) recurrence needs at least one day of the month.');
      if ($error !== NULL) {
        return $error;
      }
      foreach ($config['day_of_month'] as $day) {
        $day = (int) $day;
        if ($day !== -1 && ($day < 1 || $day > 31)) {
          return 'Each day of the month must be -1 or between 1 and 31.';
        }
      }
    }
    elseif ($monthlyType === 'weekday') {
      $occError = $this->validateMultiplier($config['day_occurrence'] ?? NULL, 'A monthly (by weekday) recurrence needs at least one week occurrence.');
      if ($occError !== NULL) {
        return $occError;
      }
      $daysError = $this->validateMultiplier($config['days'] ?? NULL, 'A monthly (by weekday) recurrence needs at least one day of the week.');
      if ($daysError !== NULL) {
        return $daysError;
      }
    }
    else {
      return 'A monthly recurrence needs a monthly_type of "monthday" or "weekday".';
    }

    return $this->validateDurationOrEndTime($config);
  }

  /**
   * Validates the consecutive keys, the unit whitelist, and the slot floor.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateConsecutive(array $config): ?string {
    $dateError = $this->validateStartEnd($config);
    if ($dateError !== NULL) {
      return $dateError;
    }
    if (empty($config['time']) || empty($config['end_time'])) {
      return 'A consecutive recurrence needs a start and end time.';
    }

    foreach (['duration', 'buffer'] as $key) {
      if (!array_key_exists($key, $config)) {
        return sprintf('A consecutive recurrence needs a %s.', $key);
      }
      $value = $config[$key];
      if (!is_numeric($value) || (int) $value != $value || (int) $value < 0) {
        return sprintf('The consecutive %s must be a non-negative whole number.', $key);
      }
    }

    foreach (['duration_units', 'buffer_units'] as $key) {
      $unit = $config[$key] ?? NULL;
      if (!is_string($unit) || !in_array($unit, self::ALLOWED_UNITS, TRUE)) {
        return sprintf('The consecutive %s must be one of: %s.', $key, implode(', ', self::ALLOWED_UNITS));
      }
    }

    // The net step (duration + buffer) must clear the floor, else the slot
    // loop advances too little (or not at all) and never terminates.
    $stepSeconds = ((int) $config['duration']) * self::UNIT_SECONDS[$config['duration_units']]
      + ((int) $config['buffer']) * self::UNIT_SECONDS[$config['buffer_units']];
    if ($stepSeconds < self::MIN_CONSECUTIVE_SLOT_MINUTES * 60) {
      return sprintf('A consecutive recurrence must advance at least %d minutes each slot.', self::MIN_CONSECUTIVE_SLOT_MINUTES);
    }

    return NULL;
  }

  /**
   * Rejects a start/end span wider than MAX_SPAN_DAYS.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateSpan(array $config): ?string {
    $start = $config['start_date'] ?? NULL;
    $end = $config['end_date'] ?? NULL;
    if (!$this->isValidDate($start) || !$this->isValidDate($end)) {
      // Presence/parse is enforced per-type; span only bounds a valid pair.
      return NULL;
    }
    $spanDays = ($end->getTimestamp() - $start->getTimestamp()) / 86400;
    if ($spanDays > self::MAX_SPAN_DAYS) {
      return sprintf('The recurrence span may not exceed %d days.', self::MAX_SPAN_DAYS);
    }
    return NULL;
  }

  /**
   * Requires a parseable start_date and end_date on the converted config.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateStartEnd(array $config): ?string {
    if (!$this->isValidDate($config['start_date'] ?? NULL) || !$this->isValidDate($config['end_date'] ?? NULL)) {
      return 'The recurrence needs a valid start and end date.';
    }
    return NULL;
  }

  /**
   * Requires duration or end_time per the duration_or_end_time switch.
   *
   * @param array<string, mixed> $config
   *   The converted recurrence config.
   */
  private function validateDurationOrEndTime(array $config): ?string {
    $mode = $config['duration_or_end_time'] ?? NULL;
    if ($mode === 'duration') {
      if (!isset($config['duration']) || !is_numeric($config['duration'])) {
        return 'The recurrence needs a numeric duration.';
      }
      return NULL;
    }
    if ($mode === 'end_time') {
      if (empty($config['end_time'])) {
        return 'The recurrence needs an end time.';
      }
      return NULL;
    }
    return 'The recurrence needs a duration or an end time.';
  }

  /**
   * A converted multiplier value must be a non-empty array within the cap.
   *
   * getWeeklyDays()/getMonthlyDayOfMonth() return an ARRAY when populated but
   * the raw (empty string / NULL) field value when empty, so "non-empty array"
   * is the correct presence test for the foreach-crash keys.
   */
  private function validateMultiplier(mixed $value, string $emptyMessage): ?string {
    if (!is_array($value) || $value === []) {
      return $emptyMessage;
    }
    if (count($value) > self::MAX_MULTIPLIER_ELEMENTS) {
      return sprintf('A recurrence may not list more than %d values.', self::MAX_MULTIPLIER_ELEMENTS);
    }
    return NULL;
  }

  /**
   * Whether a converted-config date is a usable DrupalDateTime.
   *
   * The converted config carries start/end as DrupalDateTime objects (or NULL
   * on a missing/unparseable field). A DrupalDateTime built from bad input
   * carries errors rather than throwing at construction, so check both that it
   * is the right type and that it has no parse errors.
   */
  private function isValidDate(mixed $value): bool {
    return $value instanceof DrupalDateTime && !$value->hasErrors();
  }

  /**
   * Computes the series' effective (future-only) creation-set dates.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The event series whose current recur config is computed.
   *
   * @return array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}>
   *   The computed date set, keyed by DrupalDateTime::format('r') — the same
   *   shape recurring_events' own $events_to_create carries. Dates whose end
   *   is verifiably in the past are excluded.
   */
  public function compute(EventSeries $series): array {
    // An event with no recurrence configuration yet generates no dates.
    if (!$series->getRecurType()) {
      return [];
    }

    $config = $this->eventCreationService->convertEntityConfigToArray($series);
    $eventsToCreate = $this->calculateDates($config);

    // Snapshot/restore: fire the real contrib alter hook (so any module,
    // this one included, reacts exactly as it would during a genuine
    // createInstances() call — e.g. the past-preserving rebuild plugin's own
    // filtering) without letting a status/warning message meant for that
    // real save leak onto whatever unrelated request happens to call
    // compute().
    $snapshot = $this->messenger->all();
    $this->messenger->deleteAll();
    try {
      $this->moduleHandler->alter('recurring_events_event_instances_pre_create', $eventsToCreate, $series);
    }
    catch (\Throwable $e) {
      // compute() is a read-only preview of the effective set, not a real
      // save. The contrib alter reads the site's global excluded/included
      // date config and parses each entry with DrupalDateTime::
      // createFromFormat(), which throws on a malformed/partial stored date —
      // real content this preview must not fatal on. Fall back to the
      // pre-alter computed set (the alter only ever removes dates it excludes,
      // so the preview is at worst slightly over-inclusive) rather than let a
      // bad global-date config take down whatever form triggered the preview.
      \Drupal::logger('access_events')->notice('Effective-set preview skipped the pre-create alter for series @id: @message.', [
        '@id' => $series->id(),
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $this->messenger->deleteAll();
      foreach ($snapshot as $type => $messages) {
        foreach ($messages as $message) {
          $this->messenger->addMessage($message, $type);
        }
      }
    }

    // The alter above only applies self::filterFlaggedDates() itself while
    // PastPreservingEventInstanceCreator::isRebuilding($series) is TRUE for
    // this series (see the module alter's docblock) — compute() is also
    // called OUTSIDE a rebuild (e.g. a preview of the effective set), so it
    // cannot rely on the alter having done this filtering already. Call the
    // SAME shared method directly here instead of re-deriving the collision
    // logic, so a caller of compute() sees the identical exclusion the
    // rebuild plugin's own alter enforces during a real rebuild.
    $effective = self::filterFlaggedDates($this->filterFuture($eventsToCreate), $series);

    // Contrib's calculateInstances() builds the set PER-TOKEN, not
    // chronologically: a weekly days:'monday,wednesday' yields every Monday
    // then every Wednesday (WeeklyRecurringDate), and the monthly branches do
    // the same per day-of-month / weekday. So the raw set is grouped, not
    // ordered. A caller that slices the head (a preview's output cap) would
    // otherwise keep whole early tokens and drop later ones entirely rather
    // than the earliest N occurrences. Sort ascending by start timestamp here
    // so BOTH the count and any downstream slice see chronological order.
    uasort($effective, static fn (array $a, array $b): int => $a['start_date']->getTimestamp() <=> $b['start_date']->getTimestamp());

    return $effective;
  }

  /**
   * Drops any date whose computed start matches a preserved flagged
   * instance's start.
   *
   * A "preserved flagged instance" is any of the series' CURRENT eventinstance
   * entities with individually_cancelled = TRUE (see
   * PastPreservingEventInstanceCreator::isFlagged() /
   * EventStateReactions::instancePresave()) — a coordinator deliberately,
   * publicly cancelled that single occurrence, independent of the series.
   * Comparing on start timestamp mirrors PastPreservingEventInstanceCreator::
   * hasEnded()'s own strtotime($value . ' UTC') parse, so "the same date" is
   * judged identically everywhere this codebase makes that comparison. This
   * is the ONE function both access_events_recurring_events_event_instances_
   * pre_create_alter() (the actual rebuild path) and EffectiveCreationSet::
   * compute() (the read-only preview path) call — never two independent
   * derivations of "does this date collide with a flagged instance's date",
   * so they cannot drift apart.
   *
   * @param array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}> $eventsToCreate
   *   Keyed by DrupalDateTime::format('r'), same shape as $events_to_create
   *   in recurring_events_event_instances_pre_create.
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The series whose CURRENT instances are checked for flagged ones.
   *
   * @return array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}>
   */
  public static function filterFlaggedDates(array $eventsToCreate, EventSeries $series): array {
    $flaggedStarts = [];
    foreach ($series->event_instances->referencedEntities() as $instance) {
      /** @var \Drupal\recurring_events\Entity\EventInstance $instance */
      if (!$instance->hasField('individually_cancelled') || !(bool) $instance->get('individually_cancelled')->value) {
        continue;
      }
      $start = self::flaggedInstanceStart($instance);
      if ($start !== NULL) {
        $flaggedStarts[$start] = TRUE;
      }
    }

    if (!$flaggedStarts) {
      return $eventsToCreate;
    }

    return array_filter($eventsToCreate, function (array $dates) use ($flaggedStarts): bool {
      $start = $dates['start_date'] ?? NULL;
      return !$start || !isset($flaggedStarts[$start->getTimestamp()]);
    });
  }

  /**
   * The flagged instance's start timestamp, or NULL if unparseable/absent.
   *
   * Mirrors PastPreservingEventInstanceCreator::hasEnded()'s own
   * strtotime($value . ' UTC') parse against date.value (the START of the
   * range — hasEnded() itself parses end_value, since it is asking "has this
   * ended", but the collision this filter guards against is "is a new
   * instance about to be created at the SAME START as a preserved flagged
   * one", so it reads the start half of the same date field).
   */
  private static function flaggedInstanceStart(EventInstance $instance): ?int {
    $value = $instance->get('date')->value;
    if (!$value) {
      return NULL;
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === FALSE ? NULL : $timestamp;
  }

  /**
   * Calculates the raw (pre-alter) date set from a converted config array.
   *
   * Mirrors EventCreationService::createInstances()'s own branching: the
   * 'custom' recur type reads custom_dates directly; every other recur type
   * delegates to its field plugin's static calculateInstances().
   *
   * Both branches run their result through validRows() before returning, so
   * every row this method emits is a well-formed tuple whose start_date and
   * end_date are real DrupalDateTime values. Real content violates the
   * assumption that field data is fully populated — a coordinator can save a
   * custom_dates row with an empty start (the date-list widget allows
   * empty/partial rows), and a half-configured rule-based recurrence can make
   * a field plugin throw — so neither the raw custom list nor a contrib
   * calculateInstances() call can be trusted to yield only complete tuples.
   *
   * @return array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}>
   */
  private function calculateDates(array $config): array {
    if (empty($config['type'])) {
      return [];
    }

    if ($config['type'] === 'custom') {
      $eventsToCreate = [];
      foreach ($config['custom_dates'] ?? [] as $dateRange) {
        // A custom date row without a start date yields no occurrence: it
        // cannot be keyed or scheduled, so it is simply omitted from the
        // effective set (contrib's own createInstances() cannot build an
        // instance from it either).
        $start = $dateRange['start_date'] ?? NULL;
        if (!$start instanceof DrupalDateTime) {
          continue;
        }
        $eventsToCreate[$start->format('r')] = [
          'start_date' => $start,
          'end_date' => $dateRange['end_date'] ?? NULL,
        ];
      }
      return self::validRows($eventsToCreate);
    }

    // A rule-based recurrence delegates to its field plugin's
    // calculateInstances(), which trusts its config to be fully populated: a
    // half-configured rule (an empty days list, a missing start/end date, an
    // unregistered recur type) makes contrib throw a TypeError / Error /
    // PluginNotFoundException rather than return an empty set. An
    // unconfigured or partially-configured recurrence has no effective set
    // for warning purposes, so fail safe to [] rather than let that surface
    // as a fatal on whatever form triggered the computation.
    try {
      $fieldDefinition = $this->fieldTypePluginManager->getDefinition($config['type']);
      $fieldClass = $fieldDefinition['class'];
      $eventsToCreate = $fieldClass::calculateInstances($config);
    }
    catch (\Throwable $e) {
      return [];
    }
    return self::validRows($eventsToCreate);
  }

  /**
   * Keeps only rows whose start_date and end_date are real DrupalDateTime
   * values — the single choke point guaranteeing compute() only ever emits
   * well-formed tuples.
   *
   * A missing/NULL end_date would be kept by filterFuture() (it fails open on
   * a null end) but would then be dereferenced downstream (the flagged-date
   * filter and the createInstances() call both format both ends), so a row
   * missing either half is dropped here rather than carried forward.
   *
   * @param array<string, array{start_date: mixed, end_date: mixed}> $eventsToCreate
   *
   * @return array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}>
   */
  private static function validRows(array $eventsToCreate): array {
    return array_filter($eventsToCreate, function (array $dates): bool {
      return ($dates['start_date'] ?? NULL) instanceof DrupalDateTime
        && ($dates['end_date'] ?? NULL) instanceof DrupalDateTime;
    });
  }

  /**
   * Drops dates whose end is verifiably in the past.
   *
   * Same boundary as RegistrantCounter::endIsNotVerifiablyPast(): a missing
   * end_date entry (contrib's shape changing underneath this) is kept, not
   * dropped — failing open here means a real date is never silently hidden
   * from the effective set, at worst including one that is actually past.
   *
   * @param array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}> $eventsToCreate
   *
   * @return array<string, array{start_date: \Drupal\Core\Datetime\DrupalDateTime, end_date: \Drupal\Core\Datetime\DrupalDateTime}>
   */
  private function filterFuture(array $eventsToCreate): array {
    $now = $this->time->getRequestTime();
    return array_filter($eventsToCreate, function (array $dates) use ($now): bool {
      $end = $dates['end_date'] ?? NULL;
      return !$end || $end->getTimestamp() > $now;
    });
  }

}
