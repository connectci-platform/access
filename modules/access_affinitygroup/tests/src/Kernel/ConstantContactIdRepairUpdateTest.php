<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Covers the update hook that repairs list-shaped Constant Contact IDs.
 *
 * @group access_affinitygroup
 */
class ConstantContactIdRepairUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'access', 'access_affinitygroup', 'user', 'system', 'key', 'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    FieldStorageConfig::create([
      'field_name' => 'field_constant_contact_id',
      'entity_type' => 'user',
      'type' => 'string',
      'cardinality' => 1,
      'settings' => ['max_length' => 255],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_constant_contact_id',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();
    \Drupal::moduleHandler()->loadInclude('access_affinitygroup', 'install');
  }

  /**
   * Creates a user holding the given raw Constant Contact value.
   */
  private function makeUser(string $name, string $value): int {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
      'field_constant_contact_id' => $value,
    ]);
    $user->save();
    return (int) $user->id();
  }

  /**
   * Returns the raw stored value for a user.
   */
  private function rawValue(int $uid): string {
    return (string) \Drupal::database()
      ->select('user__field_constant_contact_id', 'cc')
      ->fields('cc', ['field_constant_contact_id_value'])
      ->condition('entity_id', $uid)
      ->execute()
      ->fetchField();
  }

  /**
   * A list-shaped support value becomes a string and the cache is reset.
   */
  public function testListValueIsRepairedAndCacheReset(): void {
    $uid = $this->makeUser('listuser', '{"test":"","openondemand":"","support":["uuid-1"]}');
    // Load so the entity is cached before the hook runs.
    $this->assertSame('{"test":"","openondemand":"","support":["uuid-1"]}', User::load($uid)->get('field_constant_contact_id')->value);

    $message = access_affinitygroup_update_10018();

    $this->assertStringContainsString('Fixed 1 user(s)', $message);
    $expected = '{"test":"","openondemand":"","support":"uuid-1"}';
    $this->assertSame($expected, $this->rawValue($uid));
    $this->assertSame($expected, User::load($uid)->get('field_constant_contact_id')->value);
  }

  /**
   * Already-correct values are left byte-identical.
   */
  public function testCorrectValueUntouched(): void {
    $value = '{"test":"","openondemand":"","support":"uuid-2"}';
    $uid = $this->makeUser('gooduser', $value);

    $message = access_affinitygroup_update_10018();

    $this->assertStringContainsString('Fixed 0 user(s)', $message);
    $this->assertSame($value, $this->rawValue($uid));
  }

  /**
   * An empty support list becomes an empty string.
   */
  public function testEmptyListBecomesEmptyString(): void {
    $uid = $this->makeUser('emptyuser', '{"support":[]}');

    access_affinitygroup_update_10018();

    $this->assertSame('{"support":""}', $this->rawValue($uid));
  }

  /**
   * Non-JSON values are left unchanged and reported as skipped.
   */
  public function testNonJsonValueSkipped(): void {
    $value = 'not json, mentions support';
    $uid = $this->makeUser('baduser', $value);

    $message = access_affinitygroup_update_10018();

    $this->assertSame($value, $this->rawValue($uid));
    $this->assertStringContainsString('skipped', $message);
    $this->assertStringContainsString('Fixed 0 user(s)', $message);
  }

  /**
   * A second run fixes nothing and leaves data unchanged.
   */
  public function testSecondRunIsNoOp(): void {
    $uid = $this->makeUser('twiceuser', '{"support":["uuid-3"]}');

    access_affinitygroup_update_10018();
    $after_first = $this->rawValue($uid);
    $message = access_affinitygroup_update_10018();

    $this->assertStringContainsString('Fixed 0', $message);
    $this->assertSame($after_first, $this->rawValue($uid));
    $this->assertSame('{"support":"uuid-3"}', $after_first);
  }

}
