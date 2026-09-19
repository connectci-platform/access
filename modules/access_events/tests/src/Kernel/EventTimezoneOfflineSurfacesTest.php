<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

/**
 * Tests the time formatting used outside a web request.
 *
 * On a web request Drupal sets the ambient timezone to the viewer's account
 * zone, which is why a round trip through strtotime()/date() comes out right
 * on rendered pages. Cron, queue workers and mail runs have no viewer, so
 * ambient falls back to the site default and that cancellation stops holding.
 *
 * These surfaces are broken today for reasons of their own as well:
 *
 * - the digest takes its zone abbreviation from a fresh "now" rather than from
 *   the event's own date, so a digest sent in July stamps EDT on a January
 *   event, which is EST;
 * - the JSON-LD builder parses a stored naive-UTC value as if it were local
 *   and then stamps a local offset, so the emitted instant is wrong by the
 *   full offset.
 *
 * Both are tested through pure value-suppliers. The digest itself runs an
 * entityQuery over node types this kernel environment does not create, and the
 * JSON-LD builder needs a render pipeline; extracting the formatting is what
 * makes the behaviour assertable at all.
 *
 * @group access_events
 */
class EventTimezoneOfflineSurfacesTest extends EventKernelTestBase {

  /**
   * The abbreviation comes from the event's date, not from today.
   *
   * A digest assembled in July must still label a January event EST. Taking
   * the abbreviation from a fresh "now" gets this wrong for roughly half the
   * year, in whichever direction the send date sits relative to the event.
   */
  public function testAbbreviationComesFromTheEventDateNotToday(): void {
    $january = _access_events_format_event_time(
      '2026-01-15T19:00:00',
      'America/New_York',
      'n/j/Y g:i a T'
    );
    $july = _access_events_format_event_time(
      '2026-07-15T19:00:00',
      'America/New_York',
      'n/j/Y g:i a T'
    );

    $this->assertStringContainsString('EST', $january,
      'a January event is Eastern Standard Time whenever it is sent');
    $this->assertStringContainsString('EDT', $july,
      'and a July event is Eastern Daylight Time');
  }

  /**
   * The formatted wall clock is correct for the zone asked for.
   */
  public function testWallClockIsCorrectForTheRequestedZone(): void {
    // 19:00 UTC in July is 3:00pm Eastern, 2:00pm Central, noon Pacific.
    $this->assertStringContainsString('3:00 pm',
      _access_events_format_event_time('2026-07-15T19:00:00', 'America/New_York', 'g:i a'));
    $this->assertStringContainsString('2:00 pm',
      _access_events_format_event_time('2026-07-15T19:00:00', 'America/Chicago', 'g:i a'));
    $this->assertStringContainsString('12:00 pm',
      _access_events_format_event_time('2026-07-15T19:00:00', 'America/Los_Angeles', 'g:i a'));
  }

  /**
   * Formatting does not depend on the ambient zone.
   *
   * This is the property the offline surfaces need: a cron run has no viewer,
   * so anything that reads the ambient zone produces a different answer
   * depending on how it was invoked.
   */
  public function testFormattingIsIndependentOfTheAmbientZone(): void {
    $results = [];
    $original = date_default_timezone_get();
    foreach (['America/New_York', 'Australia/Perth', 'UTC'] as $ambient) {
      date_default_timezone_set($ambient);
      try {
        $results[$ambient] = _access_events_format_event_time(
          '2026-07-15T19:00:00',
          'America/Chicago',
          'n/j/Y g:i a T'
        );
      }
      finally {
        date_default_timezone_set($original);
      }
    }

    $this->assertCount(1, array_unique($results),
      'the same event and zone format identically however the run was invoked');
    $this->assertStringContainsString('CDT', reset($results),
      'and in the zone asked for, not the ambient one');
  }

  /**
   * The structured-data offset is the event's, computed for its own date.
   *
   * The stored value is naive UTC. Parsing it as local and stamping a local
   * offset — which is what the JSON-LD builder does today — is wrong by the
   * full offset, and Google reads that offset literally.
   */
  public function testStructuredDataOffsetIsCorrectForTheEventDate(): void {
    $july = _access_events_iso8601_event_time('2026-07-15T19:00:00', 'America/New_York');
    $january = _access_events_iso8601_event_time('2026-01-15T19:00:00', 'America/New_York');

    $this->assertSame('2026-07-15T15:00:00-04:00', $july,
      'July is UTC-4 in New York, and 19:00 UTC is 3pm local');
    $this->assertSame('2026-01-15T14:00:00-05:00', $january,
      'January is UTC-5, and the same UTC time is 2pm local');
  }

  /**
   * A structured-data offset is emitted for the zone asked for.
   */
  public function testStructuredDataRespectsTheEventsOwnZone(): void {
    $this->assertSame('2026-07-15T14:00:00-05:00',
      _access_events_iso8601_event_time('2026-07-15T19:00:00', 'America/Chicago'),
      'a Chicago event emits the Chicago offset');
    $this->assertSame('2026-07-15T21:00:00+02:00',
      _access_events_iso8601_event_time('2026-07-15T19:00:00', 'Europe/Rome'),
      'and a Rome event emits the Rome offset');
  }

  /**
   * An unusable zone falls back rather than throwing.
   *
   * These run in cron and mail paths where an exception is not a visible
   * error, it is a digest that silently never sends.
   */
  public function testUnusableZoneFallsBackRatherThanThrowing(): void {
    foreach (['', '   ', 'Not/AZone'] as $bad) {
      $formatted = _access_events_format_event_time('2026-07-15T19:00:00', $bad, 'g:i a T');
      $this->assertNotEmpty($formatted,
        sprintf('zone %s still formats', var_export($bad, TRUE)));

      $iso = _access_events_iso8601_event_time('2026-07-15T19:00:00', $bad);
      $this->assertNotEmpty($iso,
        sprintf('zone %s still produces an ISO instant', var_export($bad, TRUE)));
    }
  }

}
