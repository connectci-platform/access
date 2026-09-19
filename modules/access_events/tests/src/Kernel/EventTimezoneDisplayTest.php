<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\recurring_events\Entity\EventInstance;

/**
 * Tests how an event's own timezone affects the rendered time.
 *
 * Online events keep viewer-local conversion, which is what nearly two thirds
 * of production events are and what every event does today. In-person events
 * render in the venue's zone instead, because the venue clock is authoritative
 * regardless of where the viewer sits.
 *
 * The rule underneath both branches is the one that matters: a displayed time's
 * zone abbreviation must come from the same date object that produced the
 * number. Attaching the event's zone to a viewer-converted number would give
 * "12:00 PM CDT" to an LA viewer of a 2pm Chicago event — a real-looking time
 * naming a different instant.
 *
 * Two formatters reach these surfaces and they take different render paths:
 * the three eventinstance view displays use daterange_custom (plain #markup via
 * buildDate), while six views use daterange_default (a #theme=time element via
 * buildDateWithIsoAttribute). Both inherit from the same core base, so one
 * override covers both — but a fix verified only on the detail page leaves
 * every listing rendering viewer-local.
 *
 * @group access_events
 */
class EventTimezoneDisplayTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();

    // daterange_default renders through a date_format config entity, which
    // ships in system's config/install and so is absent from this minimal
    // kernel environment.
    if (!\Drupal::entityTypeManager()->getStorage('date_format')->load('html_datetime')) {
      \Drupal::entityTypeManager()->getStorage('date_format')->create([
        'id' => 'html_datetime',
        'label' => 'HTML Datetime',
        'locked' => TRUE,
        'pattern' => 'Y-m-d\\TH:i:sO',
      ])->save();
    }
  }

  /**
   * Builds an instance whose series carries the given zone and modality.
   */
  private function instanceWithZone(string $zone, bool $inPerson): EventInstance {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $series->set('field_event_timezone', $zone);
    $series->set('field_event_in_person', $inPerson);
    $series->save();

    // Pin a summer date: the base fixture sits in January, where the zone
    // abbreviations are the standard-time ones (PST/CST) rather than the
    // daylight ones the assertions name.
    $instance->set('date', [
      'value' => '2026-07-15T19:00:00',
      'end_value' => '2026-07-15T20:00:00',
    ]);
    $instance->save();

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    return $this->reloadInstance($instance);
  }

  /**
   * A same-day event shows the end as a time, not a repeated date.
   *
   * This is the shape the detail page has always had:
   *   09/30/25 - 12:00 PM - 1:00 PM EDT
   * The date appears once, the end is time-only, and the zone is named once at
   * the end. Letting core's daterange formatter render both endpoints in full
   * instead produces
   *   9/30/2025 12:00 PM EDT - 9/30/2025 1:00 PM EDT
   * which repeats the whole date for a one-hour event.
   */
  public function testSameDayEventRendersTheEndAsTimeOnly(): void {
    $instance = $this->instanceWithZone('America/Chicago', TRUE);
    $instance->set('date', [
      'value' => '2026-07-15T19:00:00',
      'end_value' => '2026-07-15T20:00:00',
    ]);
    $instance->save();

    $output = $this->renderDate($this->reloadInstance($instance), 'daterange_custom', [
      'timezone_override' => '',
      'date_format' => 'n/j/Y g:i A T',
      'from_to' => 'both',
      'separator' => '-',
    ]);

    $this->assertStringContainsString('07/15/26', $output,
      'the date is rendered once, with a two-digit year');
    $this->assertStringContainsString('2:00 PM', $output, 'the start time');
    $this->assertStringContainsString('3:00 PM CDT', $output,
      'and the end is a bare time carrying the zone label');
    $this->assertSame(1, substr_count($output, '07/15/26'),
      'the date appears exactly once, not on both endpoints');
  }

  /**
   * A cross-day event shows the full date on both endpoints.
   *
   * The other arm of the same-day split: when the event genuinely spans days,
   * the end date has to be stated or the reader cannot tell when it finishes.
   */
  public function testCrossDayEventRendersBothDates(): void {
    $instance = $this->instanceWithZone('America/Chicago', TRUE);
    $instance->set('date', [
      'value' => '2026-07-15T19:00:00',
      'end_value' => '2026-07-17T20:00:00',
    ]);
    $instance->save();

    $output = $this->renderDate($this->reloadInstance($instance), 'daterange_custom', [
      'timezone_override' => '',
      'date_format' => 'n/j/Y g:i A T',
      'from_to' => 'both',
      'separator' => '-',
    ]);

    $this->assertStringContainsString('07/15/26', $output, 'the start date');
    $this->assertStringContainsString('07/17/26', $output,
      'and the end date, because this one spans days');
    $this->assertStringContainsString(' to ', $output,
      "and the endpoints read ' to ', as a multi-day range always has");
  }

  /**
   * A formatter rendering only one endpoint is left alone.
   *
   * api/2.1/events is a data_export display using this same formatter id with
   * from_to 'start_date' and an ISO date_format, so a consumer parses what it
   * emits. Compacting a range makes no sense when only one endpoint is being
   * rendered, and rewriting it into prose corrupts the JSON.
   */
  public function testSingleEndpointRenderingIsNotRewritten(): void {
    $instance = $this->instanceWithZone('America/Chicago', TRUE);
    $instance->set('date', [
      'value' => '2026-07-15T19:00:00',
      'end_value' => '2026-07-15T20:00:00',
    ]);
    $instance->save();

    $output = $this->renderDate($this->reloadInstance($instance), 'daterange_custom', [
      'timezone_override' => '',
      'date_format' => 'Y-m-d\\TH:i:s\\Z',
      'from_to' => 'start_date',
      'separator' => '::',
    ]);

    $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $output,
      'the ISO instant survives untouched');
    $this->assertStringNotContainsString('PM', $output,
      'and no prose rendering leaks into it');
  }

  /**
   * An ONLINE event keeps the viewer-timezone cache context.
   *
   * This is the direction that leaks. An online event's rendered time
   * legitimately differs per viewer, so its render must carry the 'timezone'
   * context or the first viewer's copy is served to everyone. Core attaches
   * that context to the start_date and end_date CHILDREN of the delta, so a
   * compaction that rebuilds the delta and copies only the outer '#cache'
   * silently discards it — and nothing about the output looks wrong.
   *
   * A New York viewer would warm the cache with 10:00 AM EDT and a Los
   * Angeles viewer be served that same string, three hours off, labelled with
   * a zone the number was not computed in.
   */
  public function testOnlineEventKeepsTheViewerTimezoneCacheContext(): void {
    $instance = $this->instanceWithZone('America/Chicago', FALSE);

    $build = $this->reloadInstance($instance)->get('date')->view([
      'type' => 'daterange_custom',
      'label' => 'hidden',
      'settings' => [
        'timezone_override' => '',
        'date_format' => 'n/j/Y g:i A T',
        'from_to' => 'both',
        'separator' => '-',
      ],
    ]);

    $this->assertContains('timezone', $build[0]['#cache']['contexts'] ?? [],
      'an online event varies by viewer, so its render must say so');
  }

  /**
   * The series cache tag is attached whatever the current modality.
   *
   * The render depends on the series' timezone and modality fields regardless
   * of the values they hold right now. Tagging only in-person events means
   * flipping a series to in-person never invalidates the renders cached while
   * it was online, so they keep showing viewer-local times indefinitely.
   */
  public function testSeriesCacheTagIsAttachedForOnlineEventsToo(): void {
    $instance = $this->instanceWithZone('America/Chicago', FALSE);
    $series = $this->reloadInstance($instance)->getEventSeries();

    $build = $this->reloadInstance($instance)->get('date')->view([
      'type' => 'daterange_custom',
      'label' => 'hidden',
      'settings' => [
        'timezone_override' => '',
        'date_format' => 'n/j/Y g:i A T',
        'from_to' => 'both',
        'separator' => '-',
      ],
    ]);

    $this->assertContains('eventseries:' . $series->id(), $build['#cache']['tags'] ?? [],
      'so that marking the series in-person invalidates this render');
  }

  /**
   * Renders an instance's date field through a named formatter.
   */
  private function renderDate(EventInstance $instance, string $formatter, array $settings): string {
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $instance->get('date');
    $build = $items->view([
      'type' => $formatter,
      'label' => 'hidden',
      'settings' => $settings,
    ]);
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

  /**
   * An online event still converts to the viewer's zone.
   *
   * This is the common case — roughly two thirds of production events — and it
   * must not change. The number and the label both move to the viewer.
   */
  public function testOnlineEventRendersInTheViewersZone(): void {
    $instance = $this->instanceWithZone('America/Chicago', FALSE);
    $original = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');
    try {
      $output = $this->renderDate($instance, 'daterange_custom', [
        'date_format' => 'n/j/Y g:i A T',
        'separator' => '-',
        'from_to' => 'both',
        'timezone_override' => '',
      ]);
    }
    finally {
      date_default_timezone_set($original);
    }

    $this->assertStringContainsString('PDT', $output,
      'an online event shows the viewer their own zone');
    $this->assertStringNotContainsString('CDT', $output,
      'and not the event zone, which would be a different instant');
  }

  /**
   * An in-person event renders in the venue's zone, identically for everyone.
   */
  public function testInPersonEventRendersInTheVenueZoneForEveryViewer(): void {
    $instance = $this->instanceWithZone('America/Chicago', TRUE);

    $rendered = [];
    $original = date_default_timezone_get();
    foreach (['America/New_York', 'America/Los_Angeles', 'UTC'] as $viewerZone) {
      date_default_timezone_set($viewerZone);
      try {
        $rendered[$viewerZone] = $this->renderDate($instance, 'daterange_custom', [
          'date_format' => 'n/j/Y g:i A T',
          'separator' => '-',
          'from_to' => 'both',
          'timezone_override' => '',
        ]);
      }
      finally {
        date_default_timezone_set($original);
      }
    }

    foreach ($rendered as $viewerZone => $output) {
      $this->assertStringContainsString('CDT', $output,
        "a viewer in $viewerZone sees the venue's zone");
    }
    $this->assertCount(1, array_unique($rendered),
      'and every viewer sees exactly the same string');
  }

  /**
   * The in-person branch also reaches the views formatter.
   *
   * daterange_default takes a different render path — buildDateWithIsoAttribute
   * rather than buildDate — and six views use it, including the main events
   * listing and the API view. An override that covers only daterange_custom
   * fixes the detail page while every listing keeps rendering viewer-local.
   */
  public function testInPersonBranchAppliesToTheViewsFormatterToo(): void {
    // Deliberately NOT America/Chicago, which every other case here uses: a
    // formatter that hardcoded the zone rather than reading the event's own
    // would satisfy all of those and fail only this one.
    $instance = $this->instanceWithZone('America/Denver', TRUE);

    $original = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');
    try {
      $output = $this->renderDate($instance, 'daterange_default', [
        'format_type' => 'html_datetime',
        'separator' => '::',
        'from_to' => 'both',
        'timezone_override' => '',
      ]);
    }
    finally {
      date_default_timezone_set($original);
    }

    // Denver in July is UTC-6; a Los Angeles viewer would get -0700. The 'O'
    // format emits the offset without a colon.
    $this->assertStringContainsString('-0600', $output,
      'the views formatter emits the venue offset, not the viewer’s');
    $this->assertStringNotContainsString('-0700', $output,
      'and specifically not the viewer’s offset');
    $this->assertStringNotContainsString('-0500', $output,
      'and not Chicago’s either — the zone is read from the event, not assumed');
    $this->assertStringContainsString('T13:00:00', $output,
      'the wall clock is the venue’s 1pm, not the viewer’s noon');
  }

  /**
   * A zero-duration event exercises the other arm of the equality split.
   *
   * viewElements() branches on whether start and end share a timestamp, and
   * the arm for equal timestamps is the one no ordinary event reaches. It is
   * also the shape that breaks the display alter this work removes.
   */
  public function testZeroDurationInPersonEventStillRendersTheVenueZone(): void {
    $instance = $this->instanceWithZone('America/Chicago', TRUE);
    $instance->set('date', [
      'value' => '2026-07-15T19:00:00',
      'end_value' => '2026-07-15T19:00:00',
    ]);
    $instance->save();

    $original = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');
    try {
      $output = $this->renderDate($this->reloadInstance($instance), 'daterange_custom', [
        'date_format' => 'n/j/Y g:i A T',
        'separator' => '-',
        'from_to' => 'both',
        'timezone_override' => '',
      ]);
    }
    finally {
      date_default_timezone_set($original);
    }

    $this->assertStringContainsString('CDT', $output,
      'the equal-timestamp arm resolves the venue zone as well');
  }

  /**
   * The render depends on the series, so it must carry the series cache tag.
   *
   * The fields live on the series while these surfaces render the instance, and
   * neither contrib module bubbles any cacheability for an inherited value.
   * Without the tag, editing a series' timezone leaves every cached instance
   * render showing the old zone until something else invalidates it.
   */
  public function testRenderCarriesTheSeriesCacheTag(): void {
    $instance = $this->instanceWithZone('America/Chicago', TRUE);
    $seriesTag = 'eventseries:' . $instance->getEventSeries()->id();

    $build = $instance->get('date')->view([
      'type' => 'daterange_custom',
      'label' => 'hidden',
      'settings' => [
        'date_format' => 'n/j/Y g:i A T',
        'separator' => '-',
        'from_to' => 'both',
        'timezone_override' => '',
      ],
    ]);
    $renderer = \Drupal::service('renderer');
    $renderer->renderInIsolation($build);

    $tags = $build['#cache']['tags'] ?? [];
    $this->assertContains($seriesTag, $tags,
      'a timezone edit on the series invalidates this render');
  }

}
