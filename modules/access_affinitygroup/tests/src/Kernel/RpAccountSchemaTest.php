<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the access_user_rp_account schema and CRUD operations.
 *
 * @group access_affinitygroup
 */
class RpAccountSchemaTest extends KernelTestBase {

  protected static $modules = ['access_affinitygroup', 'user', 'system'];

  public function testSchemaCreatesTable(): void {
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    $schema = \Drupal::database()->schema();
    $this->assertTrue($schema->tableExists('access_user_rp_account'));

    foreach ([
      'uid', 'rp_nid', 'grant_number', 'project_id', 'resource_id',
      'grant_title', 'rp_username', 'account_state', 'project_balance',
      'project_end', 'project_state', 'is_expired', 'billable_unit',
      'synced_at',
    ] as $column) {
      $this->assertTrue(
        $schema->fieldExists('access_user_rp_account', $column),
        "Column $column should exist."
      );
    }
    $this->assertTrue(
      $schema->indexExists('access_user_rp_account', 'idx_uid_rp'),
      'Index idx_uid_rp should exist.'
    );
    $this->assertTrue(
      $schema->indexExists('access_user_rp_account', 'idx_synced'),
      'Index idx_synced should exist.'
    );
  }

  public function testCrud(): void {
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    $db = \Drupal::database();

    $db->insert('access_user_rp_account')
      ->fields([
        'uid' => 1,
        'rp_nid' => 100,
        'grant_number' => 'TST123',
        'project_id' => 99,
        'resource_id' => 88,
        'grant_title' => 'Test grant',
        'rp_username' => 'testuser',
        'account_state' => 'active',
        'project_balance' => '12345.6789',
        'project_end' => '2026-12-31',
        'project_state' => 'active',
        'is_expired' => 0,
        'billable_unit' => 'Core-hours',
        'synced_at' => 1746737000,
      ])
      ->execute();

    $row = $db->select('access_user_rp_account', 'a')
      ->fields('a')
      ->condition('uid', 1)
      ->condition('rp_nid', 100)
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($row);
    $this->assertSame('testuser', $row['rp_username']);
    $this->assertSame('Test grant', $row['grant_title']);
  }

  public function testPrimaryKeyPreventsExactDuplicate(): void {
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    $db = \Drupal::database();
    $insert = function () use ($db) {
      $db->insert('access_user_rp_account')
        ->fields([
          'uid' => 1, 'rp_nid' => 100, 'grant_number' => 'TST',
          'project_id' => 1, 'resource_id' => 1, 'synced_at' => 0,
        ])
        ->execute();
    };
    $insert();
    $this->expectException(\Drupal\Core\Database\IntegrityConstraintViolationException::class);
    $insert();
  }
}
