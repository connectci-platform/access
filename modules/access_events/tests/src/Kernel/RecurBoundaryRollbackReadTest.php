<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Entity\EventSeriesAccess;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Pins the rollback-safety claim: STOCK code reads anchors correctly.
 *
 * The deploy story promises that a Drupal rollback needs no reverse
 * migration because contrib's stock getters — the code that would read the
 * rows while rolled back — already decode a `{date}T12:00:00` anchor to the
 * intended calendar date for any reader between UTC-11 and UTC+11. This file
 * pins that math by RE-DERIVING contrib's decode chain (storage-format parse
 * in UTC, instant-convert into the reader's zone, floor to midnight) and
 * applying it to anchored values directly. The bundle-class getters are
 * deliberately NOT used anywhere here: they no longer instant-convert
 * anchors, and the whole point is what OLD code — which has no bundle class —
 * would read.
 *
 * @group access_events
 */
class RecurBoundaryRollbackReadTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Only `datetime` (for the storage-format constants the stock chain parses
   * with) — no entities, no DB rows: the claim under test is pure date math
   * over the stored string shape.
   */
  protected static $modules = ['datetime'];

  /**
   * Reader zones inside the safe band, spanning its extremes.
   *
   * New York / Los Angeles / London are the populations that hit the original
   * bug; Etc/GMT+11 and Etc/GMT-11 are UTC-11 and UTC+11 (POSIX sign
   * inversion — deliberate, do not "fix"), the outermost offsets the rollback
   * claim covers. Noon UTC sits 01:00..23:00 local across all of them, so the
   * calendar date can never move.
   */
  private const SAFE_ZONES = [
    'America/New_York',
    'America/Los_Angeles',
    'Europe/London',
    'Etc/GMT+11',
    'Etc/GMT-11',
  ];

  /**
   * Anchored calendar dates chosen to be hostile to instant conversion.
   *
   * Year boundaries (an off-by-one here crosses a YEAR for the yearly rule)
   * and the four DST transition days of the reader zones above (US spring
   * forward / fall back, Europe's spring forward / fall back), where a naive
   * offset assumption is most likely to slip.
   */
  private const ANCHORED_DATES = [
    '2026-01-01',
    '2026-03-08',
    '2026-03-29',
    '2026-04-02',
    '2026-10-25',
    '2026-11-01',
    '2026-12-31',
  ];

  /**
   * Re-derives contrib's stock boundary decode for one reader zone.
   *
   * Mirrors what a rolled-back site computes: core's DateTimeComputed parses
   * the raw column via the datetime storage format in the storage timezone
   * (UTC), then the stock EventSeries getter does
   * `->setTimezone($user_timezone)->setTime(0, 0, 0)`.
   */
  private function stockDecode(string $raw, string $readerZone): DrupalDateTime {
    return DrupalDateTime::createFromFormat(
      DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
      $raw,
      DateTimeItemInterface::STORAGE_TIMEZONE,
    )->setTimezone(new \DateTimeZone($readerZone))->setTime(0, 0, 0);
  }

  /**
   * Every anchored fixture reads as its own date in every safe zone.
   */
  public function testStockDecodeReadsAnchorsAsTheirDateAcrossTheSafeBand(): void {
    foreach (self::ANCHORED_DATES as $date) {
      $raw = $date . EventSeriesAccess::ANCHOR_SUFFIX;
      foreach (self::SAFE_ZONES as $zone) {
        $this->assertSame(
          $date,
          $this->stockDecode($raw, $zone)->format('Y-m-d'),
          sprintf('stock decode of %s for a %s reader', $raw, $zone),
        );
      }
    }
  }

  /**
   * UTC+12 is the documented edge where the rollback claim STOPS.
   *
   * Noon UTC is already tomorrow at UTC+12, so a far-east reader on rolled-
   * back code sees the next day. Pinned deliberately: if this ever starts
   * passing as same-day, the anchor constant changed and the entire safe-band
   * analysis (and the deploy story's rollback bullet) must be redone.
   */
  public function testUtcPlusTwelveReaderIsOutsideTheClaim(): void {
    $raw = '2026-04-02' . EventSeriesAccess::ANCHOR_SUFFIX;
    // Etc/GMT-12 is UTC+12 (POSIX sign inversion — deliberate).
    $this->assertSame('2026-04-03', $this->stockDecode($raw, 'Etc/GMT-12')->format('Y-m-d'));
  }

}
