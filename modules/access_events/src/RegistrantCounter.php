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

}
