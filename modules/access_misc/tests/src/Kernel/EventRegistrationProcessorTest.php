<?php

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\access_misc\Plugin\search_api\processor\EventRegistration;

/**
 * @group access_misc
 */
class EventRegistrationProcessorTest extends KernelTestBase {

  // Deliberately NOT enabling access_misc (heavy install hooks). We test the
  // processor CLASS directly. recurring_events pulls field_inheritance; add its
  // deps explicitly since KernelTestBase doesn't resolve chains.
  protected static $modules = [
    'recurring_events', 'recurring_events_registration', 'field_inheritance',
    'search_api', 'user', 'system', 'field', 'datetime', 'datetime_range', 'options', 'text', 'filter',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('eventseries');
    $this->installEntitySchema('eventinstance');
    $this->installEntitySchema('registrant');
    $this->installEntitySchema('user');
    $this->installConfig(['field_inheritance', 'recurring_events', 'recurring_events_registration']);
  }

  private function makeInstance(int $enabled, ?int $capacity, int $waitlist): EventInstance {
    $series = EventSeries::create([
      'type' => 'default',
      'title' => 'Test Series',
      // 'custom' with no custom_dates saves the series without triggering
      // instance creation (which fatals on the empty recur-type plugin id).
      'recur_type' => 'custom',
      'event_registration' => [
        'registration' => $enabled,
        'capacity' => $capacity,
        'waitlist' => $waitlist,
      ],
    ]);
    $series->save();
    $instance = EventInstance::create(['type' => 'default', 'eventseries_id' => $series->id()]);
    $instance->save();
    return $instance;
  }

  /** The three registration values the processor reads off the series. */
  private function extract(EventInstance $instance): array {
    $series = $instance->getEventSeries();
    $reg = $series->get('event_registration')->first();
    return [
      'enabled' => $reg ? (bool) $reg->registration : FALSE,
      'capacity' => $reg && $reg->capacity ? (int) $reg->capacity : 0,
      'waitlist' => $reg ? (bool) $reg->waitlist : FALSE,
    ];
  }

  public function testProcessorExposesThreeProperties(): void {
    $processor = new EventRegistration([], 'custom_event_registration', []);
    $props = $processor->getPropertyDefinitions(NULL);
    $this->assertArrayHasKey('search_api_registration_enabled', $props);
    $this->assertArrayHasKey('search_api_registration_capacity', $props);
    $this->assertArrayHasKey('search_api_registration_has_waitlist', $props);
  }

  public function testEnabledSeriesWithCapacityAndWaitlist(): void {
    $out = $this->extract($this->makeInstance(1, 60, 1));
    $this->assertTrue($out['enabled']);
    $this->assertSame(60, $out['capacity']);
    $this->assertTrue($out['waitlist']);
  }

  public function testDisabledSeries(): void {
    $out = $this->extract($this->makeInstance(0, NULL, 0));
    $this->assertFalse($out['enabled']);
    $this->assertSame(0, $out['capacity']);
    $this->assertFalse($out['waitlist']);
  }
}
