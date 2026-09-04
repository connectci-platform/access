<?php

declare(strict_types=1);

namespace Drupal\access_events\Entity;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
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
   * The five recurrence-rule date-range base fields the invariant covers.
   *
   * Each is a cardinality-1 daterange whose value/end_value columns hold the
   * series' recurrence boundaries; preSave() normalizes all five regardless
   * of which one the series' recur_type currently activates.
   */
  public const RULE_FIELDS = [
    'consecutive_recurring_date',
    'daily_recurring_date',
    'weekly_recurring_date',
    'monthly_recurring_date',
    'yearly_recurring_date',
  ];

  /**
   * The time-part suffix that marks a stored boundary as anchored.
   *
   * `{date}T12:00:00` means "this literal calendar date, for every reader" —
   * noon-UTC keeps the date stable across UTC-12..UTC+14 when a legacy
   * consumer does instant-convert it.
   */
  public const ANCHOR_SUFFIX = 'T12:00:00';

  /**
   * Whether a raw stored boundary carries the anchor signature.
   *
   * READ-side classification only — shared by the decode getters, the widget
   * prefill and the legacy-row migrator. The WRITE path (preSave) must never
   * key on it: the signature is writer-reachable in-band (see preSave()).
   *
   * @param string $raw
   *   A raw {field}__value / {field}__end_value string.
   *
   * @return bool
   *   TRUE when the value is an anchored boundary.
   */
  public static function isAnchored(string $raw): bool {
    return str_ends_with($raw, self::ANCHOR_SUFFIX);
  }

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
   *
   * Normalizes the recurrence boundaries to `{date}T12:00:00` anchors, keyed
   * on CHANGE and WRITER — never on the stored value's shape, because the
   * anchor signature is writer-reachable in-band (a far-east editor saving
   * at local midnight stores a wrong date wearing `T12:00:00`; trusting the
   * shape would freeze it forever). Per column, in this order:
   * - flagged writer: a controller that already normalized set
   *   $recurBoundariesNormalized, so its values are deliberate anchors,
   *   correct under any acting-user timezone — stored verbatim;
   * - unchanged value: byte-identical to the stored original — untouched.
   *   This branch carries all far-east (UTC >= +12) correctness: a correct
   *   anchor reads back as next-day under a +12 acting user, and only
   *   never-re-deriving unchanged values keeps a programmatic re-save
   *   (moderation, cron) from ratcheting the date a day per save;
   * - changed value: new input from a form or programmatic write —
   *   recovered to the saver's intended calendar date and anchored.
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    foreach (self::RULE_FIELDS as $field) {
      if (!$this->hasField($field) || $this->get($field)->isEmpty()) {
        continue;
      }
      $item = $this->get($field)->first();
      foreach (['value', 'end_value'] as $column) {
        $current = $item->{$column} ?? NULL;
        if ($current === NULL || $current === '') {
          continue;
        }
        if ($this->recurBoundariesNormalized) {
          continue;
        }
        // "Unchanged" is measured against the DEFAULT revision (what
        // $this->original holds during save).
        $originalValue = isset($this->original) ? ($this->original->get($field)->first()->{$column} ?? NULL) : NULL;
        if (isset($this->original) && $current === $originalValue) {
          continue;
        }
        $item->set($column, self::recoverToAnchor((string) $current));
      }
    }
  }

  /**
   * Recovers a changed raw boundary to its intended `{date}T12:00:00` anchor.
   *
   * An instant-shaped value is what the date widget stored: the chosen date
   * at the editor's submit-moment wall clock, converted to UTC. Converting
   * it back into the saver's zone (date_default_timezone_get()) inverts
   * that write exactly, recovering the calendar date the editor chose —
   * including the signature-collision case, where a far-east local-midnight
   * save produced a T12-shaped wrong date.
   *
   * @param string $raw
   *   The changed column value.
   *
   * @return string
   *   The anchored value, or $raw unchanged if its shape is unrecognized.
   */
  private static function recoverToAnchor(string $raw): string {
    // Bare calendar date: LITERAL — it has no instant; parsing it as one
    // injects the current wall clock and shifts TZ-dependently.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      return $raw . self::ANCHOR_SUFFIX;
    }
    // `!` zeroes unspecified fields; the strict `\T` separator rejects
    // space-separated datetimes into the store-unchanged path below rather
    // than the bare-date branch above.
    $dt = \DateTime::createFromFormat('!Y-m-d\TH:i:s', $raw, new \DateTimeZone('UTC'));
    if ($dt === FALSE) {
      // Unrecognized shape: store unchanged rather than corrupt; decode's
      // stock branch handles it like any legacy value.
      return $raw;
    }
    $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $dt->format('Y-m-d') . self::ANCHOR_SUFFIX;
  }

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
    if (self::isAnchored($raw)) {
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
