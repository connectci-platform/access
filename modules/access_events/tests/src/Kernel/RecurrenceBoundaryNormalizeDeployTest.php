<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\User;

/**
 * Tests the deploy hook that normalizes recurrence-rule boundaries.
 *
 * Legacy rows hold the editor's moment of submission in UTC (wrong calendar
 * day when read as UTC) or an API-written T00:00:00. The hook rewrites them to
 * noon UTC on the intended date, with direct queries so no instance is
 * regenerated.
 *
 * @group access_events
 */
class RecurrenceBoundaryNormalizeDeployTest extends EventKernelTestBase {

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
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    \Drupal::moduleHandler()->loadInclude('access_events', 'php', 'access_events.deploy');
  }

  /**
   * Creates a weekly series authored by a user in the given account zone.
   */
  private function createWeekly(string $suffix, string $userZone, string $seriesZone = 'America/Chicago'): EventSeries {
    $author = User::create([
      'name' => 'author_' . $suffix,
      'mail' => $suffix . '@example.com',
      'status' => 1,
      'timezone' => $userZone,
    ]);
    $author->save();
    $series = EventSeries::create([
      'title' => 'Series ' . $suffix,
      'type' => 'default',
      'uid' => $author->id(),
      'revision_user' => $author->id(),
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => [
        'value' => '2099-03-02T12:00:00',
        'end_value' => '2099-03-30T12:00:00',
        'time' => '02:00 PM',
        'end_time' => '03:00 PM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
        'days' => 'monday',
      ],
      'field_event_timezone' => $seriesZone,
    ]);
    $series->save();
    return $series;
  }

  /**
   * Writes raw legacy values into the data and current revision tables.
   */
  private function setRaw(EventSeries $series, string $field, ?string $value, ?string $end): void {
    $db = \Drupal::database();
    foreach (['eventseries_field_data', 'eventseries_field_revision'] as $table) {
      $db->update($table)
        ->fields([$field . '__value' => $value, $field . '__end_value' => $end])
        ->condition('id', $series->id())
        ->condition('vid', $series->getRevisionId())
        ->execute();
    }
    \Drupal::entityTypeManager()->getStorage('eventseries')->resetCache([$series->id()]);
  }

  /**
   * Reads raw [value, end_value] from both tables.
   *
   * @return array<string, array<int, string|null>>
   *   Keyed by table.
   */
  private function raw(EventSeries $series, string $field): array {
    $db = \Drupal::database();
    $out = [];
    foreach (['eventseries_field_data', 'eventseries_field_revision'] as $table) {
      $row = $db->select($table, 't')
        ->fields('t', [$field . '__value', $field . '__end_value'])
        ->condition('id', $series->id())
        ->condition('vid', $series->getRevisionId())
        ->execute()->fetchAssoc();
      $out[$table] = array_values((array) $row);
    }
    return $out;
  }

  /**
   * Asserts both tables hold the expected pair.
   */
  private function assertStoredPair(EventSeries $series, array $expected, string $message = ''): void {
    foreach ($this->raw($series, 'weekly_recurring_date') as $table => $pair) {
      $this->assertSame($expected, $pair, $table . ' ' . $message);
    }
  }

  /**
   * A Denver editor's late-evening submission lands on the editor's date.
   */
  public function testSubmissionMomentUsesRevisionAuthorZone(): void {
    $series = $this->createWeekly('denver', 'America/Denver');
    $this->setRaw($series, 'weekly_recurring_date', '2024-10-17T04:06:43', '2024-11-18T05:30:00');

    $message = (string) access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertStoredPair($series, ['2024-10-16T12:00:00', '2024-11-17T12:00:00']);
    $this->assertStringContainsString('2 recurrence boundaries', $message);
    $this->assertStringContainsString('2 changed calendar date', $message);
    $this->assertStringContainsString((string) $series->id(), $message);
  }

  /**
   * API-style midnight keeps its UTC date.
   */
  public function testApiMidnightKeepsUtcDate(): void {
    $series = $this->createWeekly('api', 'America/Denver');
    $this->setRaw($series, 'weekly_recurring_date', '2099-06-01T00:00:00', '2099-07-01T00:00:00');

    $message = (string) access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertStoredPair($series, ['2099-06-01T12:00:00', '2099-07-01T12:00:00']);
    $this->assertStringContainsString('0 changed calendar date', $message);
    $this->assertStringContainsString('2 changed only the time', $message);
  }

  /**
   * Already-normalized values are untouched and not counted.
   */
  public function testNoonValuesAreUntouched(): void {
    $series = $this->createWeekly('noon', 'America/Denver');
    $this->setRaw($series, 'weekly_recurring_date', '2099-06-01T12:00:00', '2099-07-01T12:00:00');

    $message = (string) access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertStoredPair($series, ['2099-06-01T12:00:00', '2099-07-01T12:00:00']);
    $this->assertStringContainsString('No recurrence boundaries', $message);
  }

  /**
   * Without an author zone the series' event timezone is used.
   */
  public function testFallsBackToSeriesTimezone(): void {
    $series = $this->createWeekly('nozone', '', 'America/New_York');
    $this->setRaw($series, 'weekly_recurring_date', '2024-10-17T03:30:00', NULL);

    access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertStoredPair($series, ['2024-10-16T12:00:00', NULL]);
  }

  /**
   * A custom recur_type series is not touched.
   */
  public function testCustomSeriesIsNotTouched(): void {
    $series = EventSeries::create([
      'title' => 'Custom',
      'type' => 'default',
      'recur_type' => 'custom',
      'custom_date' => [
        [
          'value' => '2099-07-15T18:00:00',
          'end_value' => '2099-07-15T19:00:00',
        ],
      ],
    ]);
    $series->save();
    $this->setRaw($series, 'weekly_recurring_date', '2024-10-17T04:06:43', '2024-10-18T04:06:43');

    access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertStoredPair($series, ['2024-10-17T04:06:43', '2024-10-18T04:06:43']);
  }

  /**
   * Event instances are neither regenerated nor re-saved.
   */
  public function testInstancesAreUntouched(): void {
    $series = $this->createWeekly('inst', 'America/Denver');
    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $snapshot = static function () use ($storage): array {
      $storage->resetCache();
      $out = [];
      foreach ($storage->loadMultiple() as $id => $instance) {
        $out[$id] = $instance->getChangedTime();
      }
      ksort($out);
      return $out;
    };
    $before = $snapshot();
    $this->assertNotEmpty($before, 'the series generated instances');
    $this->setRaw($series, 'weekly_recurring_date', '2024-10-17T04:06:43', '2024-11-18T05:30:00');

    access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertSame($before, $snapshot());
  }

  /**
   * Running twice gives the same values and the second run rewrites nothing.
   */
  public function testIdempotent(): void {
    $series = $this->createWeekly('idem', 'America/Denver');
    $this->setRaw($series, 'weekly_recurring_date', '2024-10-17T04:06:43', '2024-11-18T05:30:00');

    access_events_deploy_0006_normalize_recurrence_boundaries();
    $first = $this->raw($series, 'weekly_recurring_date');
    $message = (string) access_events_deploy_0006_normalize_recurrence_boundaries();

    $this->assertSame($first, $this->raw($series, 'weekly_recurring_date'));
    $this->assertStringContainsString('No recurrence boundaries', $message);
  }

}
