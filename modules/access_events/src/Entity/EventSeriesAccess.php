<?php

declare(strict_types=1);

namespace Drupal\access_events\Entity;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Bundle class for the eventseries "default" bundle.
 *
 * Owns the recurrence-boundary storage invariant: boundary dates must read as
 * the same calendar date for every viewer regardless of timezone, instead of
 * sliding a day when the reader's zone crosses UTC (D8-2838).
 */
class EventSeriesAccess extends EventSeries {

  /**
   * Whether an API writer already normalized the recurrence boundaries.
   *
   * Transient (never persisted): set by the write path on the in-memory
   * entity right before save() so preSave() can tell normalized controller
   * input apart from raw browser-form input, and always FALSE on a fresh
   * load.
   */
  public bool $recurBoundariesNormalized = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getConsecutiveStartDate() {
    return $this->decodeBoundary($this->get('consecutive_recurring_date')->value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getConsecutiveEndDate() {
    return $this->decodeBoundary($this->get('consecutive_recurring_date')->end_value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getDailyStartDate() {
    return $this->decodeBoundary($this->get('daily_recurring_date')->value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getDailyEndDate() {
    return $this->decodeBoundary($this->get('daily_recurring_date')->end_value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getWeeklyStartDate() {
    return $this->decodeBoundary($this->get('weekly_recurring_date')->value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getWeeklyEndDate() {
    return $this->decodeBoundary($this->get('weekly_recurring_date')->end_value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getMonthlyStartDate() {
    return $this->decodeBoundary($this->get('monthly_recurring_date')->value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getMonthlyEndDate() {
    return $this->decodeBoundary($this->get('monthly_recurring_date')->end_value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getYearlyStartDate() {
    return $this->decodeBoundary($this->get('yearly_recurring_date')->value ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getYearlyEndDate() {
    return $this->decodeBoundary($this->get('yearly_recurring_date')->end_value ?? NULL);
  }

  /**
   * Decodes a raw boundary string into the reader's-zone calendar midnight.
   *
   * Anchored values (`{date}T12:00:00`) are reader-independent: the stored
   * date substring IS the calendar date, so it materializes at midnight in
   * the reader's zone without any instant conversion — the date can never
   * slide, no matter how far the reader sits from UTC. Any other shape gets
   * contrib's stock semantics (UTC instant converted into the reader's
   * zone, then floored to midnight), so unmigrated legacy rows and
   * form-extracted wall-clock values decode exactly as today.
   *
   * @param string|null $raw
   *   The stored column value ({field}__value or {field}__end_value).
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|null
   *   A FRESH date object per call — midnight in date_default_timezone_get(),
   *   seconds and microseconds zeroed — or NULL for an empty column.
   */
  private function decodeBoundary(?string $raw): ?DrupalDateTime {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $tz = new \DateTimeZone(date_default_timezone_get());
    if (str_ends_with($raw, 'T12:00:00')) {
      // Anchored: the stored date substring IS the calendar date.
      return DrupalDateTime::createFromFormat('Y-m-d H:i:s', substr($raw, 0, 10) . ' 00:00:00', $tz);
    }
    // Legacy/wall-clock: contrib's stock semantics, re-derived FRESH — never
    // parent:: (returns/mutates the shared computed object), never clone
    // (shares the inner \DateTime).
    return DrupalDateTime::createFromFormat(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $raw, DateTimeItemInterface::STORAGE_TIMEZONE)
      ->setTimezone($tz)
      ->setTime(0, 0, 0);
  }

}
