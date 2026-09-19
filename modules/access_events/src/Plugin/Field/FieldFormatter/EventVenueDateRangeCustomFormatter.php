<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\Field\FieldFormatter;

use Drupal\access_events\EventVenueTimezoneFormatterTrait;
use Drupal\datetime_range\Plugin\Field\FieldFormatter\DateRangeCustomFormatter;

/**
 * Renders a date range in the event's venue timezone where it has one.
 *
 * Swapped in for core's daterange_custom via
 * access_events_field_formatter_info_alter(), so the three eventinstance view
 * displays pick it up without their config changing.
 */
class EventVenueDateRangeCustomFormatter extends DateRangeCustomFormatter {

  use EventVenueTimezoneFormatterTrait;

  /**
   * {@inheritdoc}
   *
   * This is the detail page's prose rendering, where the compact form belongs.
   */
  protected function usesCompactRange(): bool {
    return TRUE;
  }

  /**
   * The entity being rendered, captured so buildDate() can reach its fields.
   *
   * @var \Drupal\Core\Entity\EntityInterface|null
   */
  protected $venueTimezoneEntity = NULL;

}
