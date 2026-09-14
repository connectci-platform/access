<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\BubbleableMetadata;

/**
 * Renders an in-person event's times in its venue's timezone.
 *
 * Online events keep core's behaviour untouched: the time converts to the
 * viewer's zone, which is the zone that tells them when to show up. That is
 * roughly two thirds of production events and every event today.
 *
 * An in-person event is different. Its venue clock is authoritative no matter
 * where the viewer sits, so a workshop at 9am in a Chicago building reads 9am
 * to everyone rather than 7am to someone in Los Angeles.
 *
 * Both core date-range formatters route every rendered value through
 * setTimeZone(), so overriding that one method covers both the plain
 * buildDate() path used by the three eventinstance view displays and the
 * buildDateWithIsoAttribute() path used by the six views. Overriding the
 * build methods themselves would mean duplicating the logic, and a fix applied
 * to only one of them leaves every listing rendering viewer-local while the
 * detail page looks correct.
 */
trait EventVenueTimezoneFormatterTrait {

  /**
   * {@inheritdoc}
   *
   * Defers to core unless this is an in-person event with a stored zone.
   */
  protected function setTimeZone(DrupalDateTime $date) {
    $venueZone = $this->venueTimezone();
    if ($venueZone === NULL) {
      parent::setTimeZone($date);
      return;
    }
    $date->setTimezone(new \DateTimeZone($venueZone));
  }

  /**
   * The venue timezone for the entity being rendered, if it has one.
   *
   * @return string|null
   *   An IANA zone name, or NULL to let core convert to the viewer's zone.
   */
  private function venueTimezone(): ?string {
    $entity = $this->venueTimezoneEntity ?? NULL;
    if ($entity === NULL) {
      return NULL;
    }
    // Both fields live on the series and reach the instance through
    // field_inheritance, which exposes them under names with the
    // eventinstance_default_ prefix stripped. An instance whose inheritance
    // rows are missing reads empty here, which falls through to core — the
    // safe direction, and why the backfill repairs those rows.
    $inPerson = $entity->hasField('event_in_person')
      ? (bool) $entity->get('event_in_person')->value
      : FALSE;
    if (!$inPerson) {
      return NULL;
    }
    $zone = $entity->hasField('event_timezone')
      ? trim((string) ($entity->get('event_timezone')->value ?? ''))
      : '';
    if ($zone === '' || !in_array($zone, \DateTimeZone::listIdentifiers(), TRUE)) {
      return NULL;
    }
    return $zone;
  }

  /**
   * {@inheritdoc}
   *
   * Captures the entity, which the build methods do not receive, and declares
   * the cacheability the rendered value actually depends on.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $this->venueTimezoneEntity = $items->getEntity();
    $elements = parent::viewElements($items, $langcode);

    // Render the compact form: the date once, and the end as a bare time when
    // the event starts and ends on the same day. Core states both endpoints in
    // full, which for a one-hour event repeats the whole date. This used to be
    // done by re-parsing the rendered markup in access_misc_entity_view(); it
    // belongs here, where the zone the date was computed in is known.
    // Only when this formatter is actually rendering BOTH endpoints. The
    // api/2.1/events data_export display uses this same formatter id with
    // from_to 'start_date' and an ISO date_format; rewriting that into prose
    // corrupts JSON that consumers parse.
    $bothEndpoints = ($this->getSetting('from_to') ?? 'both') === 'both';
    if ($this->usesCompactRange() && $bothEndpoints) {
      foreach ($items as $delta => $item) {
        if (isset($elements[$delta]) && !empty($item->start_date) && !empty($item->end_date)) {
          $elements[$delta] = $this->compactRange($elements[$delta], $item);
        }
      }
    }

    // The zone lives on the SERIES while this renders an INSTANCE, and neither
    // recurring_events nor field_inheritance bubbles any cacheability for an
    // inherited value. Attach the tag WHATEVER the current modality: the render
    // depends on those series fields regardless of the values they hold today,
    // so marking an in-person event alone would leave every already-cached
    // online render showing viewer-local times after the series was flipped.
    $series = $this->venueTimezoneEntity->hasField('eventseries_id')
      ? $this->venueTimezoneEntity->get('eventseries_id')->entity
      : NULL;
    if ($series !== NULL) {
      $elements['#cache']['tags'] = array_merge(
        $elements['#cache']['tags'] ?? [],
        $series->getCacheTags()
      );
    }

    $venueZone = $this->venueTimezone();
    if ($venueZone === NULL) {
      return $elements;
    }

    // An in-person time is the same string for every viewer, so varying the
    // cache by timezone fragments it into identical copies.
    foreach ($elements as $delta => $element) {
      if (is_numeric($delta)) {
        $elements[$delta] = $this->dropTimezoneContext($element);
      }
    }

    return $elements;
  }

  /**
   * Whether this formatter renders the compact human-readable range.
   *
   * Only the detail page's prose rendering is compacted. The views formatter
   * feeds six views including the API view and emits machine-readable values
   * (html_datetime, with an ISO offset a consumer parses); rewriting those
   * into prose would corrupt them.
   *
   * @return bool
   *   TRUE to render the compact range.
   */
  protected function usesCompactRange(): bool {
    return FALSE;
  }

