<?php

namespace Drupal\Tests\user_profiles\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user_profiles\Exception\UserMergeException;
use Drupal\user_profiles\UserMerger;
use Psr\Log\NullLogger;

/**
 * Kernel tests for UserMerger merge-and-block behavior.
 *
 * @group user_profiles
 */
class UserMergerTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * 'flag' is required because UserMerger injects @flag as a hard
   * constructor dependency; without it the service cannot be instantiated
   * from the container even when no flags exist.
   *
   * @var array<int, string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'node',
    'text',
    'file',
    'paragraphs',
    'entity_reference_revisions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('flagging');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
  }

  /**
   * MergeAndBlock commits transaction, returns summary, and blocks source.
   *
   * Uses an anonymous subclass to override mergeUser() so the test does not
   * depend on the ~20 site-specific custom user fields that the real
   * mergeUser() reads unconditionally. The override performs a small, real,
   * committable action (adding a role to the to-user) proving transaction
   * commit, not rollback. This proves mergeAndBlock returns the summary
   * unchanged, commits the work done by mergeUser (role persists), and
   * blocks (but retains) the source user.
   */
  public function testMergeAndBlockCommitsWorkAndBlocksSource(): void {
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();

    $from = User::create(['name' => 'olduser', 'mail' => 'dup@example.com', 'status' => 1]);
    $from->save();
    $from_id = (int) $from->id();

    $to = User::create(['name' => 'newuser@access-ci.org', 'mail' => 'new@example.com', 'status' => 1]);
    $to->save();
    $to_id = (int) $to->id();

    $expected_summary = [
      'nodes' => 0,
      'user_references' => [],
      'event_series' => 0,
      'event_instances' => 0,
      'event_registrations' => 0,
      'roles' => 1,
      'webform_submissions' => 0,
      'flags' => 0,
    ];

    // Anonymous subclass: overrides mergeUser() to add a role (real, DB-
    // persisted work) and return a representative summary — no custom fields
    // are touched.
    $merger = new class(
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler'),
      $this->container->get('database'),
      $this->container->get('flag'),
      new NullLogger(),
      $this->container->get('entity_field.manager'),
    ) extends UserMerger {

      /**
       * {@inheritdoc}
       */
      public function mergeUser(int $from_user_id, int $to_user_id): array {
        $to = User::load($to_user_id);
        $to->addRole('editor');
        $to->save();
        return [
          'nodes' => 0,
          'user_references' => [],
          'event_series' => 0,
          'event_instances' => 0,
          'event_registrations' => 0,
          'roles' => 1,
          'webform_submissions' => 0,
          'flags' => 0,
        ];
      }

    };

    $summary = $merger->mergeAndBlock($from_id, $to_id);

    // mergeAndBlock must return exactly what mergeUser returned.
    $this->assertSame($expected_summary, $summary);
    // Source user must be BLOCKED but retained (transaction committed, not
    // rolled back). It must still exist so the merge stays recoverable.
    $reloaded_from = User::load($from_id);
    $this->assertNotNull(
      $reloaded_from,
      'Source user must be retained (not deleted) after a successful merge.'
    );
    $this->assertFalse(
      $reloaded_from->isActive(),
      'Source user must be blocked after a successful merge.'
    );
    // Role added inside mergeUser must have persisted (proves commit).
    $reloaded_to = User::load($to_id);
    $this->assertTrue(
      $reloaded_to->hasRole('editor'),
      'Role added during merge must persist after successful mergeAndBlock.'
    );
  }

  /**
   * A failure mid-merge rolls back fully and leaves the source intact.
   */
  public function testMidMergeFailureRollsBack(): void {
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();

    $from = User::create(['name' => 'olduser', 'mail' => 'dup@example.com', 'status' => 1]);
    $from->addRole('editor');
    $from->save();
    $from_id = (int) $from->id();

    $to = User::create(['name' => 'newuser@access-ci.org', 'mail' => 'dup@example.com', 'status' => 1]);
    $to->save();
    $to_id = (int) $to->id();

    // A merger whose mergeUser throws AFTER doing real work, simulating a
    // failure partway through the merge.
    $failing = new class(
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler'),
      $this->container->get('database'),
      $this->container->get('flag'),
      new NullLogger(),
      $this->container->get('entity_field.manager'),
    ) extends UserMerger {

      /**
       * {@inheritdoc}
       */
      public function mergeUser(int $from_user_id, int $to_user_id): array {
        // Do real, committable-looking work first...
        $to = User::load($to_user_id);
        $to->addRole('editor');
        $to->save();
        // ...then fail before completion.
        throw new \RuntimeException('boom');
      }

    };

    $this->expectException(UserMergeException::class);
    try {
      $failing->mergeAndBlock($from_id, $to_id);
    }
    finally {
      // Source NOT deleted.
      $this->assertNotNull(User::load($from_id), 'Source user must survive a failed merge.');
      // Role change rolled back.
      $this->assertFalse(User::load($to_id)->hasRole('editor'), 'Partial merge work must roll back.');
      // From-user must be fully intact (unchanged).
      $this->assertTrue(
        User::load($from_id)->hasRole('editor'),
        'From-user content must be fully intact after rollback.'
      );
    }
  }

  /**
   * Builds a UserMerger from container services.
   *
   * The user_profiles module is not enabled because its hooks depend on
   * unrelated site modules (e.g. access_badges), so the service is not in the
   * container; construct it the same way the services.yml does.
   */
  protected function createMerger(): UserMerger {
    return new UserMerger(
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler'),
      $this->container->get('database'),
      $this->container->get('flag'),
      new NullLogger(),
      $this->container->get('entity_field.manager'),
    );
  }

  /**
   * Creates an entity_reference field to users on a bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $field_name
   *   The field name.
   * @param int $cardinality
   *   The field cardinality.
   */
  protected function createUserReferenceField(string $entity_type, string $bundle, string $field_name, int $cardinality): void {
    $storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    if (!$storage) {
      $storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'entity_reference',
        'cardinality' => $cardinality,
        'settings' => ['target_type' => 'user'],
      ]);
      $storage->save();
    }
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $field_name,
    ])->save();
  }

  /**
   * MergeUserReferences rewrites every configurable user-reference field.
   */
  public function testMergeUserReferencesRewritesAllUserFields(): void {
    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity group'])->save();
    NodeType::create(['type' => 'match_engagement', 'name' => 'Match engagement'])->save();
    ParagraphsType::create(['id' => 'review_feedback', 'label' => 'Review feedback'])->save();

    $this->createUserReferenceField('node', 'affinity_group', 'field_ag_private_users', -1);
    $this->createUserReferenceField('node', 'match_engagement', 'field_mentor', -1);
    $this->createUserReferenceField('paragraph', 'review_feedback', 'field_rvf_author', 1);

    $user_a = User::create(['name' => 'user_a', 'mail' => 'a@example.com', 'status' => 1]);
    $user_a->save();
    $user_b = User::create(['name' => 'user_b', 'mail' => 'b@example.com', 'status' => 1]);
    $user_b->save();
    $a_id = (int) $user_a->id();
    $b_id = (int) $user_b->id();

    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Group',
      'uid' => $b_id,
      'field_ag_private_users' => [['target_id' => $a_id]],
    ]);
    $group->save();

    $engagement = Node::create([
      'type' => 'match_engagement',
      'title' => 'Engagement',
      'uid' => $b_id,
      'field_mentor' => [['target_id' => $a_id], ['target_id' => $b_id]],
    ]);
    $engagement->save();

    $paragraph = Paragraph::create([
      'type' => 'review_feedback',
      'field_rvf_author' => [['target_id' => $a_id]],
    ]);
    $paragraph->save();

    // A node owned by user A: the uid base field must NOT be rewritten.
    $owned = Node::create([
      'type' => 'affinity_group',
      'title' => 'Owned by A',
      'uid' => $a_id,
    ]);
    $owned->save();

    $summary = $this->createMerger()
      ->mergeUserReferences($user_a, $user_b);

    $storage_manager = $this->container->get('entity_type.manager');
    $storage_manager->getStorage('node')->resetCache();
    $storage_manager->getStorage('paragraph')->resetCache();

    $group = Node::load($group->id());
    $this->assertSame([(string) $b_id], array_column($group->get('field_ag_private_users')->getValue(), 'target_id'));

    $engagement = Node::load($engagement->id());
    $mentors = array_column($engagement->get('field_mentor')->getValue(), 'target_id');
    $this->assertCount(1, $mentors, 'The to-user must appear only once.');
    $this->assertSame([(string) $b_id], $mentors);

    $paragraph = Paragraph::load($paragraph->id());
    $this->assertSame((string) $b_id, (string) $paragraph->get('field_rvf_author')->target_id);

    $owned = Node::load($owned->id());
    $this->assertSame($a_id, (int) $owned->getOwnerId(), 'Base field owner must not be changed.');

    $expected = [
      'node.field_ag_private_users' => 1,
      'node.field_mentor' => 1,
      'paragraph.field_rvf_author' => 1,
    ];
    ksort($summary);
    ksort($expected);
    $this->assertEquals($expected, $summary);
  }

  /**
   * MergeUserReferences returns an empty array when nothing references user.
   */
  public function testMergeUserReferencesNoMatches(): void {
    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity group'])->save();
    $this->createUserReferenceField('node', 'affinity_group', 'field_ag_private_users', -1);

    $user_b = User::create(['name' => 'user_b', 'mail' => 'b@example.com', 'status' => 1]);
    $user_b->save();
    $user_c = User::create(['name' => 'user_c', 'mail' => 'c@example.com', 'status' => 1]);
    $user_c->save();

    Node::create([
      'type' => 'affinity_group',
      'title' => 'Group',
      'field_ag_private_users' => [['target_id' => $user_b->id()]],
    ])->save();

    $summary = $this->createMerger()
      ->mergeUserReferences($user_c, $user_b);
    $this->assertSame([], $summary);
  }

}
