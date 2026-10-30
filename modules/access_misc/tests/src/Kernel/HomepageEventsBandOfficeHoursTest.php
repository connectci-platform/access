<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\access_misc\Services\OfficeHoursLookup;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\Entity\EventSeriesType;

/**
 * Tests the "Next:" office hours lookup of the homepage events band.
 *
 * @covers \Drupal\access_misc\Services\OfficeHoursLookup
 * @group access_misc
 */
class HomepageEventsBandOfficeHoursTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'link',
    'datetime',
    'datetime_range',
    'field_inheritance',
    'recurring_events',
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('eventseries');
    $this->installEntitySchema('eventinstance');
    $this->config('field_inheritance.config')
      ->set('included_entities', ['eventinstance'])
      ->save();
    $this->installConfig(['recurring_events']);
    $seriesType = EventSeriesType::load('default');
    $seriesType->setValidateRecurringDate(FALSE);
    $seriesType->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    // The lookup runs with access checking, as for an anonymous visitor.
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());
  }

  /**
   * Creates a series with one instance per start time (UTC).
   *
   * @param string $title
   *   The series title.
   * @param string[] $starts
   *   Instance start times, 'Y-m-d\TH:i:s' in UTC.
   *
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   *   The instances, in the order of $starts.
   */
  protected function createSeries(string $title, array $starts): array {
    $dates = [];
    foreach ($starts as $start) {
      $end = gmdate('Y-m-d\TH:i:s', strtotime($start . ' UTC') + 3600);
      $dates[] = ['value' => $start, 'end_value' => $end];
    }
    $series = EventSeries::create([
      'title' => $title,
      'recur_type' => 'custom',
      'type' => 'default',
      'status' => 1,
      'custom_date' => $dates,
    ]);
    $series->save();
    $instances = array_values($series->getInstances());
    usort($instances, fn(EventInstance $a, EventInstance $b) => strcmp($a->date->value, $b->date->value));
    return $instances;
  }

  /**
   * Returns the lookup service.
   */
  protected function lookup(): OfficeHoursLookup {
    return \Drupal::service('access_misc.office_hours_lookup');
  }

  /**
   * Only the next two future NAIRR sessions come back, soonest first.
   */
  public function testReturnsNextTwoFutureSessionsSoonestFirst(): void {
    // Two series with the same title, as on the site; their sessions
    // interleave.
    $this->createSeries(OfficeHoursLookup::SERIES_TITLE, [
      '2000-01-04T20:00:00',
      '2999-01-08T17:00:00',
      '2999-01-20T17:00:00',
    ]);
    $this->createSeries(OfficeHoursLookup::SERIES_TITLE, [
      '2999-01-07T17:00:00',
      '2999-01-30T17:00:00',
    ]);

    $next = $this->lookup()->getNext(2);

    // Formatted in the site time zone ("D n/j").
    $this->assertSame(['Mon 1/7', 'Tue 1/8'], array_column($next, 'label'));
    $this->assertLessThan($next[1]['start'], $next[0]['start']);
  }

  /**
   * Past, unpublished and other-series instances are ignored.
   */
  public function testIgnoresPastUnpublishedAndOtherSeries(): void {
    $this->createSeries('Some Other Event', ['2999-01-05T17:00:00']);
    $past = $this->createSeries(OfficeHoursLookup::SERIES_TITLE, ['2000-01-04T20:00:00']);
    $this->assertCount(1, $past);
    $nairr = $this->createSeries(OfficeHoursLookup::SERIES_TITLE, [
      '2999-01-06T17:00:00',
      '2999-01-08T17:00:00',
    ]);
    $nairr[0]->set('status', 0)->save();

    $next = $this->lookup()->getNext(2);

    $this->assertSame(['Tue 1/8'], array_column($next, 'label'));
  }

  /**
   * With no upcoming sessions the lookup is empty.
   */
  public function testEmptyWhenNothingUpcoming(): void {
    $this->createSeries(OfficeHoursLookup::SERIES_TITLE, ['2000-01-04T20:00:00']);
    $this->assertSame([], $this->lookup()->getNext(2));
  }

}
