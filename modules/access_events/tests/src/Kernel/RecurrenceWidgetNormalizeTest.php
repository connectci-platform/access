<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;

/**
 * Tests that recurrence widgets store a picked date as noon UTC.
 *
 * The date-only picker builds its value in the editor's zone with the time of
 * submission, so storing that instant would make the stored UTC date depend on
 * when and where the editor saved. The widgets' massageFormValues() replaces
 * it with noon UTC on the picked calendar date (D8-2947).
 *
 * @group access_events
 */
class RecurrenceWidgetNormalizeTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
  }

  /**
   * The weekly widget stores noon UTC of the editor's picked date.
   *
   * 22:30 MST on 2099-03-02 is 05:30 UTC on 2099-03-03, so a naive UTC
   * conversion would store the wrong date.
   */
  public function testWeeklyWidgetStoresNoonUtcOfPickedDate(): void {
    $input = [
      'value' => $this->denverEvening('2099-03-02'),
      'end_value' => $this->denverEvening('2099-03-30'),
      'time' => '02:00 pm',
      'end_time' => ['time' => '03:00 pm'],
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => ['monday' => 'monday'],
    ];

    $result = $this->massage('weekly_recurring_date', $input);

    $this->assertSame('2099-03-02T12:00:00', $result[0]['value']);
    $this->assertSame('2099-03-30T12:00:00', $result[0]['end_value']);
    $this->assertSame('monday', $result[0]['days']);
    $this->assertDenverEvening($input['value'], '2099-03-02');
    $this->assertDenverEvening($input['end_value'], '2099-03-30');
  }

  /**
   * The consecutive widget stores noon UTC of the editor's picked date.
   */
  public function testConsecutiveWidgetStoresNoonUtcOfPickedDate(): void {
    $input = [
      'value' => $this->denverEvening('2099-03-02'),
      'end_value' => $this->denverEvening('2099-03-30'),
      'time' => '09:00 am',
      'end_time' => '05:00 pm',
      'duration' => 30,
      'duration_units' => 'minute',
      'buffer' => 15,
      'buffer_units' => 'minute',
    ];

    $result = $this->massage('consecutive_recurring_date', $input);

    $this->assertSame('2099-03-02T12:00:00', $result[0]['value']);
    $this->assertSame('2099-03-30T12:00:00', $result[0]['end_value']);
    $this->assertDenverEvening($input['value'], '2099-03-02');
    $this->assertDenverEvening($input['end_value'], '2099-03-30');
  }

  /**
   * Runs the widget's massageFormValues() on one item.
   */
  private function massage(string $widgetId, array $item): array {
    $definition = \Drupal::service('entity_field.manager')
      ->getBaseFieldDefinitions('eventseries')[$widgetId];
    $widget = \Drupal::service('plugin.manager.field.widget')->getInstance([
      'field_definition' => $definition,
      'form_mode' => 'default',
      'prepare' => TRUE,
      'configuration' => ['type' => $widgetId],
    ]);
    $this->assertNotNull($widget);
    $form = [];
    return $widget->massageFormValues([$item], $form, new FormState());
  }

  /**
   * A 22:30 Denver date, the case where UTC and Denver dates differ.
   */
  private function denverEvening(string $date): DrupalDateTime {
    return new DrupalDateTime($date . ' 22:30:00', new \DateTimeZone('America/Denver'));
  }

  /**
   * Asserts the input object still has its original zone and time.
   */
  private function assertDenverEvening(DrupalDateTime $date, string $day): void {
    $this->assertSame('America/Denver', $date->getTimezone()->getName());
    $this->assertSame($day . ' 22:30:00', $date->format('Y-m-d H:i:s'));
  }

}
