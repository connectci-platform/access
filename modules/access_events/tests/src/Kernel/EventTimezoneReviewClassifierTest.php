<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\User;

/**
 * Tests which series the backfill flags for a human to check.
 *
 * This is the list someone actually works from when deciding which of 962
 * production series need correcting by hand, so both directions matter. A
 * missed conflict is a wrong timezone nobody notices; a false positive is
 * noise, and a list with a third of it wrong is a list people learn to ignore.
 *
 * The rules encode what production actually contains: locations reading
 * "Multiple" because a PSC workshop is telecast to satellite sites, and
 * locations with a timezone typed into them because there was nowhere else to
 * put one before this field existed.
 *
 * @group access_events
 */
class EventTimezoneReviewClassifierTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();

    // The classifier reads field_location, which the shared fixture does not
    // create. Without it hasField() is FALSE and every case returns NULL
    // before any rule is evaluated — the tests would pass for the wrong
    // reason on the negative cases and fail inexplicably on the positive ones.
    if (!\Drupal\field\Entity\FieldStorageConfig::loadByName('eventseries', 'field_location')) {
      \Drupal\field\Entity\FieldStorageConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_location',
        'type' => 'text',
        'settings' => ['max_length' => 255],
      ])->save();
      \Drupal\field\Entity\FieldConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_location',
        'bundle' => 'default',
        'label' => 'Location',
      ])->save();
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    }
  }

  /**
   * Runs the backfill over one series and returns its review reason, if any.
   */
  private function reviewReasonFor(string $location, string $authorZone): ?string {
    $suffix = md5($location . $authorZone);
    $author = User::create([
      'name' => 'author_' . $suffix,
      'mail' => $suffix . '@example.com',
      'status' => 1,
      'timezone' => $authorZone,
    ]);
    $author->save();

    $series = EventSeries::create([
      'title' => 'Series ' . $suffix,
      'type' => 'default',
      'recur_type' => 'custom',
      'uid' => $author->id(),
      'field_location' => $location,
      'custom_date' => [[
        'value' => '2026-07-15T18:00:00',
        'end_value' => '2026-07-15T19:00:00',
      ]],
    ]);
    $series->save();

    // The backfill never overwrites, so a series already carrying a zone is
    // skipped and never classified. Each case therefore needs its own series
    // with an empty zone, which is what creating one per call gives us — but
    // the run also sweeps every OTHER series the fixture left behind, so match
    // on this series' id rather than assuming it is the only row.
    $result = \Drupal::service('access_events.timezone_backfill')->backfill();
    foreach ($result['review'] as $row) {
      if ((int) $row['id'] === (int) $series->id()) {
        return $row['reason'];
      }
    }
    return NULL;
  }

  /**
   * A typed timezone that AGREES with the author's zone is not a conflict.
   *
   * Production has five OSPool events whose location reads
   * "Online (time is in CST)" with a Chicago author. CST is Central, so the
   * backfill derived exactly what the organizer had typed — reporting those
   * inverted the result and was a fifth of the whole list.
   */
  public function testTypedZoneAgreeingWithTheAuthorZoneIsNotFlagged(): void {
    $this->assertNull(
      $this->reviewReasonFor('Online (time is in CST)', 'America/Chicago'),
      'CST and America/Chicago are the same zone, so there is nothing to review'
    );
  }

  /**
   * A typed timezone that DISAGREES is flagged.
   */
  public function testTypedZoneDisagreeingWithTheAuthorZoneIsFlagged(): void {
    $reason = $this->reviewReasonFor('Online (time is in PST)', 'America/New_York');

    $this->assertNotNull($reason, 'a real disagreement reaches the operator');
    $this->assertStringContainsString('PST', (string) $reason,
      'and names what was typed');
  }

  /**
   * A timezone abbreviation inside an ordinary word is not a match.
   *
   * "Commons Visualization Laboratory" contains CEST if the match is
   * case-insensitive and unanchored, which flagged a real PSC venue as a
   * timezone conflict.
   */
  public function testAbbreviationInsideAnOrdinaryWordIsNotFlagged(): void {
    $this->assertNull(
      $this->reviewReasonFor('Commons Visualization Laboratory', 'America/New_York'),
      'CEST inside "Commons" is not a timezone'
    );
  }

  /**
   * A lowercase word that spells an abbreviation is not a match.
   */
  public function testLowercaseWordIsNotTreatedAsAnAbbreviation(): void {
    $this->assertNull(
      $this->reviewReasonFor('The cst building, room 4', 'America/New_York'),
      'matching is case-sensitive, so lowercase prose is not a timezone'
    );
  }

  /**
   * A satellite-broadcast location is flagged whatever the author zone.
   *
   * These are the PSC workshops telecast to host institutions. A single venue
   * zone is the wrong shape for them, and the real time is stated in the body.
   */
  public function testBroadcastLocationIsFlagged(): void {
    $reason = $this->reviewReasonFor('Multiple', 'America/New_York');

    $this->assertNotNull($reason, 'a broadcast to satellite sites reaches the operator');
    $this->assertStringContainsString('Multiple', (string) $reason,
      'and says what the location was');
  }

  /**
   * An ordinary venue is not flagged.
   */
  public function testOrdinaryVenueIsNotFlagged(): void {
    $this->assertNull(
      $this->reviewReasonFor('300 South Craig Street, Room 366, Pittsburgh, PA', 'America/New_York'),
      'a street address needs no human decision'
    );
  }

  /**
   * An empty location is not flagged.
   */
  public function testEmptyLocationIsNotFlagged(): void {
    $this->assertNull(
      $this->reviewReasonFor('', 'America/New_York'),
      'nothing in the location means nothing to disagree with'
    );
  }

}
