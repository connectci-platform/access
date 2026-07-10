<?php

namespace Drupal\Tests\user_profiles\Kernel;

use Drupal\KernelTests\KernelTestBase;
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
  protected static $modules = ['system', 'user', 'field', 'flag'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('flagging');
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
      'node_references' => 0,
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
          'node_references' => 0,
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

}
