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
  private $start_time;

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
  private $end_time;

  /**
   * True if start and end date are the same day.
   *
   * @var boolean
   */
  public $sameDay = 1;

  /**
   * Function to convert start and end date for events.
   */
  public function __construct($set_start, $set_end) {
    $start = 0;

    if ($set_start != NULL ) {
      $start_iso = strtotime($set_start);
      $start_day = date('d', $start_iso);
      $start = date('m/d/y - g:i A', $start_iso);
      $start_time = date('g:i A', $start_iso);
    }

    $end = 0;

    if ($set_end != NULL ) {
      $end_iso = strtotime($set_end);
      $end_day = date('d', $end_iso);
    }
    if ($set_end != NULL && $set_start != NULL) {
      if ($start_day != $end_day) {
        $this->sameDay = 0;

        $end = date('m/d/y - g:i A T', $end_iso);
        $end_time = date('g:i A', $end_iso);
      }
      else {
        $end = date('g:i A T', $end_iso);
        $end_time = date('g:i A', $end_iso);
      }
    }
    $this->start = $start;
    $this->start_time = $start_time;
    $this->end = $end;
    $this->end_time = $end_time;
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
    return $this->start_time;
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
    return $this->end_time;
  }

}
