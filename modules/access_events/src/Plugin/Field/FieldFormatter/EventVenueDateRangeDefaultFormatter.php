<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\Field\FieldFormatter;

use Drupal\access_events\EventVenueTimezoneFormatterTrait;
use Drupal\datetime_range\Plugin\Field\FieldFormatter\DateRangeDefaultFormatter;

/**
 * Renders a date range in the event's venue timezone where it has one.
 *
 * The views counterpart: six views use daterange_default, including the main
 * events listing and the API view. It reaches buildDateWithIsoAttribute()
 * rather than buildDate(), so covering only the custom formatter would fix the
 * detail page and leave every listing rendering viewer-local.
 */
class EventVenueDateRangeDefaultFormatter extends DateRangeDefaultFormatter {

  use EventVenueTimezoneFormatterTrait;

  /**
   * The entity being rendered, captured so buildDate() can reach its fields.
   *
   * @var \Drupal\Core\Entity\EntityInterface|null
   */
  protected $venueTimezoneEntity = NULL;

}
