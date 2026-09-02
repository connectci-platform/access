<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests creation-time birth states for machinery-created event instances.
 *
 * access_events_recurring_events_event_instance_alter() sets a
 * machinery-created instance's moderation_state to match its series' own
 * published/not-published status at the moment of creation: a live series
 * bears live occurrences; a dark series' occurrences are born archived so a
 * later series restore or first publish (EventStateReactions::sweepRestore())
 * brings them along. Draft is reserved for occurrences a person authors
 * directly (never routed through EventCreationService::createEventInstance(),
 * so the alter never fires for them) — those keep the editorial_eventinstance
 * workflow's own draft default and are never swept by a series-level
 * transition.
 *
 * @covers \Drupal\access_events\Plugin\EventInstanceCreator\PastPreservingEventInstanceCreator
 * @group access_events
 */
class InstanceBirthStateTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // access_affinitygroup_entity_presave() (fired by createAffinityGroupNode(),
    // used indirectly via makePublishedCustomSeriesWithDate() below) reads
    // field_affinity_group on the node — not seeded by the base setUp(),
    // only by EventDeleteGuardHooksTest's own fixture. Mirrors
    // SeriesReactionsTest::setUp().
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
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    }
  }

  /**
   * A machinery-created instance under a PUBLISHED series is born published.
   *
   * Exercised via the insert path (a brand-new custom series with a seeded
   * custom_date, published before save so the alter sees isPublished() ===
   * TRUE at creation time). Also asserts the instance carries exactly ONE
   * content_moderation_state entity — proving the alter's data-array write
   * seeded the field at create() time rather than a later resave creating a
   * second moderation-state revision.
   */
  public function testMachineryBirthUnderPublishedSeriesIsPublished(): void {
    $series = EventSeries::create([
      'title' => 'Published Birth Event',
      'body' => 'Born under a live series.',
      'recur_type' => 'custom',
      'type' => 'default',
      'moderation_state' => 'published',
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    $series->save();
    $seriesId = (int) $series->id();

    $instances = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $instances);
    $instance = reset($instances);

    $this->assertSame('published', $instance->get('moderation_state')->value);

    $count = (int) \Drupal::entityTypeManager()->getStorage('content_moderation_state')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'eventinstance')
      ->condition('content_entity_id', $instance->id())
      ->count()
      ->execute();
    $this->assertSame(1, $count, 'Exactly one content_moderation_state entity was created for the birthed instance.');
  }

  /**
   * A machinery-created instance under an ARCHIVED (not-published) series is
   * born archived.
   *
   * Uses the non-published rebuild trigger (EventStateReactions::
   * maybeRebuildInstances()) to fire instance creation on an archived series:
   * a date change on an archived custom series regenerates its instance set,
   * and every regenerated instance must be born archived, not draft — dark
   * series bear dark occurrences.
   */
  public function testMachineryBirthUnderArchivedSeriesIsArchived(): void {
    $series = EventSeries::create([
      'title' => 'Archived Birth Event',
      'body' => 'Born under a dark series.',
      'recur_type' => 'custom',
      'type' => 'default',
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    $series->save();
    $seriesId = (int) $series->id();
    EventSeries::load($seriesId)->set('moderation_state', 'archived')->save();

    $series = EventSeries::load($seriesId);
    $series->set('custom_date', [
      ['value' => '2999-03-01T10:00:00', 'end_value' => '2999-03-01T12:00:00'],
    ]);
    $series->save();

    $instances = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $instances);
    $instance = reset($instances);
    $this->assertSame('2999-03-01T10:00:00', $instance->get('date')->value);
    $this->assertSame('archived', $instance->get('moderation_state')->value);
  }

  /**
   * A draft-authored series (never published) births its instances archived;
   * publishing the series for the first time carries them to published via
   * the restore sweep (EventStateReactions::sweepRestore()) — the full walk from
   * creation to first publish, with no manual seeding of the instance state.
   */
  public function testMachineryBirthUnderDraftSeriesIsArchivedAndPublishesWithSeries(): void {
    $series = EventSeries::create([
      'title' => 'Draft Authored Birth Event',
      'body' => 'Never published at creation time.',
      'recur_type' => 'custom',
      'type' => 'default',
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    $series->save();
    $seriesId = (int) $series->id();

    $instances = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $instances);
    $instance = reset($instances);
    $this->assertSame('archived', $instance->get('moderation_state')->value,
      'A machinery-created instance under a series that has never been published is born archived, not draft.');

    EventSeries::load($seriesId)->set('moderation_state', 'published')->save();

    $this->assertSame('published', $this->reloadInstance($instance)->get('moderation_state')->value,
      'The first publish of the series carries its born-archived instance to published via the restore sweep.');
  }

  /**
   * An instance a human authors directly (via storage create()+save(), never
   * through EventCreationService::createEventInstance()) stays at the
   * editorial_eventinstance workflow's own draft default — the alter never
   * fires for it, since it is never machinery-created. A later series publish
   * must NOT sweep it: it is not in the archived, not-verifiably-past
   * population sweepRestore() walks.
   */
  public function testHumanDirectCreateKeepsDraft(): void {
    $series = EventSeries::create([
      'title' => 'Human Authored Instance Event',
      'body' => 'The series itself has no seeded dates, so no machinery instance is spawned.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $series->save();
    $seriesId = (int) $series->id();

    // Direct storage create()+save() — the "Add Instance" route pattern
    // access_events_entity_presave() documents, never routed through
    // EventCreationService::createEventInstance(), so the birth-state alter
    // never runs for it.
    $instance = EventInstance::create([
      'eventseries_id' => $seriesId,
      'type' => 'default',
      'date' => ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);
    $instance->save();

    $this->assertSame('draft', $instance->get('moderation_state')->value,
      'A human-authored instance keeps the workflow default (draft) — the birth alter never fires for a directly-created instance.');

    EventSeries::load($seriesId)->set('moderation_state', 'published')->save();

    $this->assertSame('draft', $this->reloadInstance($instance)->get('moderation_state')->value,
      'A human-authored draft instance is never swept by a series publish — sweepRestore() only walks archived instances.');
  }

  /**
   * A cloned series is born draft (the clone form-alter forces it,
   * mirroring what eventseries_default_clone_form does via
   * access_events_form_alter()); publishing the clone for the first time
   * publishes its own machinery-created instances, which were themselves
   * born archived (the clone was still draft at their creation time) — the
   * same restore-sweep walk as the draft-series test, but reached via
   * createDuplicate() + a forced draft default rather than a from-scratch
   * series.
   */
  public function testClonedSeriesBornDraftPublishesThroughRestore(): void {
    $original = EventSeries::create([
      'title' => 'Source Event',
      'body' => 'The series being cloned.',
      'recur_type' => 'custom',
      'type' => 'default',
      'moderation_state' => 'published',
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    $original->save();

    // Mirrors EventSeriesCloneForm::buildForm() (createDuplicate()) followed
    // by access_events_form_alter()'s eventseries_default_clone_form branch,
    // which forces the widget default to draft — modeled here as setting
    // moderation_state to draft on the duplicate before the first save,
    // since this is a storage-level test with no form/widget in play.
    $clone = $original->createDuplicate();
    $clone->set('moderation_state', 'draft');
    $clone->save();
    $cloneId = (int) $clone->id();

    $cloneInstances = $this->loadInstances(EventSeries::load($cloneId));
    $this->assertCount(1, $cloneInstances);
    $cloneInstance = reset($cloneInstances);
    $this->assertSame('archived', $cloneInstance->get('moderation_state')->value,
      'The clone was still draft at instance-creation time, so its machinery-created instance is born archived.');

    EventSeries::load($cloneId)->set('moderation_state', 'published')->save();

    $this->assertSame('published', $this->reloadInstance($cloneInstance)->get('moderation_state')->value,
      'Publishing the clone for the first time carries its born-archived instance to published via the restore sweep.');
  }

  /**
   * The hand-authored "Add Instance" caller invokes the birth alter with only
   * $data and no series, and that must neither throw nor stamp a state.
   *
   * EventSeriesController::addInstance() (the /events/series/{eventseries}/add
   * form) builds a $data array carrying eventseries_id + type and calls
   * ->alter('recurring_events_event_instance', $data) with no second argument,
   * so the alter's series parameter arrives NULL. This exercises exactly that
   * shape: the alter must run without a TypeError and must leave
   * moderation_state unset, so the eventinstance form is born in the workflow's
   * own draft default rather than being stamped published/archived. A
   * regression here (a non-nullable series parameter) white-screens the Add
   * Instance form for every editor.
   */
  public function testAddInstanceAlterShapeLeavesStateUnstamped(): void {
    $data = [
      'eventseries_id' => 1,
      'type' => 'default',
    ];
    \Drupal::moduleHandler()->alter('recurring_events_event_instance', $data);

    $this->assertArrayNotHasKey('moderation_state', $data,
      'With no series context (the hand-authored Add Instance path), the birth alter leaves moderation_state unstamped so the occurrence keeps the workflow draft default.');
  }

  /**
   * The composed cancel+rebuild save from the series-reactions work
   * (SeriesReactionsTest::testCancelAndRebuildComposeInOneSave()) is now
   * assertable on the resulting instance's birth state: one save that both
   * archives the series AND changes its dates rebuilds the instance set
   * BEFORE the state reactions observe the transition (maybeRebuildInstances()
   * runs first in EventStateReactions::seriesUpdate()), so the rebuild-
   * created instance is born under the series' NEW (already-archived at
   * alter time, since $data is built inside the same save that is
   * transitioning to archived) state — archived, not published nor draft.
   */
  public function testComposedCancelRebuildLeavesInstancesArchived(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $seriesId = (int) $series->id();

    $series = EventSeries::load($seriesId);
    $existing = $series->get('custom_date')->getValue();
    $existing[0]['value'] = '2999-07-01T10:00:00';
    $existing[0]['end_value'] = '2999-07-01T12:00:00';
    $series->set('custom_date', $existing);
    $series->set('moderation_state', 'archived');
    $series->save();

    $instances = $this->loadInstances(EventSeries::load($seriesId));
    $this->assertCount(1, $instances);
    $instance = reset($instances);
    $this->assertSame('2999-07-01T10:00:00', $instance->get('date')->value);
    $this->assertSame('archived', $instance->get('moderation_state')->value,
      'The rebuild-created instance from a composed cancel+date-change save is born archived under the now-archiving series.');
  }

}
