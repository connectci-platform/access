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
 * Tests view access to private affinity groups (D8-2887).
 *
 * The entity access hook must decide from the account being checked, not the
 * logged-in user: a private group is visible only to users in
 * field_ag_private_users or field_coordinator, whoever is currently logged in.
 *
 * @covers ::access_affinitygroup_entity_access
 * @group access_affinitygroup
 */
class AffinityGroupEntityAccessTest extends KernelTestBase {

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

    FieldStorageConfig::create([
      'field_name' => 'field_ag_private',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ag_private',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
    foreach (['field_ag_private_users', 'field_coordinator'] as $userRef) {
      FieldStorageConfig::create([
        'field_name' => $userRef,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => $userRef,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    // access_affinitygroup_entity_presave() fires on every affinity_group save
    // and needs the affinity_group_leader role + affinity_groups vocab + a few
    // string fields to complete without erroring (CC disabled by default).
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();
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
    foreach (['field_group_slug', 'field_ext_email_list', 'field_list_id'] as $agString) {
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

    // Burn uid 1 (the superuser, which bypasses access checks).
    $this->createUser();
  }

  /**
   * Creates a saved, published affinity_group node.
   *
   * @param bool $private
   *   Whether the group is private.
   * @param int[] $privateUids
   *   User ids for field_ag_private_users.
   * @param int[] $coordinatorUids
   *   User ids for field_coordinator.
   */
  protected function createGroup(bool $private, array $privateUids = [], array $coordinatorUids = []): NodeInterface {
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Access Group',
      'status' => 1,
      'field_ag_private' => (int) $private,
      'field_ag_private_users' => $privateUids,
      'field_coordinator' => $coordinatorUids,
      'field_group_slug' => 'access-group',
      'field_use_ext_email_list' => 0,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Clears the node access handler's static cache.
   */
  protected function resetAccessCache(): void {
    \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
  }

  /**
   * A private group is judged by the checked account, not the logged-in user.
   */
  public function testPrivateGroupUsesCheckedAccount(): void {
    $userA = $this->createUser(['access content']);
    $userB = $this->createUser(['access content']);
    $userC = $this->createUser(['access content']);
    $userD = $this->createUser(['access content']);
    $group = $this->createGroup(TRUE, [(int) $userA->id()], [(int) $userC->id()]);

    $this->setCurrentUser($userD);
    $this->resetAccessCache();
    $this->assertTrue($group->access('view', $userA), 'Private-users member can view.');
    $this->resetAccessCache();
    $this->assertFalse($group->access('view', $userB), 'Unrelated user cannot view.');
    $this->resetAccessCache();
    $this->assertTrue($group->access('view', $userC), 'Coordinator can view.');

    // The logged-in user being a member must not leak to other accounts.
    $this->setCurrentUser($userA);
    $this->resetAccessCache();
    $this->assertFalse($group->access('view', $userB), 'Unrelated user still cannot view when a member is logged in.');
  }

  /**
   * A non-private group stays viewable by an unrelated user.
   */
  public function testNonPrivateGroupIsViewable(): void {
    $userB = $this->createUser(['access content']);
    $userD = $this->createUser(['access content']);
    $group = $this->createGroup(FALSE);

    $this->setCurrentUser($userD);
    $this->resetAccessCache();
    $this->assertTrue($group->access('view', $userB));
  }

  /**
   * The private-group result varies per user so it is not cached across them.
   */
  public function testPrivateGroupResultCachesPerUser(): void {
    $userA = $this->createUser(['access content']);
    $userB = $this->createUser(['access content']);
    $group = $this->createGroup(TRUE, [(int) $userA->id()]);

    $this->resetAccessCache();
    $result = $group->access('view', $userB, TRUE);
    $this->assertTrue($result->isForbidden());
    $this->assertContains('user', $result->getCacheContexts());
  }

}
