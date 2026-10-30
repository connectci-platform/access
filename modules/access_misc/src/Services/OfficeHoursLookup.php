<?php

namespace Drupal\access_misc\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Finds the upcoming sessions of the ACCESS and NAIRR Pilot office hours.
 *
 * Feeds the "Next: ..." line of the homepage events band (D8-2934). The
 * office hours run as two recurring event series that share one title, so the
 * instances are matched on the series title rather than on a series ID.
 */
class OfficeHoursLookup {

  /**
   * Title shared by the office hours event series.
   */
  const SERIES_TITLE = 'ACCESS and NAIRR Pilot Office Hours';

  /**
   * Constructs the lookup.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the next published office hours sessions, soonest first.
   *
   * @param int $limit
   *   The maximum number of sessions to return.
   *
   * @return array<int, array{label: string, start: int}>
   *   Each session's label (for example "Wed 10/28", in the site time zone)
   *   and its start as a Unix timestamp.
   */
  public function getNext(int $limit = 2): array {
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    // Instance dates are stored in UTC.
    $now = gmdate('Y-m-d\TH:i:s', $this->time->getRequestTime());
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('date.value', $now, '>=')
      ->condition('eventseries_id.entity.title', self::SERIES_TITLE)
      ->sort('date.value', 'ASC')
      ->range(0, $limit)
      ->execute();

    $sessions = [];
    /** @var \Drupal\recurring_events\Entity\EventInstance $instance */
    foreach ($storage->loadMultiple($ids) as $instance) {
      // The stored value is a UTC 'Y-m-d\TH:i:s' string.
      $value = $instance->get('date')->value;
      if (!is_string($value)) {
        continue;
      }
      $timestamp = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
      $sessions[] = [
        'label' => $this->dateFormatter->format($timestamp, 'custom', 'D n/j'),
        'start' => $timestamp,
      ];
    }
    // loadMultiple() does not preserve the query's sort order.
    usort($sessions, fn(array $a, array $b) => $a['start'] <=> $b['start']);
    return $sessions;
  }

}
