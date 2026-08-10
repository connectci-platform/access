<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the eventseries presave backstop for the reschedule-block constraint.
 *
 * EventSeriesRescheduleBlockTest covers the validate() path. This suite
 * covers the BARE-SAVE path: some save flows (the content-moderation widget,
 * revision reverts) call $entity->save() directly without ever calling
 * validate(), so the constraint never runs there. This hook is the backstop
 * that still blocks a destructive rebuild in that case.
 *
 * @covers \access_events_eventseries_presave
 * @group access_events
 */
class EventSeriesPresaveBackstopTest extends EventKernelTestBase {

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
    // don't fail in presave hooks.
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
   * A bare save (no validate()) with future registrants is blocked.
   *
   * This mirrors the content-moderation widget / revision-revert save paths,
   * which call save() directly and never invoke validate() — so the
   * constraint's own validator never runs. The presave hook must still catch
   * the destructive recur-config change and refuse the save, and the change
   * must not persist.
   */
  public function testBareSaveBlockedWithFutureRegistrants(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $registrant = $this->registerUser($this->createUser(), $instance);

    $existingDates = $series->get('custom_date')->getValue();
    $existingDates[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existingDates);

    // Core's SqlContentEntityStorage wraps a presave-hook exception in an
    // EntityStorageException, with the original RuntimeException as its
    // previous exception.
    $threw = FALSE;
    try {
      $series->save();
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $threw = TRUE;
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertStringContainsString('schedule cannot be rebuilt', $e->getPrevious()->getMessage());
    }
    $this->assertTrue($threw, 'Bare save() must throw when the series has future registrants and its recur config changed.');

    // The custom_date change must not have persisted.
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertCount(1, $reloaded->get('custom_date')->getValue());

    // The registrant must still exist.
    $this->assertNotNull(\Drupal::entityTypeManager()->getStorage('registrant')->loadUnchanged($registrant->id()));
  }

  /**
   * A bare save with NO registrants succeeds.
   */
  public function testBareSaveSucceedsWithNoRegistrants(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);

    $existingDates = $series->get('custom_date')->getValue();
    $existingDates[] = ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'];
    $series->set('custom_date', $existingDates);
    $series->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertCount(2, $reloaded->get('custom_date')->getValue());
  }

  /**
   * A content-only edit (title) with registrants is allowed on bare save.
   *
   * The recur/date fields are untouched, so checkForOriginalRecurConfigChanges()
   * returns FALSE and the hook must not throw.
   */
  public function testBareSaveContentEditAllowedWithRegistrants(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCustomSeriesWithDate($coordinator);
    $instance = $this->loadInstances($series)[array_key_first($this->loadInstances($series))];
    $this->registerUser($this->createUser(), $instance);

    $series->set('title', 'Updated Title')->set('body', 'Updated body copy.');
    $series->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('Updated Title', $reloaded->get('title')->value);
  }

}
