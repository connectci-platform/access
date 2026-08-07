<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Counts registrants for the has_registrations safety gate.
 */
class RegistrantCounter {

  public function __construct(protected EntityTypeManagerInterface $etm) {}

  /**
   * Counts registrants attached to a single event instance.
   *
   * @param int $instanceId
   *   The event instance entity id.
   *
   * @return int
   *   The count of registrant entities referencing this instance.
   */
  public function countForInstance(int $instanceId): int {
    return (int) $this->etm->getStorage('registrant')->getQuery()
      ->condition('eventinstance_id', $instanceId)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Counts registrants across all instances of a series.
   *
   * @param int $seriesId
   *   The event series entity id.
   *
   * @return int
   *   The count of registrant entities referencing this series.
   */
  public function countForSeries(int $seriesId): int {
    return (int) $this->etm->getStorage('registrant')->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Counts registrants on a series' NOT-yet-ended instances.
   *
   * "Future" = the registrant's instance date.end_value > now — the same
   * boundary recurring_events uses (eventInstanceIsInFuture keys on the end
   * date, so an in-progress instance still counts). This is the population a
   * recurrence-config rebuild would destroy and the population cancel notifies.
   *
   * INVARIANT: this boundary must stay identical to
   * CancellationNotifier::instanceIsFuture() — both use getRequestTime() and a
   * strict >. Changing either alone (>=, wall-clock time()) silently splits the
   * blocked population from the notified population.
   *
   * @param int $seriesId
   *   The event series entity id.
   *
   * @return int
   *   Count of registrants whose instance has not yet ended.
   */
  public function countFutureForSeries(int $seriesId): int {
    $now = gmdate('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime());
    return (int) $this->etm->getStorage('registrant')->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->condition('eventinstance_id.entity.date.end_value', $now, '>')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Counts registrants on one instance, but only if the instance has not ended.
   *
   * @param int $instanceId
   *   The event instance entity id.
   *
   * @return int
   *   Registrant count, or 0 if the instance has already ended.
   */
  public function countFutureForInstance(int $instanceId): int {
    $now = gmdate('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime());
    return (int) $this->etm->getStorage('registrant')->getQuery()
      ->condition('eventinstance_id', $instanceId)
      ->condition('eventinstance_id.entity.date.end_value', $now, '>')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
