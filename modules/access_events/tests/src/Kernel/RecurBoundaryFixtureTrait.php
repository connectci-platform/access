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

  /**
   * Plants an extra revision ROW with its own raw boundary pair.
   *
   * Copies the series' current eventseries_field_revision row under a fresh,
   * higher vid and overwrites the given field's boundary columns — a
   * direct-DB stand-in for a forward/draft (or orphaned old) revision whose
   * boundary shape diverges from the default revision's. Only the field
   * table gets the row (the migrator reads nothing else), and the entity API
   * is bypassed for the same reason as setRawBoundary(): a real revision
   * save would normalize the value on the way in.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   A saved series to hang the extra revision row on.
   * @param string $field
   *   Base field name, e.g. 'weekly_recurring_date'.
   * @param string $value
   *   Raw string for {field}__value, stored byte-for-byte.
   * @param string $endValue
   *   Raw string for {field}__end_value, stored byte-for-byte.
   *
   * @return int
   *   The vid the planted row was stored under.
   */
  protected function plantDivergentRevisionRow(EventSeries $series, string $field, string $value, string $endValue): int {
    $connection = \Drupal::database();
    $row = $connection->select('eventseries_field_revision', 'r')
      ->fields('r')
      ->condition('id', $series->id())
      ->condition('vid', $series->getRevisionId())
      ->execute()
      ->fetchAssoc();
    // Past the global vid sequence (vid is one sequence across ALL series),
    // so the planted row can never collide with a later fixture's revision.
    $row['vid'] = 1000 + (int) $connection->query('SELECT MAX(vid) FROM {eventseries_field_revision}')->fetchField();
    $row[$field . '__value'] = $value;
    $row[$field . '__end_value'] = $endValue;
    $connection->insert('eventseries_field_revision')->fields($row)->execute();

    \Drupal::entityTypeManager()->getStorage('eventseries')
      ->resetCache([$series->id()]);
    return (int) $row['vid'];
  }

}
