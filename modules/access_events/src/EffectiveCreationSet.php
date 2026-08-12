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

  public function __construct(
    protected EventCreationService $eventCreationService,
    protected FieldTypePluginManagerInterface $fieldTypePluginManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected MessengerInterface $messenger,
    protected TimeInterface $time,
  ) {}

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
    return self::filterFlaggedDates($this->filterFuture($eventsToCreate), $series);
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
