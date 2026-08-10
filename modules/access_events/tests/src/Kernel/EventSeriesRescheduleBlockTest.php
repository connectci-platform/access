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
      'schedule cannot be rebuilt',
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

  /**
   * A recur/date change is still refused on an ARCHIVED series with
   * registrations not verifiably past.
   *
   * The not-past registrant count swap's countNotPastForSeries() counts registrants independent of the
   * series' OWN moderation_state — the series being archived (not published)
   * must not exempt it. This is the population the destructive rebuild would
   * still hard-delete if the change were allowed through.
   */
  public function testRecurChangeRefusedOnArchivedRegisteredSeries(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);

    $series->set('moderation_state', 'archived')->save();
    $series = EventSeries::load($series->id());

    $series->set('excluded_dates', [['value' => '2999-06-01', 'end_value' => '2999-06-01']]);

    $violations = $this->asActingUser($coordinator, fn () => $series->validate());

    $this->assertGreaterThan(0, $violations->count());
    $this->assertStringContainsString(
      'schedule cannot be rebuilt',
      (string) $violations->get(0)->getMessage(),
    );
  }

  /**
   * A recur/date change is refused when a registrant's instance has a NULL
   * end date.
   *
   * countNotPastForSeries() treats a NULL/unparseable end_value as "not
   * verifiably past" (uncertainty favors the registrant), unlike the old
   * countFutureForSeries() SQL boundary (`end_value > now`), which is FALSE
   * for NULL and so misses this registrant entirely. The DB-level NULL is
   * seeded directly — the entity API refuses to persist a NULL end_value.
   */
  public function testNullEndRegistrantBlocksRecurChange(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);

    $entityType = $instance->getEntityType();
    $tableName = $entityType->getDataTable() ?: $entityType->getBaseTable();
    \Drupal::database()->update($tableName)
      ->fields(['date__end_value' => NULL])
      ->condition('id', $instance->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache([$instance->id()]);

    $series->set('excluded_dates', [['value' => '2999-06-01', 'end_value' => '2999-06-01']]);

    $violations = $this->asActingUser($coordinator, fn () => $series->validate());

    $this->assertGreaterThan(0, $violations->count());
    $this->assertStringContainsString(
      'schedule cannot be rebuilt',
      (string) $violations->get(0)->getMessage(),
    );
  }

  /**
   * The request-adjustment refusal: Request Adjustment on a published, registered series is refused.
   */
  public function testRequestAdjustmentRefusedWithRegistrants(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);

    $series->set('moderation_state', 'needs_adjustment');

    $violations = $this->asActingUser($newsPm, fn () => $series->validate());

    $this->assertGreaterThan(0, $violations->count());
    $messages = array_map(
      fn ($violation) => (string) $violation->getMessage(),
      iterator_to_array($violations),
    );
    $this->assertTrue(
      (bool) array_filter($messages, fn ($m) => str_contains($m, 'Request Adjustment would hide')),
      'Expected the Request Adjustment refusal message among: ' . implode(' | ', $messages),
    );

    // Bare-save mirror: access_events_eventseries_presave() must throw too,
    // for any save path that skips validate() entirely.
    $threw = FALSE;
    try {
      $this->asActingUser($newsPm, function () use ($series) {
        $series->save();
      });
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $threw = TRUE;
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertStringContainsString('Request Adjustment would hide', $e->getPrevious()->getMessage());
    }
    $this->assertTrue($threw, 'Bare save() must throw for Request Adjustment on a published, registered series.');
  }

  /**
   * The request-adjustment refusal's permitted path: Request Adjustment with NO registrants runs the cancel sweep
   * (zero emails — notifications are not enabled in this test).
   */
  public function testRequestAdjustmentPermittedRunsCancelSemantics(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);

    $violations = $this->asActingUser($newsPm, fn () => (clone $series)->set('moderation_state', 'needs_adjustment')->validate());
    $this->assertSame(0, $violations->count());

    $this->asActingUser($newsPm, function () use ($series) {
      $series->set('moderation_state', 'needs_adjustment')->save();
    });

    foreach ($this->loadInstances($series) as $instance) {
      $reloaded = $this->reloadInstance($instance);
      $this->assertSame('archived', $reloaded->get('moderation_state')->value);
    }

    // Notifications are not enabled in this test (enableEventNotifications()
    // was never called), so the cancel sweep's cancellation notice is gated shut
    // — zero emails queued, even though instances were archived.
    $this->assertQueueCount('event_cancelled_notification', 0);
  }

}
