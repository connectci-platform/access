<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests the warning shown before a timezone change reschedules an event.
 *
 * A timezone edit is a reschedule. If an event was recorded as Chicago and is
 * really New York, it happens an hour earlier than everyone was told, so the
 * occurrences genuinely should move — and registrants deserve the same
 * treatment any other schedule change gives them.
 *
 * Because generation resolves the series' stored zone, the field takes part in
 * recur-config change detection, so saving a changed zone rebuilds the
 * occurrences immediately. Verified directly: a zone-only save moved a weekly
 * series from 19:00/18:00 UTC to 22:00/21:00. On a series with registrations
 * that meets the existing reschedule guard, which refuses the save.
 *
 * The warning exists so either outcome is expected rather than a surprise.
 *
 * @group access_events
 */
class EventTimezoneChangeWarningTest extends EventKernelTestBase {

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
  }

  /**
   * Builds a future weekly series generated under a given ambient zone.
   */
  private function futureWeeklySeries(string $storedZone, string $generatedUnder): EventSeries {
    $original = date_default_timezone_get();
    date_default_timezone_set($generatedUnder);
    try {
      $series = EventSeries::create([
        'title' => 'Drift check',
        'type' => 'default',
        'recur_type' => 'weekly_recurring_date',
        'weekly_recurring_date' => [
          'value' => '2099-03-02T00:00:00',
          'end_value' => '2099-03-30T00:00:00',
          'time' => '02:00 PM',
          'end_time' => '03:00 PM',
          'duration' => 3600,
          'duration_or_end_time' => 'end_time',
          'days' => 'monday',
        ],
      ]);
      $series->save();
    }
    finally {
      date_default_timezone_set($original);
    }

    // Set the stored zone AFTER generation, which is exactly what the backfill
    // does to every existing series.
    $series->set('field_event_timezone', $storedZone);
    $series->save();

    return $series;
  }

  /**
   * A rule series with future occurrences warns that they will move.
   */
  public function testWarnsThatUpcomingOccurrencesWillMove(): void {
    $series = $this->futureWeeklySeries('America/New_York', 'America/New_York');

    $warning = \Drupal::service('access_events.form_warnings')->timezoneChangeWarning($series);

    $this->assertNotNull($warning, 'the editor is told before they change it');
    $this->assertStringContainsString('moves its upcoming occurrences', (string) $warning,
      'and told what the change actually does');
  }

  /**
   * A series with registrations is told the save will be refused.
   *
   * This is the branch that matters most to an editor: it is the one telling
   * them the change will be rejected and what to do instead. Both branches
   * contain "moves its upcoming occurrences", so a test asserting only that
   * substring cannot tell them apart.
   */
  public function testRegisteredSeriesIsToldTheSaveWillBeRefused(): void {
    $series = $this->futureWeeklySeriesWithRegistration();
    $instance = $this->firstInstanceOf($series);
    $this->registerUser($this->createUser([], 'registrant'), $instance);

    $warning = (string) \Drupal::service('access_events.form_warnings')
      ->timezoneChangeWarning($series);

    $this->assertStringContainsString('refused', $warning,
      'the editor learns the save will not go through');
    $this->assertStringContainsString('cancel the event', $warning,
      'and is told the route that does work');
  }

  /**
   * The same series without registrations gets the plain warning.
   *
   * Pins the branch boundary: without this, an implementation that always
   * returns the refusal text would pass the test above.
   */
  public function testUnregisteredSeriesIsNotToldAboutRefusal(): void {
    $series = $this->futureWeeklySeriesWithRegistration();

    $warning = (string) \Drupal::service('access_events.form_warnings')
      ->timezoneChangeWarning($series);

    $this->assertStringNotContainsString('refused', $warning,
      'with no registrations there is nothing to refuse');
    $this->assertStringContainsString('regenerated on save', $warning,
      'but the occurrences still move, and the editor is told so');
  }

  /**
   * A future rule-based series that accepts registrations.
   */
  private function futureWeeklySeriesWithRegistration(): EventSeries {
    $original = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
      $series = EventSeries::create([
        'title' => 'Registrable recurring event',
        'body' => 'The full event description.',
        'type' => 'default',
        'recur_type' => 'weekly_recurring_date',
        'field_event_timezone' => 'America/New_York',
        'event_registration' => [
          'registration' => 1,
          'registration_type' => 'instance',
          'registration_dates' => 'open',
          'capacity' => 60,
          'waitlist' => 0,
        ],
        'weekly_recurring_date' => [
          'value' => '2099-03-02T00:00:00',
          'end_value' => '2099-03-30T00:00:00',
          'time' => '02:00 PM',
          'end_time' => '03:00 PM',
          'duration' => 3600,
          'duration_or_end_time' => 'end_time',
          'days' => 'monday',
        ],
      ]);
      $series->save();
    }
    finally {
      date_default_timezone_set($original);
    }
    $this->publishModerated($series);

    return \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
  }

  /**
   * The first generated occurrence of a series.
   */
  private function firstInstanceOf(EventSeries $series) {
    $ids = \Drupal::entityQuery('eventinstance')
      ->accessCheck(FALSE)
      ->condition('eventseries_id', $series->id())
      ->sort('date.value', 'ASC')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($ids, 'the series generated occurrences');
    return \Drupal::entityTypeManager()->getStorage('eventinstance')
      ->load(reset($ids));
  }

  /**
   * A custom-date series never warns.
   *
   * Custom dates are stored instants rather than a rule to re-expand, so the
   * zone does not move them. 904 of the 962 production series are custom, and
   * warning on all of them would teach editors to ignore the message.
   */
  public function testCustomDateSeriesNeverWarns(): void {
    $series = EventSeries::create([
      'title' => 'Custom dates',
      'type' => 'default',
      'recur_type' => 'custom',
      'field_event_timezone' => 'Australia/Perth',
      'custom_date' => [[
        'value' => '2099-07-15T18:00:00',
        'end_value' => '2099-07-15T19:00:00',
      ]],
    ]);
    $series->save();

    $this->assertNull(
      \Drupal::service('access_events.form_warnings')->timezoneChangeWarning($series),
      'a custom-date series has no rule to re-expand, so its zone cannot move it'
    );
  }

  /**
   * A series whose occurrences are all past never warns.
   *
   * A rebuild cannot move an occurrence that has already happened.
   */
  public function testPastSeriesNeverWarns(): void {
    $original = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
      $series = EventSeries::create([
        'title' => 'Past series',
        'type' => 'default',
        'recur_type' => 'weekly_recurring_date',
        'weekly_recurring_date' => [
          'value' => '2020-03-02T00:00:00',
          'end_value' => '2020-03-30T00:00:00',
          'time' => '02:00 PM',
          'end_time' => '03:00 PM',
          'duration' => 3600,
          'duration_or_end_time' => 'end_time',
          'days' => 'monday',
        ],
      ]);
      $series->save();
    }
    finally {
      date_default_timezone_set($original);
    }

    $this->assertNull(
      \Drupal::service('access_events.form_warnings')->timezoneChangeWarning(
        \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id())
      ),
      'nothing a rebuild could move, so nothing to warn about'
    );
  }

  /**
   * Pins the behaviour the warning describes: a zone save really does move them.
   *
   * If this ever stops being true the warning becomes a lie, so it is asserted
   * rather than assumed.
   */
  public function testAZoneOnlySaveActuallyRebuildsTheOccurrences(): void {
    $series = $this->futureWeeklySeries('', 'America/New_York');
    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');

    $before = $this->occurrenceStarts($storage->loadUnchanged($series->id()));

    $series->set('field_event_timezone', 'America/Los_Angeles');
    $series->save();

    $after = $this->occurrenceStarts($storage->loadUnchanged($series->id()));

    $this->assertNotSame($before, $after,
      'a timezone change reschedules the event, which is what the warning says');
  }

  /**
   * The stored start values of a series' occurrences, in order.
   */
  private function occurrenceStarts(EventSeries $series): array {
    $starts = [];
    foreach ($series->event_instances->referencedEntities() as $instance) {
      $starts[] = $instance->get('date')->value;
    }
    sort($starts);
    return $starts;
  }

}
