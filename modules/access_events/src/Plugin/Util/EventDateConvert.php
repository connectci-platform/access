<?php

namespace Drupal\access_events\Plugin\Util;

/**
 * Convert Date for events.
 *
 * @EventDateConvert(
 *   id = "event_date_convert",
 *   title = @Translation("Event date convert"),
 *   description = @Translation("Convert Date for events.")
 * )
 */
class EventDateConvert {
  /**
   * Stores start date.
   *
   * @var string
   */
  private $start;

  /**
   * Stores start time.
   *
   * @var string
   */
  private $startTime;

  /**
   * Stores the start's weekday and date, in the same zone as the times.
   *
   * @var string
   */
  private $startDayDate = '';

  /**
   * Stores end date.
   *
   * @var string
   */
  private $end;

  /**
   * Stores end date time.
   *
   * @var string
   */
  private $endTime;

  /**
   * True if start and end date are the same day.
   *
   * @var bool
   */
  public $sameDay = 1;

  /**
   * Function to convert start and end date for events.
   */
  public function __construct($set_start, $set_end, ?string $displayZone = NULL) {
    $start = 0;

    // Render in the zone the input carries, not the ambient one. Callers now
    // pass strings the date formatter produced, and for an in-person event
    // that string is in the venue's zone with an explicit offset. strtotime()
    // reads the offset correctly, but date() would then re-render in ambient —
    // showing a Chicago event's 9:00 AM as 10:00 AM EDT, the right instant
    // with the wrong clock and a label contradicting the venue.
    // An explicit zone names itself, so 'T' renders CST rather than GMT-0500.
    // Without one, fall back to whatever the input string carries.
    $named = ($displayZone !== NULL && $displayZone !== '' && in_array($displayZone, \DateTimeZone::listIdentifiers(), TRUE))
      ? new \DateTimeZone($displayZone)
      : NULL;
    // Only an explicitly named zone changes the rendering. Inferring one from
    // an offset in the input would relabel viewer-local times as GMT-0400
    // instead of EDT, which is worse than leaving them alone — and an online
    // event's times are already correct in the ambient zone.
    $startZone = $named;
    $endZone = $named;

    if ($set_start != NULL) {
      $start_iso = strtotime($set_start);
      $start_date = self::render($start_iso, 'Y-m-d', $startZone);
      $start = self::render($start_iso, 'm/d/y - g:i A', $startZone);
      $start_time = self::render($start_iso, 'g:i A', $startZone);
      $start_day_date = self::render($start_iso, 'l n/j/Y', $startZone);
    }

    $end = 0;

    if ($set_end != NULL) {
      $end_iso = strtotime($set_end);
      $end_date = self::render($end_iso, 'Y-m-d', $endZone);
    }
    if ($set_end != NULL && $set_start != NULL) {
      if ($start_date != $end_date) {
        $this->sameDay = 0;

        $end = self::render($end_iso, 'm/d/y - g:i A T', $endZone);
        $end_time = self::render($end_iso, 'g:i A T', $endZone);
      }
      else {
        $end = self::render($end_iso, 'g:i A T', $endZone);
        $end_time = self::render($end_iso, 'g:i A T', $endZone);
      }
    }

    $this->start = $start;
    $this->startTime = $start_time;
    $this->startDayDate = $start_day_date ?? '';
    $this->end = $end;
    $this->endTime = $end_time;
  }

  /**
   * Formats a timestamp in a given zone, or ambient when none is given.
   */
  private static function render(int $timestamp, string $format, ?\DateTimeZone $zone): string {
    if ($zone === NULL) {
      return date($format, $timestamp);
    }
    return (new \DateTime('@' . $timestamp))->setTimezone($zone)->format($format);
  }

  /**
   * Function to get start date.
   */
  public function getStart() {
    return $this->start;
  }

  /**
   * Function to get start time.
   */
  public function getStartTime() {
    return $this->startTime;
  }

  /**
   * The weekday and date of the start, in the same zone as the times.
   *
   * Callers that print a weekday alongside getStartTime() need it computed in
   * the zone the times were rendered in. Re-deriving it with date() would use
   * the ambient zone, which for an evening in-person event names the wrong
   * weekday and the wrong day.
   */
  public function getStartDayDate() {
    return $this->startDayDate;
  }

  /**
   * Function to get end date.
   */
  public function getEnd() {
    return $this->end;
  }

  /**
   * Function to get end time.
   */
  public function getEndTime() {
    return $this->endTime;
  }

}
