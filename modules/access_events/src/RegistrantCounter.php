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
   * recurrence-config rebuild would destroy.
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

  /**
   * Counts registrants on a series' instances not verifiably in the past.
   *
   * "Not verifiably past" = end_value is NULL, empty, unparseable, or
   * strtotime($endValue) > now. This is a more permissive boundary than
   * countFutureForSeries() — it includes instances with missing or malformed
   * end dates, treating uncertainty as "not proven to be past".
   *
   * @param int $seriesId
   *   The event series entity id.
   *
   * @return int
   *   Count of registrants whose instance end date is not verifiably past.
   */
  public function countNotPastForSeries(int $seriesId): int {
    $now = \Drupal::time()->getRequestTime();
    $query = $this->etm->getStorage('registrant')->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE);

    // Load all registrants for this series and filter using the predicate.
    $registrantIds = $query->execute();
    $count = 0;

    if (!empty($registrantIds)) {
      $registrants = $this->etm->getStorage('registrant')->loadMultiple($registrantIds);
      foreach ($registrants as $registrant) {
        $instance = $registrant->get('eventinstance_id')->entity;
        if ($instance) {
          $endValue = $instance->get('date')->end_value;
          if (self::endIsNotVerifiablyPast($endValue, $now)) {
            $count++;
          }
        }
      }
    }

    return $count;
  }

  /**
   * Counts registrants on one instance, but only if not verifiably past.
   *
   * @param int $instanceId
   *   The event instance entity id.
   *
   * @return int
   *   Registrant count, or 0 if the instance is verifiably in the past.
   */
  public function countNotPastForInstance(int $instanceId): int {
    $now = \Drupal::time()->getRequestTime();
    $query = $this->etm->getStorage('registrant')->getQuery()
      ->condition('eventinstance_id', $instanceId)
      ->accessCheck(FALSE);

    $registrantIds = $query->execute();
    $count = 0;

    if (!empty($registrantIds)) {
      $registrants = $this->etm->getStorage('registrant')->loadMultiple($registrantIds);
      $instance = $this->etm->getStorage('eventinstance')->load($instanceId);
      if ($instance) {
        $endValue = $instance->get('date')->end_value;
        if (self::endIsNotVerifiablyPast($endValue, $now)) {
          $count = count($registrants);
        }
      }
    }

    return $count;
  }

  /**
   * Checks if an end date is not verifiably in the past.
   *
   * "Not verifiably past" means:
   * - The date is NULL or empty string, OR
   * - The date cannot be parsed with strtotime(), OR
   * - The parsed timestamp is strictly greater than $now.
   *
   * @param string|null $endValue
   *   The end_value from a date field. May be a string or null.
   * @param int $now
   *   The unix timestamp to compare against.
   *
   * @return bool
   *   TRUE if the date is not verifiably in the past; FALSE otherwise.
   */
  public static function endIsNotVerifiablyPast(?string $endValue, int $now): bool {
    return $endValue === NULL || $endValue === '' || strtotime($endValue . ' UTC') === FALSE || strtotime($endValue . ' UTC') > $now;
  }

}