  /**
   * Renders a range compactly: the date once, the end as a time when same-day.
   *
   * Produces "07/15/26 - 2:00 PM - 3:00 PM CDT" for a same-day event and
   * "07/15/26 - 2:00 PM - 07/17/26 - 3:00 PM CDT" when it spans days, which is
   * how the event detail page has always read. Both endpoints are formatted in
   * whatever zone setTimeZone() resolved, so an in-person event states its
   * venue's clock and an online one the viewer's.
   *
   * @param array $element
   *   The rendered element for this delta.
   * @param \Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem $item
   *   The field item being rendered.
   *
   * @return array
   *   The element, with its markup replaced by the compact rendering.
   */
  private function compactRange(array $element, $item): array {
    $start = $item->start_date;
    $end = $item->end_date;
    if (!$start instanceof DrupalDateTime || !$end instanceof DrupalDateTime) {
      return $element;
    }

    // Clone before converting: these are the field item's own objects and
    // setTimezone() mutates in place, leaking the conversion into every later
    // reader of the same item.
    $start = clone $start;
    $end = clone $end;
    $this->setTimeZone($start);
    $this->setTimeZone($end);

    // A cross-day range reads ' to ' between the endpoints; a same-day one
    // keeps the plain dash. Both are what the detail page has always shown.
    $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
    $text = $sameDay
      ? $start->format('m/d/y - g:i A') . ' - ' . $end->format('g:i A T')
      : $start->format('m/d/y - g:i A') . ' to ' . $end->format('m/d/y - g:i A T');

    // Replace the whole delta: core builds start/end/separator as separate
    // children, and leaving any of them would render the range twice.
    //
    // Carry the discarded children's cacheability across. Core attaches the
    // 'timezone' cache context to the start_date and end_date CHILDREN, not to
    // this outer element, so copying $element['#cache'] alone salvages nothing
    // — and an online event's render, which legitimately differs per viewer,
    // would then be cached without that context and served to the wrong one.
    $metadata = BubbleableMetadata::createFromRenderArray($element);
    foreach ($element as $key => $child) {
      if (is_array($child) && str_starts_with((string) $key, '#') === FALSE) {
        $metadata = $metadata->merge(BubbleableMetadata::createFromRenderArray($child));
      }
    }

    $rebuilt = ['#plain_text' => $text];
    $metadata->applyTo($rebuilt);
    return $rebuilt;
  }

  /**
   * Removes the viewer-timezone cache context from a rendered date element.
   */
  private function dropTimezoneContext(array $element): array {
    if (isset($element['#cache']['contexts'])) {
      $element['#cache']['contexts'] = array_values(
        array_diff($element['#cache']['contexts'], ['timezone'])
      );
    }
    foreach ($element as $key => $child) {
      if (is_array($child) && str_starts_with((string) $key, '#') === FALSE) {
        $element[$key] = $this->dropTimezoneContext($child);
      }
    }
    return $element;
  }

}
