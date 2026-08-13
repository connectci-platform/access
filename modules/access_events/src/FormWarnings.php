<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Computes the recipient counts and wording behind the moderation-state
 * warnings shown on event edit/moderation forms.
 *
 * This class is computation ONLY: counts, gate reads, and translatable
 * strings. It has no knowledge of Form API — the form_alter implementations
 * in access_events.module call it and place the returned strings into
 * whatever render array shape a given form needs. Keeping the two apart
 * means the same counts and wording appear identically wherever a warning is
 * shown, and this class stays testable without booting a form.
 *
 * Two moderation-state edges each warn:
 *  - LEAVING published (on a currently-published entity being edited away
 *    from published): the event is about to be cancelled and its
 *    not-yet-past registrants notified.
 *  - ARRIVING at published (including a brand-new entity's FIRST publish):
 *    occurrences are about to (re)become live and their not-yet-past
 *    registrants notified. On a series, some previously-scheduled dates may
 *    have already elapsed while the series sat unpublished — those are
 *    reported separately as "skipped" (they will not be (re)created), not
 *    folded into the "will publish" count.
 *
 * Both directions read the SAME notification gates CancellationNotifier
 * checks before it actually queues anything (the site master switch +
 * the notification key's own enabled flag) — see gateOpen(). When a gate is
 * off, the warning still reports the count but states plainly that
 * registrants will NOT be emailed, so an editor is never told a message
 * went out that in fact did not.
 */
class FormWarnings {

  use StringTranslationTrait;

  public function __construct(
    protected RegistrantCounter $registrantCounter,
    protected EffectiveCreationSet $effectiveCreationSet,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * The warning for a currently-published entity about to leave published.
   *
   * Recipients are scoped to PROSPECTIVE recipients of the cancellation
   * email: not-verifiably-past registrants on the entity's CURRENTLY
   * PUBLISHED instance(s) only. A series-level cancel only archives its
   * published, not-past instances (see EventStateReactions::sweepCancel()) —
   * an instance that is already archived or individually cancelled does not
   * transition on this save, so its registrants are never enqueued for the
   * cancellation notice and must not be counted here. This deliberately
   * narrows a series' count below RegistrantCounter::countNotPastForSeries(),
   * which is state-blind by design (it answers "how many registrants would a
   * schedule-rebuild destroy", not "how many will be emailed by a cancel").
   *
   * @param \Drupal\recurring_events\Entity\EventSeries|\Drupal\recurring_events\Entity\EventInstance $entity
   *   The series or instance currently being edited, in its LAST-SAVED
   *   (still-published) state.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The warning text.
   */
  public function leavingPublishedWarning(EventSeries|EventInstance $entity): ?TranslatableMarkup {
    $recipients = $entity instanceof EventSeries
      ? $this->countNotPastForPublishedInstancesInSeries((int) $entity->id())
      : $this->registrantCounter->countNotPastForInstance((int) $entity->id());

    if ($recipients === 0) {
      return NULL;
    }

    if (!$this->notificationGateOpen(CancellationNotifier::KEY)) {
      return $this->formatPlural(
        $recipients,
        'This event has 1 registrant. Changing its status away from Published cancels the event. They will not be emailed — notifications are turned off.',
        'This event has @count registrants. Changing its status away from Published cancels the event. They will not be emailed — notifications are turned off.',
      );
    }

    return $this->formatPlural(
      $recipients,
      'This event has 1 registrant. Changing its status away from Published cancels the event and emails them.',
      'This event has @count registrants. Changing its status away from Published cancels the event and emails them.',
    );
  }

  /**
   * The warning for an entity arriving at published (including first publish).
   *
   * @param \Drupal\recurring_events\Entity\EventSeries|\Drupal\recurring_events\Entity\EventInstance $entity
   *   The series or instance as it will be saved (its prospective, about-to-
   *   be-published state) — used to compute the series' effective creation
   *   set when $entity is an EventSeries.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The warning text.
   */
  public function arrivingPublishedWarning(EventSeries|EventInstance $entity): ?TranslatableMarkup {
    if ($entity instanceof EventInstance) {
      return $this->arrivingPublishedWarningForInstance($entity);
    }
    return $this->arrivingPublishedWarningForSeries($entity);
  }

  /**
   * Arriving-at-published wording for a single occurrence.
   *
   * An instance has no "skipped past" concept of its own — that only applies
   * to a series' full computed date set (see arrivingPublishedWarningForSeries()).
   * Recipients are its own not-verifiably-past registrants.
   */
  private function arrivingPublishedWarningForInstance(EventInstance $entity): ?TranslatableMarkup {
    $recipients = $this->registrantCounter->countNotPastForInstance((int) $entity->id());

    if ($recipients === 0) {
      return NULL;
    }

    if (!$this->notificationGateOpen(CancellationNotifier::REINSTATE_KEY)) {
      return $this->formatPlural(
        $recipients,
        'This event has 1 registrant. Publishing it will not email them — notifications are turned off.',
        'This event has @count registrants. Publishing it will not email them — notifications are turned off.',
      );
    }

    return $this->formatPlural(
      $recipients,
      'This event has 1 registrant. Publishing it emails them that the event is active again.',
      'This event has @count registrants. Publishing it emails them that the event is active again.',
    );
  }

  /**
   * Arriving-at-published wording for a series (including its first publish).
   *
   * recipients = the union of not-verifiably-past registrants across only
   * the instances the series restore sweep will actually republish and
   * email — archived, not individually cancelled, not verifiably past (see
   * countNotPastForRestorableInstancesInSeries() and
   * EventStateReactions::sweepRestore()). NULL is returned when that count is
   * zero — there is nothing to disclose.
   */
  private function arrivingPublishedWarningForSeries(EventSeries $entity): ?TranslatableMarkup {
    $recipients = $this->countNotPastForRestorableInstancesInSeries((int) $entity->id());

    if ($recipients === 0) {
      return NULL;
    }

    if (!$this->notificationGateOpen(CancellationNotifier::REINSTATE_KEY)) {
      return $this->formatPlural(
        $recipients,
        'This event has 1 registrant. Publishing it will not email them — notifications are turned off.',
        'This event has @count registrants. Publishing it will not email them — notifications are turned off.',
      );
    }

    return $this->formatPlural(
      $recipients,
      'This event has 1 registrant. Publishing it emails them that the event is active again.',
      'This event has @count registrants. Publishing it emails them that the event is active again.',
    );
  }

  /**
   * Raw counts behind arrivingPublishedWarning(), for computation-only test
   * assertions and any caller (e.g. a future dashboard) that wants the
   * numbers without the rendered string.
   *
   * @return array{publishable: int, skipped_past: int, recipients: int}
   */
  public function arrivingPublishedCounts(EventSeries|EventInstance $entity): array {
    if ($entity instanceof EventInstance) {
      return [
        'publishable' => 1,
        'skipped_past' => 0,
        'recipients' => $this->registrantCounter->countNotPastForInstance((int) $entity->id()),
      ];
    }

    $effectiveSet = $this->effectiveCreationSet->compute($entity);
    $publishable = count($effectiveSet);
    $currentlyScheduled = count($entity->event_instances->referencedEntities());

    return [
      'publishable' => $publishable,
      'skipped_past' => max(0, $currentlyScheduled - $publishable),
      'recipients' => $this->countNotPastForRestorableInstancesInSeries((int) $entity->id()),
    ];
  }

  /**
   * The warning for editing a series whose schedule change would regenerate
   * occurrences, shown up front near the recurrence fields.
   *
   * Returns NULL when the series carries nothing a schedule change would put
   * at risk: no future occurrence sits at a date outside the series' current
   * effective creation set (a diverged or directly-added occurrence), and no
   * future occurrence is individually cancelled. In that case every future
   * occurrence would simply be recreated at the same dates by a rebuild, so
   * there is nothing to disclose.
   *
   * Divergence is computed via EffectiveCreationSet::compute() — the SAME
   * service the actual schedule-rebuild alter
   * (access_events_recurring_events_event_instances_pre_create_alter()) uses
   * to decide what a rebuild would produce. Comparing against that shared
   * computation, rather than re-deriving "does this occurrence match the
   * recur config" independently, is what keeps this warning honest: it can
   * never claim an occurrence is safe (or at risk) in a way the actual
   * rebuild would contradict.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $entity
   *   The series being edited, in its LAST-SAVED state (its CURRENT recur
   *   config and CURRENT occurrences — not the form's submitted values,
   *   which are not known until submission).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The warning text, or NULL when nothing qualifies.
   */
  public function scheduleChangeWarning(EventSeries $entity): ?TranslatableMarkup {
    if (!$this->seriesHasDivergedOrCancelledFutureOccurrence($entity)) {
      return NULL;
    }

    if ($this->registrantCounter->countNotPastForSeries((int) $entity->id()) > 0) {
      return $this->t('Changing the schedule (recurrence pattern or dates) regenerates this event\'s occurrences from the recurrence configuration: occurrences at dates not in it will be removed, and individually cancelled occurrences are kept. This event has registrations, so schedule changes are refused entirely — see the reschedule guidance if you need to change dates.');
    }

    return $this->t('Changing the schedule (recurrence pattern or dates) regenerates this event\'s occurrences from the recurrence configuration: occurrences at dates not in it will be removed, and individually cancelled occurrences are kept.');
  }

  /**
   * Whether $entity has at least one future occurrence a schedule change
   * would put at risk of removal or that is already individually cancelled.
   *
   * "Diverged" = a future occurrence whose start is NOT among
   * EffectiveCreationSet::compute()'s dates — either the series' recur
   * config changed since this occurrence was created, or the occurrence was
   * added directly (e.g. an admin-added one-off date) rather than by the
   * recur config. Both would be silently removed by the next rebuild.
   * "Individually cancelled" occurrences are NOT at risk of removal
   * (EffectiveCreationSet::filterFlaggedDates() already excludes their dates
   * from the computed set on their behalf, and the rebuild alter preserves
   * them by construction) but are still worth disclosing here, since an
   * editor about to change the schedule should know they are kept rather
   * than wonder whether they too will vanish.
   */
  private function seriesHasDivergedOrCancelledFutureOccurrence(EventSeries $entity): bool {
    $now = $this->registrantCounterTime();
    $effectiveStarts = [];
    foreach ($this->effectiveCreationSet->compute($entity) as $dates) {
      if (isset($dates['start_date'])) {
        $effectiveStarts[$dates['start_date']->getTimestamp()] = TRUE;
      }
    }

    foreach ($entity->event_instances->referencedEntities() as $instance) {
      /** @var \Drupal\recurring_events\Entity\EventInstance $instance */
      $endValue = $instance->get('date')->end_value;
      if (!RegistrantCounter::endIsNotVerifiablyPast($endValue, $now)) {
        continue;
      }

      if ($instance->hasField('individually_cancelled') && (bool) $instance->get('individually_cancelled')->value) {
        return TRUE;
      }

      $startValue = $instance->get('date')->value;
      $start = $startValue ? strtotime($startValue . ' UTC') : FALSE;
      if ($start === FALSE || !isset($effectiveStarts[$start])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The current request time, used only by
   * seriesHasDivergedOrCancelledFutureOccurrence()'s not-verifiably-past
   * filter. Reads \Drupal::time() directly (mirroring
   * EffectiveCreationSet::filterFuture()'s own source) rather than adding a
   * TimeInterface constructor argument to this already-large service for one
   * private helper's use.
   */
  private function registrantCounterTime(): int {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * The warning for choosing Draft on a currently-archived (dark) occurrence.
   *
   * Applies to ANY currently-archived occurrence, flagged or not.
   * sweepRestore() only republishes instances it finds still in the archived
   * state, and it skips individually_cancelled ones itself — a flagged
   * occurrence is excluded from series restore either way and returns only
   * via its own restore operation, never this sweep. An UNFLAGGED archived
   * occurrence, though, WOULD be picked up by the next series restore — but
   * only if it is still archived when that sweep runs. Moving it to Draft
   * instead takes it out of the archived state entirely, so that sweep will
   * not see it at all (archived is what it queries for) — it is now orphaned
   * from that mechanism until a human republishes it directly.
   */
  public function draftOnDarkWarning(): TranslatableMarkup {
    return $this->t('This occurrence will no longer be republished by series restore.');
  }

  /**
   * Sums not-verifiably-past registrants across a series' CURRENTLY
   * PUBLISHED instances only.
   *
   * moderation_state is a content_moderation COMPUTED field (sourced from a
   * separate revision entity, never stored on the eventinstance base table),
   * so it cannot appear in an entity-query condition — mirrors
   * EventStateReactions::notPastInstancesInState()'s own fresh entity query
   * by eventseries_id + PHP-side state filter, for the same reason. Reusing
   * RegistrantCounter::countNotPastForInstance() per matching instance keeps
   * the not-verifiably-past boundary itself identical to every other count
   * in this class, rather than a parallel query with its own date logic.
   */
  private function countNotPastForPublishedInstancesInSeries(int $seriesId): int {
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    $ids = $storage->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return 0;
    }

    $total = 0;
    foreach ($storage->loadMultiple($ids) as $instance) {
      if ($instance->get('moderation_state')->value !== 'published') {
        continue;
      }
      $total += $this->registrantCounter->countNotPastForInstance((int) $instance->id());
    }
    return $total;
  }

  /**
   * Sums not-verifiably-past registrants across only the instances a series
   * restore would actually republish and email: archived, NOT individually
   * cancelled, and not verifiably past.
   *
   * This mirrors exactly the population EventStateReactions::sweepRestore()
   * acts on — it publishes each archived, not-past instance and skips the
   * individually_cancelled ones — so the arriving-at-published warning's
   * recipient count matches who really gets the reinstatement email, rather
   * than the state-blind series-wide total. moderation_state is a
   * content_moderation COMPUTED field and cannot appear in an entity-query
   * condition, so it is filtered PHP-side after the query, the same way
   * countNotPastForPublishedInstancesInSeries() and
   * EventStateReactions::notPastInstancesInState() do.
   */
  private function countNotPastForRestorableInstancesInSeries(int $seriesId): int {
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    $ids = $storage->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return 0;
    }

    $total = 0;
    foreach ($storage->loadMultiple($ids) as $instance) {
      if ($instance->get('moderation_state')->value !== 'archived') {
        continue;
      }
      if ($instance->hasField('individually_cancelled') && (bool) $instance->get('individually_cancelled')->value) {
        continue;
      }
      $total += $this->registrantCounter->countNotPastForInstance((int) $instance->id());
    }
    return $total;
  }

  /**
   * Whether both notification gates (site master switch + the key) are on.
   *
   * Mirrors CancellationNotifier::gateOpen() exactly — the same two config
   * keys, read the same way — so a warning never claims registrants WILL be
   * emailed when the notifier itself would silently no-op, or vice versa.
   */
  private function notificationGateOpen(string $key): bool {
    $config = $this->configFactory->get('recurring_events_registration.registrant.config');
    return (bool) $config->get('email_notifications') && (bool) $config->get('notifications.' . $key . '.enabled');
  }

}
