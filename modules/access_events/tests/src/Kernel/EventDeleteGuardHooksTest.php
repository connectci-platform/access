<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Covers the delete-side registrant guard at the entity-hook layer.
 *
 * @group access_events
 *
 * An eventseries or eventinstance with ANY registrations can never be
 * hard-deleted, from any path. This exercises the guard installed on:
 *  - access_events_eventseries_predelete() — direct $series->delete();
 *  - access_events_eventinstance_predelete() — direct $instance->delete();
 *  - access_events_recurring_events_pre_delete_instance() — the contrib hook
 *    EventInstanceDeleteForm/OrphanedEventInstanceForm invoke BEFORE calling
 *    $instance->delete(), and which recurring_events_registration's own
 *    implementation of the SAME hook uses to notify-then-delete the
 *    registrant. access_events_module_implements_alter() forces our module
 *    to run before recurring_events_registration on this hook — natural
 *    module-list order alone does NOT guarantee it, as the first assertion
 *    below verifies directly — so our implementation's throw aborts
 *    moduleHandler::invokeAll() before recurring_events_registration's
 *    implementation ever runs. This is simulated directly here via
 *    invokeAll(), the same call the two contrib forms make.
 *
 * All three guards delegate to EventDeleteGuard::assertDeletable() — see
 * EventDeleteGuardTest for the count/message logic itself.
 */
class EventDeleteGuardHooksTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
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

    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      [
        'use editorial transition create_new_draft',
        'use editorial transition archived_draft',
        'use editorial transition review_to_review',
        'use editorial transition send_for_review',
      ],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Direct $series->delete() with a registrant throws; nothing is removed.
   *
   * SqlContentEntityStorage::delete() wraps ANY exception thrown by a
   * predelete hook in \Drupal\Core\Entity\EntityStorageException (with our
   * \RuntimeException as ->getPrevious()) and rolls back the transaction
   * that wraps the whole delete — so the entity, its storage row, and
   * anything the predelete would have cascaded all survive intact.
   *
   * The order assertion below isolates WHY this passes: contrib's own
   * eventseries_predelete() (recurring_events_eventseries_predelete(), which
   * cascades to instance deletes) never touches a registrant directly, so
   * this test would pass even if access_events ran second here — proving the
   * THROW works but NOT that the reorder in
   * access_events_module_implements_alter() is load-bearing for it. Pinning
   * the order separates "works because of the reorder" from "works because
   * contrib's series hook happens not to touch registrants directly" (the
   * latter is true today but is not a guarantee this test should rely on
   * silently).
   */
  public function testDirectSeriesDeleteWithRegistrantThrowsAndSurvives(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $registrant = $this->registerUser($this->createUser(), $instance);
    $series = $instance->getEventSeries();
    $seriesId = (int) $series->id();
    $instanceId = (int) $instance->id();
    $registrantId = (int) $registrant->id();

    $handler = \Drupal::moduleHandler();
    $method = new \ReflectionMethod($handler, 'getImplementationInfo');
    $method->setAccessible(TRUE);
    $order = array_keys($method->invoke($handler, 'eventseries_predelete'));
    $this->assertSame(
      'access_events',
      $order[0] ?? NULL,
      'access_events must run FIRST on eventseries_predelete — see access_events_module_implements_alter().',
    );

    try {
      $series->delete();
      $this->fail('Expected EntityStorageException wrapping the guard throw.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertStringContainsString('This event has 1 registration(s) and cannot be deleted.', $e->getPrevious()->getMessage());
    }
    $etm = \Drupal::entityTypeManager();
    $this->assertNotNull($etm->getStorage('eventseries')->loadUnchanged($seriesId), 'Series survives the aborted delete.');
    $this->assertNotNull($etm->getStorage('eventinstance')->loadUnchanged($instanceId), 'Instance survives the aborted delete.');
    $this->assertNotNull($etm->getStorage('registrant')->loadUnchanged($registrantId), 'Registrant survives the aborted delete.');
  }

  /**
   * Direct $instance->delete() with a registrant throws; nothing is removed.
   *
   * Same EntityStorageException-wrapping behavior as the series case above.
   */
  public function testDirectInstanceDeleteWithRegistrantThrowsAndSurvives(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $registrant = $this->registerUser($this->createUser(), $instance);
    $instanceId = (int) $instance->id();
    $registrantId = (int) $registrant->id();

    try {
      $instance->delete();
      $this->fail('Expected EntityStorageException wrapping the guard throw.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertStringContainsString('This event has 1 registration(s) and cannot be deleted.', $e->getPrevious()->getMessage());
    }
    $etm = \Drupal::entityTypeManager();
    $this->assertNotNull($etm->getStorage('eventinstance')->loadUnchanged($instanceId), 'Instance survives the aborted delete.');
    $this->assertNotNull($etm->getStorage('registrant')->loadUnchanged($registrantId), 'Registrant survives the aborted delete.');
  }

  /**
   * The contrib pre_delete_instance hook aborts before contrib deletes.
   *
   * This simulates exactly what EventInstanceDeleteForm::submitForm() and
   * OrphanedEventInstanceForm::submitForm() do: invokeAll the hook, THEN
   * call $instance->delete(). WITHOUT access_events_module_implements_alter()
   * forcing our implementation first, this site's actual module-list order
   * puts recurring_events_registration ahead of access_events on this hook
   * (verified directly below — install order alone is not a reliable
   * guarantee), so the first assertion pins the ordering the guard depends
   * on, and the second proves the practical effect: the registrant survives
   * because our throw aborts invokeAll() before the registration module's
   * implementation runs.
   */
  public function testPreDeleteInstanceHookAbortsBeforeRegistrationModuleDeletes(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $registrant = $this->registerUser($this->createUser(), $instance);
    $registrantId = (int) $registrant->id();

    $handler = \Drupal::moduleHandler();
    $method = new \ReflectionMethod($handler, 'getImplementationInfo');
    $method->setAccessible(TRUE);
    $order = array_keys($method->invoke($handler, 'recurring_events_pre_delete_instance'));
    $this->assertSame(
      'access_events',
      $order[0] ?? NULL,
      'access_events must run FIRST on recurring_events_pre_delete_instance — see access_events_module_implements_alter().',
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('This event has 1 registration(s) and cannot be deleted.');
    try {
      \Drupal::moduleHandler()->invokeAll('recurring_events_pre_delete_instance', [$instance]);
    }
    finally {
      $survivor = \Drupal::entityTypeManager()->getStorage('registrant')->loadUnchanged($registrantId);
      $this->assertNotNull($survivor, 'Registrant survives — our hook aborted invokeAll() before the registration module notify-then-delete implementation ran.');
    }
  }

  /**
   * Deleting the registrant first releases the guard.
   *
   * The series delete then proceeds normally.
   */
  public function testSeriesDeleteSucceedsOnceRegistrantIsRemoved(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $registrant = $this->registerUser($this->createUser(), $instance);
    $series = $instance->getEventSeries();
    $seriesId = (int) $series->id();

    $registrant->delete();
    $series->delete();

    $this->assertNull(\Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($seriesId));
  }

}
