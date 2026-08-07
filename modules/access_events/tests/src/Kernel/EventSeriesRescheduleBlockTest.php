<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the reschedule-block constraint on eventseries.
 *
 * recurring_events rebuilds ALL instances of a series when its recurrence
 * configuration changes, hard-deleting the existing instances and any
 * attached registrant entities. This constraint blocks that change while
 * the series still has future registrants.
 *
 * @covers \Drupal\access_events\Plugin\Validation\Constraint\EventSeriesRescheduleBlockConstraintValidator
 * @group access_events
 */
class EventSeriesRescheduleBlockTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The base module list plus the modules access_events needs to compile. The
   * counter service (access_events.registrant_counter) only lands in the
   * container when access_events is enabled; key + content_moderation +
   * access_misc are hard service-compile dependencies of access_events.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'link',
    'datetime',
    'datetime_range',
    'field_inheritance',
    'recurring_events',
    'recurring_events_registration',
    'taxonomy',
    'key',
    'workflows',
    'content_moderation',
    'access_misc',
    'access_events',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Enabling access_events activates access_events_entity_presave(), which on
    // every eventinstance/eventseries save reads site-level fields absent in
    // this minimal kernel env. Attach them empty so the hook's reads return
    // empty and its conditional blocks are skipped.
    $this->attachInstancePresaveFields();

    // access_events_entity_access() reads field_other_authors on every
    // eventseries access check (unconditionally, no hasField guard), and it
    // must exist to avoid fatals.
    if (!FieldStorageConfig::loadByName('eventseries', 'field_other_authors')) {
      FieldStorageConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }

    // field_affinity_group on node (taxonomy_term reference) — used by
    // createAffinityGroupNode() fixture.
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

    // Grant moderation transitions to authenticated so series/instance saves
    // don't fail in presave hooks, and so validate() on an already-published
    // series (moderation_state untouched, still 'published') does not itself
    // raise a spurious ModerationStateConstraint violation — content_moderation
    // checks transition validity even when the state does not change.
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      [
        'use editorial transition create_new_draft',
        'use editorial transition archived_draft',
        'use editorial transition review_to_review',
        'use editorial transition send_for_review',
        'use editorial transition publish',
      ],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Attaches the empty site-level fields access_events_entity_presave() reads.
   */
  private function attachInstancePresaveFields(): void {
    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A recurrence/date change is blocked while the series has future registrants.
   */
  public function testBlockedWithFutureRegistrants(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);

    $series->set('excluded_dates', [['value' => '2999-06-01', 'end_value' => '2999-06-01']]);

    // Switched: validate() re-checks the (untouched) moderation_state
    // transition, which content_moderation gates on the CURRENT user, not the
    // entity owner.
    $violations = $this->asActingUser($coordinator, fn () => $series->validate());

    $this->assertGreaterThan(0, $violations->count());
    $this->assertStringContainsString(
      'permanently delete',
      (string) $violations->get(0)->getMessage(),
    );
  }

  /**
   * A recurrence/date change is allowed when the series has no registrants.
   */
  public function testAllowedWithNoRegistrants(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);

    $series->set('excluded_dates', [['value' => '2999-06-01', 'end_value' => '2999-06-01']]);

    $violations = $this->asActingUser($coordinator, fn () => $series->validate());

    $this->assertSame(0, $violations->count());
  }

  /**
   * A content-only edit is allowed even with future registrants.
   */
  public function testContentEditAllowedWithRegistrants(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);

    // Recur/date fields are untouched; only content fields change.
    $series->set('title', 'Updated Title')->set('body', 'Updated body copy.');

    $violations = $this->asActingUser($coordinator, fn () => $series->validate());

    $this->assertSame(0, $violations->count());
  }

  /**
   * Creating a new eventseries is never blocked (and must not throw).
   *
   * A new entity's id() is NULL. Without an isNew()/null-id guard,
   * loadUnchanged(NULL) throws (Drupal refuses to load an entity with a NULL
   * id) — or, if it ever returned NULL instead, that NULL would hit
   * checkForOriginalRecurConfigChanges()'s EventSeries type-hint and TypeError.
   * Either way every event creation would fatal. The validator must guard the
   * create path before reaching that call.
   */
  public function testCreateNotBlocked(): void {
    $author = $this->createUser();
    $series = EventSeries::create([
      'title' => 'Brand New Event',
      'body' => 'A new event, never saved.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);

    // Switched: a moderated entity computes moderation_state to the
    // workflow's default state (draft) even unsaved, and content_moderation
    // gates that (no-op) transition on the CURRENT user.
    $violations = $this->asActingUser($author, fn () => $series->validate());

    $this->assertSame(0, $violations->count());
  }

  /**
   * A recurrence/date change is allowed when registrants are past-only.
   */
  public function testPastOnlyRegistrantsAllowed(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $pastInstance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $pastInstance);

    $series->set('excluded_dates', [['value' => '2999-06-01', 'end_value' => '2999-06-01']]);

    $violations = $this->asActingUser($coordinator, fn () => $series->validate());

    $this->assertSame(0, $violations->count());
  }

}
