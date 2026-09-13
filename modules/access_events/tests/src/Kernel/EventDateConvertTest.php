<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Plugin\Util\EventDateConvert;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests same-day detection in the event date formatter.
 *
 * EventDateConvert collapses a range to a single date plus two times when the
 * start and end fall on the same day. Getting that comparison wrong drops the
 * end date from multi-day events, of which production has 245.
 *
 * @group access_events
 */
class EventDateConvertTest extends KernelTestBase {

  /**
   * A range inside one calendar day renders one date and two times.
   */
  public function testSameCalendarDayCollapses(): void {
    $convert = new EventDateConvert('2026-05-18 14:00:00', '2026-05-18 15:00:00');

    $this->assertSame(1, $convert->sameDay, 'a range within one day is same-day');
    $this->assertStringNotContainsString('/', $convert->getEnd(),
      'the collapsed end carries a time only, no date');
  }

  /**
   * A range spanning two days in the same month is not same-day.
   */
  public function testDifferentDayInSameMonthIsNotSameDay(): void {
    $convert = new EventDateConvert('2026-05-18 14:00:00', '2026-05-19 15:00:00');

    $this->assertSame(0, $convert->sameDay, 'consecutive days are not same-day');
    $this->assertStringContainsString('05/19/26', $convert->getEnd(),
      'the end keeps its own date');
  }

  /**
   * A range whose ends share a day-of-month but not a month is not same-day.
   *
   * This is the regression: comparing date('d') sees 18 == 18 for 5/18 -> 6/18
   * and collapses a month-long event, printing an end time with no end date.
   */
  public function testSameDayOfMonthInDifferentMonthsIsNotSameDay(): void {
    $convert = new EventDateConvert('2026-05-18 14:00:00', '2026-06-18 15:00:00');

    $this->assertSame(0, $convert->sameDay,
      '5/18 -> 6/18 spans a month and must not collapse');
    $this->assertStringContainsString('06/18/26', $convert->getEnd(),
      'the end keeps its own date');
  }

  /**
   * A named display zone renders the times in it, labelled by name.
   *
   * The listings feed this class strings the date formatter already rendered.
   * For an in-person event those are in the venue's zone, and re-rendering
   * them in the ambient zone showed a 9:00 AM Chicago event as 10:00 AM EDT —
   * the right instant with the wrong clock and a label naming the wrong place.
   */
  public function testNamedZoneRendersInThatZone(): void {
    $convert = new EventDateConvert(
      '2026-03-10T09:00:00-0500',
      '2026-03-10T10:00:00-0500',
      'America/Chicago'
    );

    $this->assertStringContainsString('9:00 AM', $convert->getStartTime(),
      "the venue's own clock, not the viewer's");
    $this->assertStringContainsString('CDT', $convert->getEndTime(),
      'and the zone is named rather than rendered as a numeric offset');
  }

  /**
   * Without a named zone the ambient zone still decides.
   *
   * Online events are viewer-local and correctly labelled by ambient, so an
   * offset in the input must not silently relabel them GMT-0400.
   */
  public function testWithoutANamedZoneAmbientStillDecides(): void {
    $original = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
      $convert = new EventDateConvert('2026-03-10T10:00:00-0400', '2026-03-10T11:00:00-0400');
      $end = $convert->getEndTime();
    }
    finally {
      date_default_timezone_set($original);
    }

    $this->assertStringContainsString('EDT', $end,
      'the viewer zone is named, not shown as an offset');
  }

  /**
   * A bare local string behaves exactly as it always did.
   */
  public function testBareLocalInputIsUnchanged(): void {
    $original = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
      $convert = new EventDateConvert('2026-03-10 09:00:00', '2026-03-10 10:00:00');
      $start = $convert->getStartTime();
    }
    finally {
      date_default_timezone_set($original);
    }

    $this->assertStringContainsString('9:00 AM', $start,
      'callers passing a bare local string see no change');
  }

  /**
   * A range whose ends share a day-of-month but not a year is not same-day.
   */
  public function testSameDayOfMonthInDifferentYearsIsNotSameDay(): void {
    $convert = new EventDateConvert('2025-05-18 14:00:00', '2026-05-18 15:00:00');

    $this->assertSame(0, $convert->sameDay,
      '5/18/25 -> 5/18/26 spans a year and must not collapse');
    $this->assertStringContainsString('05/18/26', $convert->getEnd(),
      'the end keeps its own date');
  }

  /**
   * Constructing the class raises no PHP notice.
   *
   * Every value this class exposes has to be a DECLARED property. Assigning an
   * undeclared one is a deprecation on PHP 8.2+, and Drupal renders that
   * notice into the response before the doctype — which breaks the document,
   * takes the page's JavaScript down with it, and leaves facets and the
   * toolbar unrendered. The class still returns correct strings throughout, so
   * nothing here looks wrong; the damage is entirely in the emitted notice.
   */
  public function testConstructionRaisesNoPhpNotice(): void {
    $raised = [];
    set_error_handler(function (int $no, string $message) use (&$raised): bool {
      $raised[] = $message;
      return TRUE;
    }, E_ALL);

    try {
      $convert = new EventDateConvert('2026-07-15T19:00:00', '2026-07-15T20:00:00', 'America/Chicago');
      $convert->getStart();
      $convert->getStartDayDate();
      $convert->getEnd();
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $raised,
      'a notice here is rendered into the page and breaks the document');
  }

}
