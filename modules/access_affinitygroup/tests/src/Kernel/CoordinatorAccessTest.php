<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;

/**
 * Tests the shared coordinator-membership access check.
 *
 * userCoordinatesAllGroups() returns TRUE only when the user is in every
 * group's field_coordinator; a null group, a group missing the field, or a
 * single non-coordinated group makes it FALSE. An empty group list is
 * vacuously TRUE (callers with a group-less subject guard the call).
 *
 * @covers \Drupal\access_affinitygroup\Access\CoordinatorAccess
 * @group access_affinitygroup
 */
class CoordinatorAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'taxonomy',
    'access',
    'access_affinitygroup',
    'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'user']);

    NodeType::create([
      'type' => 'affinity_group',
      'name' => 'Affinity Group',
    ])->save();
    // A bare node type with no field_coordinator, for the missing-field case.
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();

    // access_affinitygroup_entity_presave() fires on every affinity_group save
    // and needs the affinity_group_leader role + affinity_groups vocab + a few
    // string fields to complete without erroring (CC disabled by default).
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_group_slug',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_slug',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_use_ext_email_list',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_use_ext_email_list',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
    foreach (['field_ext_email_list', 'field_list_id'] as $agString) {
      FieldStorageConfig::create([
        'field_name' => $agString,
        'entity_type' => 'node',
        'type' => 'string',
        'cardinality' => 1,
      ])->save();
      FieldConfig::create([
        'field_name' => $agString,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }
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

  /**
   * Creates a SAVED affinity_group node coordinated by the given user ids.
   *
   * @param int[] $coordinatorUids
   *   User ids to place in field_coordinator.
   */
  protected function createAffinityGroupNode(array $coordinatorUids): NodeInterface {
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Coordinator Group',
      'field_coordinator' => $coordinatorUids,
      'field_group_slug' => 'coordinator-group',
      'field_use_ext_email_list' => 0,
    ]);
    $group->save();
    return $group;
  }

  /**
   * A user coordinating every referenced group passes.
   */
  public function testCoordinatorOfAllGroupsReturnsTrue(): void {
    $user = $this->createUser();
    $g1 = $this->createAffinityGroupNode([$user->id()]);
    $g2 = $this->createAffinityGroupNode([$user->id()]);
    $service = \Drupal::service('access_affinitygroup.coordinator_access');
    $this->assertTrue($service->userCoordinatesAllGroups($user, [$g1, $g2]));
  }

  /**
   * A single non-coordinated group fails the whole check.
   */
  public function testNotCoordinatorOfFirstGroupReturnsFalse(): void {
    $user = $this->createUser();
    $g1 = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $g2 = $this->createAffinityGroupNode([$user->id()]);
    $service = \Drupal::service('access_affinitygroup.coordinator_access');
    $this->assertFalse($service->userCoordinatesAllGroups($user, [$g1, $g2]));
  }

  /**
   * A NULL entry in the group array fails closed.
   */
  public function testNullGroupInArrayReturnsFalse(): void {
    $user = $this->createUser();
    $g1 = $this->createAffinityGroupNode([$user->id()]);
    $service = \Drupal::service('access_affinitygroup.coordinator_access');
    $this->assertFalse($service->userCoordinatesAllGroups($user, [$g1, NULL]));
  }

  /**
   * A group with no field_coordinator fails closed.
   */
  public function testGroupMissingCoordinatorFieldReturnsFalse(): void {
    $user = $this->createUser();
    $bare = Node::create(['type' => 'page', 'title' => 'No coordinator field']);
    $bare->save();
    $service = \Drupal::service('access_affinitygroup.coordinator_access');
    $this->assertFalse($service->userCoordinatesAllGroups($user, [$bare]));
  }

  /**
   * An empty group list is vacuously TRUE.
   */
  public function testEmptyGroupArrayReturnsTrueVacuously(): void {
    $user = $this->createUser();
    $service = \Drupal::service('access_affinitygroup.coordinator_access');
    $this->assertTrue($service->userCoordinatesAllGroups($user, []));
  }

}
