<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;

/**
 * Plants raw recurrence-boundary rows by writing the DB columns directly.
 *
 * Going through $series->set(...)->save() is not an option for these
 * fixtures: the bundle class' preSave() normalizes boundary values on the way
 * in, so an entity save can never produce the wall-clock/T00-shaped rows the
 * legacy-data tests need. Bypassing the entity API is the point.
 */
trait RecurBoundaryFixtureTrait {

  /**
   * Writes raw {field}__value/{field}__end_value strings for a series.
   *
   * Updates both the data table and the revision row of the series' current
   * revision so a subsequent load (default or revision-based) sees the raw
   * pair, then drops the series from the static/persistent entity cache.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   A saved series whose boundary columns to overwrite.
   * @param string $field
   *   Base field name, e.g. 'weekly_recurring_date'.
   * @param string $value
   *   Raw string for {field}__value, stored byte-for-byte.
   * @param string $endValue
   *   Raw string for {field}__end_value, stored byte-for-byte.
   */
  protected function setRawBoundary(EventSeries $series, string $field, string $value, string $endValue): void {
    $connection = \Drupal::database();
    $fields = [
      $field . '__value' => $value,
      $field . '__end_value' => $endValue,
    ];

    $connection->update('eventseries_field_data')
      ->fields($fields)
      ->condition('id', $series->id())
      ->execute();

    $connection->update('eventseries_field_revision')
      ->fields($fields)
      ->condition('id', $series->id())
      // The eventseries revision key is 'vid' (see the entity annotation),
      // not core's usual 'revision_id'.
      ->condition('vid', $series->getRevisionId())
      ->execute();

    \Drupal::entityTypeManager()->getStorage('eventseries')
      ->resetCache([$series->id()]);
  }

}
