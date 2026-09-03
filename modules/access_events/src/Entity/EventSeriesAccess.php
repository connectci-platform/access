<?php

declare(strict_types=1);

namespace Drupal\access_events\Entity;

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

}
