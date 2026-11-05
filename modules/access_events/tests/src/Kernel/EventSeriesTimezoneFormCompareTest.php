<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests comparing the series edit form to the stored recurrence config.
 *
 * The recurring_events module decides whether a save reschedules every
 * instance by comparing the submitted form's recurrence config to the stored
 * series'. The stored side resolves dates in the series' own timezone, so the
 * form side must too: if it used the editor's zone, a Denver editor saving an
 * unchanged New York series would get "changed" every time. That rebuilt every
 * instance and showed a misleading status message (D8-2947).
 *
 * The editor's ambient zone is America/Denver throughout, and every series is
 * stored in America/New_York, so the two zones always differ.
 *
 * @group access_events
 */
class EventSeriesTimezoneFormCompareTest extends EventKernelTestBase {

  /**
   * The ambient zone before the test changed it.
   */
  private string $originalTimezone;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
    foreach (['html_time' => 'H:i:s', 'html_date' => 'Y-m-d'] as $id => $pattern) {
      if (!\Drupal::entityTypeManager()->getStorage('date_format')->load($id)) {
        \Drupal::entityTypeManager()->getStorage('date_format')->create([
          'id' => $id,
          'label' => $id,
          'locked' => TRUE,
          'pattern' => $pattern,
        ])->save();
      }
    }
    $this->originalTimezone = date_default_timezone_get();
    date_default_timezone_set('America/Denver');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    date_default_timezone_set($this->originalTimezone);
    parent::tearDown();
  }

  /**
   * An unchanged weekly form is not a recurrence change.
   */
  public function testUnchangedWeeklyFormReportsNoChange(): void {
    $series = $this->createSeries('weekly_recurring_date', $this->weeklyValues());
    $formState = $this->formState('weekly_recurring_date', $this->weeklyInput(), 'America/New_York');

    $this->assertFalse($this->creationService()->checkForFormRecurConfigChanges($series, $formState));
  }

  /**
   * An unchanged yearly form is not a recurrence change.
   *
   * Yearly carries event_timezone in its config, so this also checks that the
   * form side adds the same key the stored side does.
   */
  public function testUnchangedYearlyFormReportsNoChange(): void {
    $series = $this->createSeries('yearly_recurring_date', [
      'value' => '2099-03-02T17:00:00',
      'end_value' => '2101-03-30T17:00:00',
      'time' => '02:00 PM',
      'end_time' => '03:00 PM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'type' => 'monthday',
      'day_of_month' => '15',
      'year_interval' => 1,
      'months' => 'march',
    ]);
    $formState = $this->formState('yearly_recurring_date', [
      'value' => $this->formDate('2099-03-02'),
      'end_value' => $this->formDate('2101-03-30'),
      'time' => '02:00 pm',
      'end_time' => '03:00 pm',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'type' => 'monthday',
      'day_of_month' => ['15' => '15'],
      'year_interval' => 1,
      'months' => ['march' => 'march'],
    ], 'America/New_York');

    $this->assertFalse($this->creationService()->checkForFormRecurConfigChanges($series, $formState));
  }

  /**
   * An unchanged consecutive form is not a recurrence change.
   *
   * Consecutive builds its form config separately from the other types and
   * also carries event_timezone.
   */
  public function testUnchangedConsecutiveFormReportsNoChange(): void {
    $series = $this->createSeries('consecutive_recurring_date', [
      'value' => '2099-03-02T17:00:00',
      'end_value' => '2099-03-04T17:00:00',
      'time' => '09:00 AM',
      'end_time' => '05:00 PM',
      'duration' => 30,
      'duration_units' => 'minute',
      'buffer' => 15,
      'buffer_units' => 'minute',
    ]);
    $formState = $this->formState('consecutive_recurring_date', [
      'value' => $this->formDate('2099-03-02'),
      'end_value' => $this->formDate('2099-03-04'),
      'time' => '09:00 am',
      'end_time' => '05:00 pm',
      'duration' => 30,
      'duration_units' => 'minute',
      'buffer' => 15,
      'buffer_units' => 'minute',
    ], 'America/New_York');

    $this->assertFalse($this->creationService()->checkForFormRecurConfigChanges($series, $formState));
  }

  /**
   * Changing only the timezone of a weekly series is a recurrence change.
   *
   * Weekly config has no event_timezone key, so only the zone on the resolved
   * start and end dates can reveal the change. If it were missed, the
   * instances would keep their old times under the new zone.
   */
  public function testTimezoneOnlyChangeOnWeeklyReportsChange(): void {
    $series = $this->createSeries('weekly_recurring_date', $this->weeklyValues());
    $formState = $this->formState('weekly_recurring_date', $this->weeklyInput(), 'America/Los_Angeles');

    $this->assertTrue($this->creationService()->checkForFormRecurConfigChanges($series, $formState));
  }

  /**
   * A timezone change gives the confirm step a Timezone row to show.
   */
  public function testTimezoneChangeProducesDiffRow(): void {
    $series = $this->createSeries('weekly_recurring_date', $this->weeklyValues());
    $formState = $this->formState('weekly_recurring_date', $this->weeklyInput(), 'America/Los_Angeles');

    $diff = $this->creationService()->buildDiffArray($series, $formState);

    $this->assertArrayHasKey('timezone', $diff);
    $this->assertSame('Timezone', (string) $diff['timezone']['label']);
    $this->assertSame('America/New_York', $diff['timezone']['stored']);
    $this->assertSame('America/Los_Angeles', $diff['timezone']['override']);
  }

  /**
   * An unchanged form gives no Timezone row.
   */
  public function testUnchangedFormProducesNoTimezoneDiffRow(): void {
    $series = $this->createSeries('weekly_recurring_date', $this->weeklyValues());
    $formState = $this->formState('weekly_recurring_date', $this->weeklyInput(), 'America/New_York');

    $diff = $this->creationService()->buildDiffArray($series, $formState);

    $this->assertArrayNotHasKey('timezone', $diff);
  }

  /**
   * Stored values for the weekly series used by several tests.
   */
  private function weeklyValues(): array {
    return [
      'value' => '2099-03-02T17:00:00',
      'end_value' => '2099-03-30T17:00:00',
      'time' => '02:00 PM',
      'end_time' => '03:00 PM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => 'monday',
    ];
  }

  /**
   * Form input that matches weeklyValues().
   */
  private function weeklyInput(): array {
    return [
      'value' => $this->formDate('2099-03-02'),
      'end_value' => $this->formDate('2099-03-30'),
      'time' => '02:00 pm',
      'end_time' => '03:00 pm',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => ['monday' => 'monday'],
    ];
  }

  /**
   * Creates a series of the given recur type, stored in America/New_York.
   */
  private function createSeries(string $recurType, array $values): EventSeries {
    $series = EventSeries::create([
      'title' => 'Timezone compare',
      'type' => 'default',
      'recur_type' => $recurType,
      $recurType => $values,
      'field_event_timezone' => 'America/New_York',
    ]);
    $series->save();
    return EventSeries::load($series->id());
  }

  /**
   * Builds the submitted values the series edit form would produce.
   *
   * @param string $recurType
   *   The recur type field name.
   * @param array $fieldInput
   *   The recurrence widget's values for delta 0.
   * @param string $timezone
   *   The timezone the editor submitted.
   */
  private function formState(string $recurType, array $fieldInput, string $timezone): FormState {
    $formState = new FormState();
    $formState->setValues([
      'recur_type' => [0 => ['value' => $recurType]],
      'field_event_timezone' => [0 => ['value' => $timezone]],
      $recurType => [0 => $fieldInput],
      'excluded_dates' => [],
      'included_dates' => [],
    ]);
    return $formState;
  }

  /**
   * A date as the recurrence widget submits it, in the editor's zone.
   *
   * The recurring date fields store full UTC datetimes, so the widget shows
   * and returns them in the editor's ambient zone (Denver). The fixtures
   * store 17:00 UTC, which is Denver mid-morning on the same day, so noon
   * Denver is the same calendar day the editor saw. Noon also stays on that
   * day when re-zoned to any US zone, so a timezone-only change can't look
   * like a change of date. Built fresh for each use because
   * getDateFromRecurringDateFieldInput() changes the object's timezone in
   * place.
   */
  private function formDate(string $date): DrupalDateTime {
    return new DrupalDateTime($date . ' 12:00:00', new \DateTimeZone('America/Denver'));
  }

  /**
   * The recurring_events event creation service.
   */
  private function creationService() {
    return \Drupal::service('recurring_events.event_creation_service');
  }

}
