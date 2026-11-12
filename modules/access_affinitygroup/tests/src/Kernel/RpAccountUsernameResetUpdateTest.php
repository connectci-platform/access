<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Covers the update hook that clears the mis-sourced rp_username values.
 *
 * @group access_affinitygroup
 */
class RpAccountUsernameResetUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'access', 'access_affinitygroup', 'user', 'system', 'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    \Drupal::moduleHandler()->loadInclude('access_affinitygroup', 'install');
  }

  /**
   * Creates a user with the given account name and returns its uid.
   */
  private function makeUser(string $name): int {
    $user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
    $user->save();
    return (int) $user->id();
  }

  /**
   * Inserts one access_user_rp_account row for the given user and grant.
   */
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

  /**
   * Returns every row, keyed by "uid:grant_number".
   */
  private function snapshot(): array {
    $rows = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a')
      ->orderBy('uid')
      ->orderBy('grant_number')
      ->execute()
      ->fetchAll();
    $keyed = [];
    foreach ($rows as $r) {
      $keyed[$r->uid . ':' . $r->grant_number] = (array) $r;
    }
    return $keyed;
  }

  /**
   * Only rows holding the owner's own ACCESS username are cleared.
   */
  public function testOnlyOwnPortalUsernameIsCleared(): void {
    $alice = $this->makeUser('aaadhavan@access-ci.org');
    $walnut = $this->makeUser('test_walnut_pie@access-ci.org');
    $nullUser = $this->makeUser('nullrow@access-ci.org');
    $local = $this->makeUser('localuser');
    $cross = $this->makeUser('crossuser@access-ci.org');

    $this->insertRow($alice, 'AAA111', 'aaadhavan');
    $this->insertRow($walnut, 'WWW111', 'walnut_alpha');
    $this->insertRow($nullUser, 'NNN111', NULL);
    $this->insertRow($local, 'LLL111', 'localuser');
    // Matches a different user's portal username; must not be cleared.
    $this->insertRow($cross, 'XXX111', 'aaadhavan');
    $before = $this->snapshot();

    $message = access_affinitygroup_update_10016();
    $this->assertStringContainsString('on 1 access_user_rp_account rows', $message);

    $after = $this->snapshot();
    $expected = $before;
    $expected["$alice:AAA111"]['rp_username'] = NULL;
    $this->assertSame($expected, $after);
    $this->assertNull($after["$alice:AAA111"]['rp_username']);
    $this->assertSame('walnut_alpha', $after["$walnut:WWW111"]['rp_username']);
    $this->assertNull($after["$nullUser:NNN111"]['rp_username']);
    $this->assertSame('localuser', $after["$local:LLL111"]['rp_username']);
    $this->assertSame('aaadhavan', $after["$cross:XXX111"]['rp_username']);
  }

  /**
   * Re-running the update is a no-op.
   */
  public function testSecondRunChangesNothing(): void {
    $alice = $this->makeUser('aaadhavan@access-ci.org');
    $this->insertRow($alice, 'AAA111', 'aaadhavan');
    access_affinitygroup_update_10016();
    $afterFirst = $this->snapshot();

    $message = access_affinitygroup_update_10016();

    $this->assertSame($afterFirst, $this->snapshot());
    $this->assertStringContainsString('on 0 access_user_rp_account rows', $message);
  }

}
