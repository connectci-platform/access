<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Validate-time symmetry pins for the recurrence-boundary decode/encode.
 *
 * The reschedule-block guard compares the form-extracted, NOT-yet-saved
 * entity against the stored original entity-vs-entity
 * (EventSeriesRescheduleBlockConstraintValidator::checkViolations() →
 * checkForOriginalRecurConfigChanges($entity, loadUnchanged)), through the
 * boundary GETTERS. The hybrid decode is what keeps that comparison
 * symmetric: an anchored stored value decodes to its literal calendar date
 * while the widget's wall-clock re-derivation of the SAME date decodes
 * through stock instant semantics to the same calendar date — so a
 * registered series' title-only or no-op boundary edit must never phantom a
 * "schedule cannot be rebuilt" refusal, and a no-op save must never
 * recreate instances. These tests pin that symmetry on anchored rows (the
 * post-migration steady state) and on trait-planted wall-clock rows (the
 * unmigrated deploy window), plus the draft-publish behavior of the
 * preSave() unchanged-vs-changed branches across revisions.
 *
 * No FormState anywhere: a widget submit is simulated by setting the loaded
 * entity's field columns to exactly what the widget would deliver, then
 * validate()/save().
 *
 * @covers \Drupal\access_events\Entity\EventSeriesAccess
 * @covers \Drupal\access_events\Plugin\Validation\Constraint\EventSeriesRescheduleBlockConstraintValidator
 * @group access_events
 */
class RecurBoundaryValidateSymmetryTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and the affinity-group node insert hook (reads
    // field_affinity_group). Seed the empty site-level fields those hooks
    // touch, mirroring EventCrudCancelOccurrenceTest.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1, []],
      ['eventinstance', 'domain_access', 'string', -1, []],
      ['eventseries', 'field_other_authors', 'entity_reference', -1, ['target_type' => 'user']],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality, $settings]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
          'settings' => $settings,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }

    if (!FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
      FieldStorageConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => 1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Runs $callable as a user in $timezone with $roles (mirrors a request).
   *
   * The account is switched (as asActingUser does) AND PHP's default
   * timezone follows the user's pref, because both the boundary decode and
   * preSave's recovery resolve the zone via date_default_timezone_get().
   */
  private function asSaverIn(string $timezone, callable $callable, array $roles = []) {
    $saver = $this->createUser([], NULL, FALSE, [
      'timezone' => $timezone,
      'roles' => $roles,
    ]);
    return $this->asActingUser($saver, function () use ($timezone, $callable) {
      $previous = date_default_timezone_get();
      date_default_timezone_set($timezone);
      try {
        return $callable();
      }
      finally {
        date_default_timezone_set($previous);
      }
    });
  }

  /**
   * Reads the stored bytes of a boundary pair straight from the data table.
   *
   * @return array{0: string|null, 1: string|null}
   *   [{field}__value, {field}__end_value] of the DEFAULT revision.
   */
  private function storedPair(int $seriesId, string $field): array {
    $row = \Drupal::database()->select('eventseries_field_data', 'd')
      ->fields('d', [$field . '__value', $field . '__end_value'])
      ->condition('id', $seriesId)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return [$row[$field . '__value'], $row[$field . '__end_value']];
  }

  /**
   * Same as storedPair(), but for one specific revision row.
   *
   * @return array{0: string|null, 1: string|null}
   *   [{field}__value, {field}__end_value] of revision $vid.
   */
  private function storedRevisionPair(int $seriesId, int $vid, string $field): array {
    $row = \Drupal::database()->select('eventseries_field_revision', 'r')
      ->fields('r', [$field . '__value', $field . '__end_value'])
      ->condition('id', $seriesId)
      ->condition('vid', $vid)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return [$row[$field . '__value'], $row[$field . '__end_value']];
  }

  /**
   * A PUBLISHED weekly-rule series whose boundary pair is deterministically
   * anchored `2999-01-04T12:00:00` / `2999-01-10T12:00:00`.
   *
   * The anchor is planted through a real entity save of bare `YYYY-MM-DD`
   * values — bare dates anchor LITERALLY in every saver zone, so the fixture
   * bytes do not depend on the phpunit environment's ambient timezone.
   */
  private function makePublishedAnchoredSeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);

    $item = $series->get('weekly_recurring_date')->first();
    $item->set('value', '2999-01-04');
    $item->set('end_value', '2999-01-10');
    $series->save();
    $series->set('moderation_state', 'published')->save();

    $this->assertSame(
      ['2999-01-04T12:00:00', '2999-01-10T12:00:00'],
      $this->storedPair((int) $series->id(), 'weekly_recurring_date'),
      'fixture: the stored boundary pair is anchored on the intended dates',
    );
    return $series;
  }

  /**
   * Attaches a published registrable instance + one registrant to $series.
   *
   * Same pattern as EventSeriesRescheduleBlockTest: the registrable instance
   * is built by the base helper and repointed at the target series, so
   * countNotPastForSeries() sees a future registrant on it.
   */
  private function attachRegistrant(EventSeries $series): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);
  }

  /**
   * Sorted eventinstance ids belonging to $series.
   *
   * @return int[]
   *   The instance ids.
   */
  private function instanceIds(EventSeries $series): array {
    $ids = array_map(fn ($i) => (int) $i->id(), $this->loadInstances($series));
    sort($ids);
    return $ids;
  }

  /**
   * Asserts zero violations, naming any that were raised.
   *
   * One known artifact is excluded: contrib defines the computed
   * event_instances reference with no explicit cardinality (so it defaults
   * to 1), and core's Count constraint therefore flags ANY series holding
   * two or more instances at validate() — including these fixtures, whose
   * weekly rule spawns several. That fires regardless of what the boundary
   * columns hold, so it is orthogonal to the symmetry under test; every
   * other violation (reschedule-block, moderation, field-level) still
   * fails the assertion.
   */
  private function assertViolationFree($violations, string $message): void {
    $raised = [];
    foreach ($violations as $violation) {
      if ($violation->getPropertyPath() === 'event_instances') {
        continue;
      }
      $raised[] = $violation->getPropertyPath() . ': ' . (string) $violation->getMessage();
    }
    $this->assertSame([], $raised, $message);
  }

  /**
   * An evening-NY wall-clock resubmit of an anchored series' own dates is
   * not a recurrence change: no reschedule refusal, no instance churn.
   *
   * The stored pair is anchored (`2999-01-04T12:00:00`). The widget, filled
   * with those same calendar dates by a New York editor at 21:34:22 local,
   * delivers each chosen date at the submit-moment wall clock converted to
   * UTC — January in New York is EST (UTC−5), so:
   *   2999-01-04 21:34:22 EST + 5h = 2999-01-05T02:34:22 (UTC)
   *   2999-01-10 21:34:22 EST + 5h = 2999-01-11T02:34:22 (UTC)
   * Next-day-shaped bytes, SAME intended dates. The validator's comparison
   * must decode both sides to Jan 4 / Jan 10 New York midnight (anchored
   * side literally, widget side via stock instant semantics) — a
   * literal-only decode would read the widget values as Jan 5 / Jan 11,
   * phantom a recur change, and refuse this registered series' save.
   */
  public function testEveningNyResubmitOfAnchoredDatesIsNoOp(): void {
    $series = $this->makePublishedAnchoredSeries();
    $this->attachRegistrant($series);
    $id = (int) $series->id();
    $idsBefore = $this->instanceIds($series);
    $this->assertNotEmpty($idsBefore);

    $violations = $this->asSaverIn('America/New_York', function () use ($id) {
      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$id]);
      $loaded = $storage->load($id);
      $item = $loaded->get('weekly_recurring_date')->first();
      $item->set('value', '2999-01-05T02:34:22');
      $item->set('end_value', '2999-01-11T02:34:22');
      $violations = $loaded->validate();
      // A phantom recur-delta would also make this save throw (the presave
      // mirror refuses recur changes on a registered series), so reaching
      // the assertions below is itself part of the pin.
      $loaded->save();
      return $violations;
    }, ['news_pm']);

    $this->assertViolationFree($violations, 'no reschedule-block (or any other) violation for a same-date resubmit');
    // preSave recovered the changed bytes through the saver's NY zone back
    // to the identical anchors — a byte-level no-op in storage.
    $this->assertSame(
      ['2999-01-04T12:00:00', '2999-01-10T12:00:00'],
      $this->storedPair($id, 'weekly_recurring_date'),
    );
    $this->assertSame($idsBefore, $this->instanceIds($series), 'instances were neither recreated nor removed');
  }

  /**
   * Deploy-window pin: the same no-op resubmit over UNMIGRATED wall-clock
   * rows raises no violation and recreates nothing.
   *
   * Between code deploy and the legacy-row migration, stored rows still hold
   * browser wall-clock shapes. Planted via the fixture trait (a plain save
   * would re-anchor them): `2999-01-05T02:00:00` / `2999-01-11T02:00:00` —
   * what a NY editor's 21:00 EST save of Jan 4 / Jan 10 stored (21:00 + 5h
   * crosses midnight UTC). A NY editor re-submits the same dates later the
   * same evening at 22:15:45 EST:
   *   2999-01-04 22:15:45 EST + 5h = 2999-01-05T03:15:45 (UTC)
   *   2999-01-10 22:15:45 EST + 5h = 2999-01-11T03:15:45 (UTC)
   * Different bytes on BOTH sides of the comparison, but both decode through
   * the stock branch to Jan 4 / Jan 10 NY midnight — so no refusal, and no
   * instance recreation even though the save rewrites the stored bytes to
   * anchors (the getter-level comparison, not the bytes, is what gates the
   * rebuild).
   */
  public function testEveningNyResubmitOverWallClockRowsIsNoOp(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);
    $series->set('moderation_state', 'published')->save();
    $this->setRawBoundary($series, 'weekly_recurring_date', '2999-01-05T02:00:00', '2999-01-11T02:00:00');
    $this->attachRegistrant($series);
    $id = (int) $series->id();
    $idsBefore = $this->instanceIds($series);
    $this->assertNotEmpty($idsBefore);

    $violations = $this->asSaverIn('America/New_York', function () use ($id) {
      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$id]);
      $loaded = $storage->load($id);
      $item = $loaded->get('weekly_recurring_date')->first();
      $item->set('value', '2999-01-05T03:15:45');
      $item->set('end_value', '2999-01-11T03:15:45');
      $violations = $loaded->validate();
      $loaded->save();
      return $violations;
    }, ['news_pm']);

    $this->assertViolationFree($violations, 'no reschedule-block violation over unmigrated wall-clock rows');
    // The save DID rewrite the bytes (changed values recover to anchors on
    // the editor's intended dates) — proving "no recreation" above is a
    // getter-symmetry property, not accidental byte equality.
    $this->assertSame(
      ['2999-01-04T12:00:00', '2999-01-10T12:00:00'],
      $this->storedPair($id, 'weekly_recurring_date'),
    );
    $this->assertSame($idsBefore, $this->instanceIds($series), 'instances were neither recreated nor removed');
  }

  /**
   * Draft-publish pin: publishing a pending draft whose boundary was
   * legitimately CHANGED re-derives it through the publishing moderator's
   * zone — correct for a New York moderator.
   *
   * A NY author drafts a move of the window from Jan 4–10 to Feb 5–11,
   * submitting at 21:34:22 local (February in New York is EST, UTC−5):
   *   2999-02-05 21:34:22 EST + 5h = 2999-02-06T02:34:22 (UTC)
   *   2999-02-11 21:34:22 EST + 5h = 2999-02-12T02:34:22 (UTC)
   * The draft save recovers those through the author's NY zone and stores
   * `2999-02-05T12:00:00` / `2999-02-11T12:00:00` in the PENDING revision;
   * the default revision keeps the Jan anchors.
   *
   * On publish, preSave measures "unchanged" against the DEFAULT revision,
   * so the draft's Feb anchors count as CHANGED and take the recovery
   * branch through the PUBLISHING MODERATOR's zone: `2999-02-05T12:00:00`
   * parsed as a UTC instant is 07:00 Feb 5 in New York — date part Feb 5 —
   * so the anchor re-derives to itself and lands correctly.
   *
   * ACCEPTED RESIDUAL (documented, deliberately not "fixed" here): a
   * UTC>=+12 moderator publishing the same date-changed draft re-derives
   * through THEIR zone — `2999-02-05T12:00:00` is already Feb 6, 01:00 in
   * Auckland (NZDT, UTC+13), so the published boundary would shift to
   * `2999-02-06T12:00:00`. UNCHANGED drafts are safe in every timezone (the
   * byte-identical no-op branch), and this residual shares its population
   * with the other accepted far-east residuals. Trusting the T12 shape
   * instead would be worse: the anchor signature is writer-reachable, so a
   * signature-trusting preSave would freeze genuinely wrong far-east
   * wall-clock dates forever.
   */
  public function testPublishingDateChangedDraftAnchorsCorrectlyUnderNyModerator(): void {
    $series = $this->makePublishedAnchoredSeries();
    $id = (int) $series->id();
    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $defaultVid = (int) $series->getRevisionId();

    // The NY author saves the date change as a pending draft revision.
    $this->asSaverIn('America/New_York', function () use ($storage, $id): void {
      $storage->resetCache([$id]);
      $draft = $storage->createRevision($storage->load($id));
      $draft->set('moderation_state', 'draft');
      $item = $draft->get('weekly_recurring_date')->first();
      $item->set('value', '2999-02-06T02:34:22');
      $item->set('end_value', '2999-02-12T02:34:22');
      $draft->save();
    });

    // Genuinely pending: the default revision still carries the Jan anchors
    // while the newer draft revision carries the recovered Feb anchors.
    $latestVid = (int) $storage->getLatestRevisionId($id);
    $this->assertGreaterThan($defaultVid, $latestVid, 'the draft is a new revision');
    $this->assertSame(
      ['2999-01-04T12:00:00', '2999-01-10T12:00:00'],
      $this->storedPair($id, 'weekly_recurring_date'),
      'the default revision is untouched by the pending draft',
    );
    $this->assertSame(
      ['2999-02-05T12:00:00', '2999-02-11T12:00:00'],
      $this->storedRevisionPair($id, $latestVid, 'weekly_recurring_date'),
      'the pending revision holds the changed dates, anchored via the NY author',
    );

    // The NY moderator publishes the pending draft as the default revision.
    $this->asSaverIn('America/New_York', function () use ($storage, $id, $latestVid): void {
      $pending = $storage->loadRevision($latestVid);
      $pending->set('moderation_state', 'published');
      $pending->save();
    }, ['news_pm']);

    $this->assertSame(
      ['2999-02-05T12:00:00', '2999-02-11T12:00:00'],
      $this->storedPair($id, 'weekly_recurring_date'),
      'the published boundary is anchored on the draft-changed dates (the changed-value recovery is exact for a NY moderator)',
    );
  }

}
