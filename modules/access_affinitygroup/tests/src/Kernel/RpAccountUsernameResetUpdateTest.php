<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Covers the update hook that clears the mis-sourced rp_username values.
 *
 * @group access_affinitygroup
 */
class RpAccountUsernameResetUpdateTest extends KernelTestBase {

  protected static $modules = [
    'access', 'access_affinitygroup', 'user', 'system', 'key',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    \Drupal::moduleHandler()->loadInclude('access_affinitygroup', 'install');
  }

  private function insertRow(int $uid, string $grant, ?string $rpUsername): void {
    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => $uid,
      'rp_nid' => 10,
      'grant_number' => $grant,
      'project_id' => 4699,
      'resource_id' => 3031,
      'rp_username' => $rpUsername,
      'account_state' => 'active',
      'project_balance' => '12.50',
      'is_expired' => 0,
      'synced_at' => 1750000000,
    ])->execute();
  }

  private function snapshot(): array {
    $rows = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a')
      ->orderBy('uid')
      ->execute()
      ->fetchAllAssoc('uid');
    return array_map(fn ($r) => (array) $r, $rows);
  }

  public function testRpUsernamesAreClearedAndNothingElseChanges(): void {
    $this->insertRow(1, 'AAA111', 'aaadhavan');
    $this->insertRow(2, 'BBB222', 'someone');
    $this->insertRow(3, 'CCC333', NULL);
    $before = $this->snapshot();

    $message = access_affinitygroup_update_10016();
    $this->assertStringContainsString('on 2 access_user_rp_account rows', $message);

    $after = $this->snapshot();
    foreach ($after as $uid => $row) {
      $this->assertNull($row['rp_username'], "uid $uid rp_username cleared");
      $expected = $before[$uid];
      $expected['rp_username'] = NULL;
      $this->assertSame($expected, $row, "uid $uid other columns untouched");
    }
  }

  public function testSecondRunChangesNothing(): void {
    $this->insertRow(1, 'AAA111', 'aaadhavan');
    access_affinitygroup_update_10016();
    $afterFirst = $this->snapshot();

    $message = access_affinitygroup_update_10016();

    $this->assertSame($afterFirst, $this->snapshot());
    $this->assertStringContainsString('on 0 access_user_rp_account rows', $message);
  }

}
