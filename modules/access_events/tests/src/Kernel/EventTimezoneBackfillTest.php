<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\User;

/**
 * Tests the event timezone backfill.
 *
 * The backfill writes a zone to every existing series, derived from the
 * author's account timezone. Measured against production that contract holds
 * for 855 of 872 series whose author has a zone set, so it is a sound default
 * — but it is a default, not a proof, and the rows it cannot settle have to
 * reach a human rather than being guessed.
 *
 * It is silent when wrong in two ways, both pinned below: a zone written over
 * a human correction, and a revision left without the field (which reads as
 * empty, so reverting to it would resolve against the ambient zone and
 * silently reschedule the event).
 *
 * @group access_events
 */
class EventTimezoneBackfillTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
  }

  /**
   * Creates a series owned by an author with the given account timezone.
   */
  private function seriesOwnedByAuthorInZone(?string $zone, string $suffix): EventSeries {
    $author = User::create([
      'name' => 'author_' . $suffix,
      'mail' => $suffix . '@example.com',
      'status' => 1,
      'timezone' => $zone ?? '',
    ]);
    $author->save();

    $series = EventSeries::create([
      'title' => 'Series ' . $suffix,
      'type' => 'default',
      'recur_type' => 'custom',
      'uid' => $author->id(),
      'custom_date' => [[
        'value' => '2026-07-15T18:00:00',
        'end_value' => '2026-07-15T19:00:00',
      ]],
    ]);
    $series->save();
    return $series;
  }

  /**
   * The backfill takes the author's account timezone.
   */
  public function testBackfillTakesTheAuthorsAccountTimezone(): void {
    $series = $this->seriesOwnedByAuthorInZone('America/Chicago', 'chi');

    $written = \Drupal::service('access_events.timezone_backfill')->backfill();

    $series = $this->reloadSeries($series);
    $this->assertSame('America/Chicago',
      $series->get('field_event_timezone')->value,
      "the series takes its author's zone");
    $this->assertGreaterThan(0, $written['author_zone'],
      'and the run reports it as author-derived');
  }

  /**
   * An author with no timezone falls back to the site default.
   */
  public function testAuthorWithoutZoneFallsBackToSiteDefault(): void {
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    $series = $this->seriesOwnedByAuthorInZone(NULL, 'nozone');

    \Drupal::service('access_events.timezone_backfill')->backfill();

    $this->assertSame('America/New_York',
      $this->reloadSeries($series)->get('field_event_timezone')->value,
      'the site default stands in');
  }

  /**
   * A series that already has a zone is never overwritten.
   *
   * The backfill must be safe to re-run, and re-running must not revert a
   * human correction — which is the whole reason it records provenance.
   */
  public function testExistingValueIsNeverOverwritten(): void {
    $series = $this->seriesOwnedByAuthorInZone('America/Chicago', 'corrected');
    $series->set('field_event_timezone', 'Europe/Rome');
    $series->save();

    \Drupal::service('access_events.timezone_backfill')->backfill();

    $this->assertSame('Europe/Rome',
      $this->reloadSeries($series)->get('field_event_timezone')->value,
      'a human correction survives a re-run');
  }

  /**
   * Re-running the backfill changes nothing.
   */
  public function testBackfillIsIdempotent(): void {
    $this->seriesOwnedByAuthorInZone('America/Denver', 'idem');

    $first = \Drupal::service('access_events.timezone_backfill')->backfill();
    $second = \Drupal::service('access_events.timezone_backfill')->backfill();

    $this->assertGreaterThan(0, $first['author_zone'], 'the first run writes');
    $this->assertSame(0, $second['author_zone'] + $second['site_default'],
      'the second run writes nothing');
  }

  /**
   * The backfill writes every revision, not just the current one.
   *
   * A field row missing from a revision reads as EMPTY when that revision is
   * loaded. Since generation resolves the stored zone, reverting a series to
   * such a revision would resolve against the ambient zone instead and
   * silently reschedule the event. Production has 3,859 series revisions.
   */
  public function testBackfillWritesEveryRevision(): void {
    $series = $this->seriesOwnedByAuthorInZone('America/Chicago', 'revisioned');

    // A second and third revision, as an edited series accumulates.
    foreach (['Second title', 'Third title'] as $title) {
      $series->setNewRevision(TRUE);
      $series->set('title', $title);
      $series->save();
    }

    $revisionIds = \Drupal::database()->select('eventseries_revision', 'r')
      ->fields('r', ['vid'])
      ->condition('r.id', $series->id())
      ->execute()
      ->fetchCol();
    $this->assertGreaterThan(1, count($revisionIds), 'the series has several revisions');

    \Drupal::service('access_events.timezone_backfill')->backfill();

    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    foreach ($revisionIds as $vid) {
      $revision = $storage->loadRevision($vid);
      $this->assertNotNull($revision, "revision $vid loads");
      $this->assertSame(
        'America/Chicago',
        $revision->get('field_event_timezone')->value,
        "revision $vid carries the zone, so reverting to it cannot reschedule the event"
      );
    }
  }

  /**
   * A pre-existing instance resolves through the entities[] fallback alone.
   *
   * This pins the mechanism the feature now depends on instead of a rebuild.
   *
   * field_inheritance 3.x resolves an inherited value from a base field.
   * FieldInheritancePluginBase::getSourceEntity() takes the source id from
   * fields[<id>]['entity'] when present, and otherwise falls back to
   * entities[<source entity type>:<bundle>]['entity'] — a per-source-bundle
   * default covering every inheritance from that source, including fields
   * whose config arrived after the 3.x migration.
   *
   * That matters because field_inheritance_update_10300() migrates the old
   * keyvalue rows into fields[] and writes NO entities[] key, and it migrates
   * only what those rows already held — so on a site where this branch has not
   * run, nothing carries our two fields. recurring_events_update_103000()
   * supplies the entities[] key afterwards, and that is what makes them
   * resolve. Verified against the full production dataset: a series with 54
   * instances resolved on all 54 with zero per-field entries present.
   *
   * If a future contrib change re-keys entities[], drops the fallback, or
   * reorders the two update hooks, this test is what turns red. Without it the
   * failure is silent: the value reads empty, and because empty is falsy the
   * display renders its safe branch and nothing looks broken.
   */
  public function testPreExistingInstanceResolvesThroughTheEntitiesFallback(): void {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $series->set('field_event_timezone', 'America/Denver')
      ->set('field_event_in_person', 1)
      ->save();

    // Model exactly what the 3.x migration leaves behind: enabled, an
    // entities[] pointer at the source series, and NO per-field entry for
    // either of our fields.
    $instance = $this->reloadInstance($instance);
    $instance->set('field_inheritance', [
      'enabled' => TRUE,
      'fields' => [],
      'entities' => [
        'eventseries:default' => ['entity' => $series->id()],
      ],
    ])->save();

    $reloaded = $this->reloadInstance($instance);
    $map = $reloaded->get('field_inheritance')->first()->getValue();
    $this->assertSame([], $map['fields'],
      'the instance carries no per-field entry, as after the migration');

    $this->assertSame('America/Denver', $reloaded->get('event_timezone')->value,
      'and still resolves the timezone through the entities[] fallback');
    $this->assertEquals(1, $reloaded->get('event_in_person')->value,
      'and the modality too — the fallback covers every field from that source');
  }

  /**
   * Reloads a series from storage.
   */
  private function reloadSeries(EventSeries $series): EventSeries {
    return \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
  }

}
