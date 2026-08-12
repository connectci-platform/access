<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\AccessIdResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Covers the canonical ACCESS-ID -> user resolution.
 *
 * @group access
 */
class AccessIdResolverTest extends KernelTestBase {

  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    $this->createAuthmapTable();
  }

  /**
   * Creates the openid_connect_authmap table (contrib-owned).
   */
  protected function createAuthmapTable(): void {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('openid_connect_authmap')) {
      return;
    }
    $schema->createTable('openid_connect_authmap', [
      'fields' => [
        'aid' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'uid' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'client_name' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'sub' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
      ],
      'primary key' => ['aid'],
    ]);
  }

  protected function writeAuthmap(User $user, string $sub, string $client = 'cilogon'): void {
    \Drupal::database()->insert('openid_connect_authmap')
      ->fields([
        'uid' => (int) $user->id(),
        'client_name' => $client,
        'sub' => $sub,
      ])
      ->execute();
  }

  protected function resolver(): AccessIdResolver {
    return new AccessIdResolver(\Drupal::entityTypeManager(), \Drupal::database());
  }

  public function testBareAccessIdResolves(): void {
    $user = User::create(['name' => 'display-name-1', 'mail' => 'd1@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale1@access-ci.org');

    $resolved = $this->resolver()->resolve('apasquale1');

    $this->assertNotNull($resolved);
    $this->assertSame((int) $user->id(), (int) $resolved->id());
  }

  public function testFullAccessIdResolves(): void {
    $user = User::create(['name' => 'display-name-2', 'mail' => 'd2@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale2@access-ci.org');

    $resolved = $this->resolver()->resolve('apasquale2@access-ci.org');

    $this->assertNotNull($resolved);
    $this->assertSame((int) $user->id(), (int) $resolved->id());
  }

  public function testUsernameIsInert(): void {
    $user = User::create(['name' => 'plainname', 'mail' => 'p@example.com', 'status' => 1]);
    $user->save();

    $this->assertNull($this->resolver()->resolve('plainname'));
  }

  public function testEmailIsInert(): void {
    $user = User::create(['name' => 'display-name-3', 'mail' => 'someone@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'someone1@access-ci.org');

    $this->assertNull($this->resolver()->resolve('someone@example.com'));
  }

  public function testForeignDomainIsInert(): void {
    $user = User::create(['name' => 'fred', 'mail' => 'f@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'fred@gmail.com');

    $this->assertNull($this->resolver()->resolve('fred@gmail.com'));
  }

  public function testBlockedUserDoesNotResolve(): void {
    $user = User::create(['name' => 'blocked-display', 'mail' => 'b@example.com', 'status' => 0]);
    $user->save();
    $this->writeAuthmap($user, 'blockedguy@access-ci.org');

    $this->assertNull($this->resolver()->resolve('blockedguy'));
  }

  public function testOpaqueSubIsNotMatched(): void {
    $user = User::create(['name' => 'Eric.Brown.Test', 'mail' => 'eb@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'http://cilogon.org/serverA/users/31508341');

    $this->assertNull($this->resolver()->resolve('Eric.Brown.Test'));
  }

  public function testUppercaseDomainAccepted(): void {
    $user = User::create(['name' => 'upper-display', 'mail' => 'u@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'upperguy@access-ci.org');

    $resolved = $this->resolver()->resolve('upperguy@ACCESS-CI.ORG');

    $this->assertNotNull($resolved);
    $this->assertSame((int) $user->id(), (int) $resolved->id());
  }

  public function testEmptyValueResolvesNothing(): void {
    $this->assertNull($this->resolver()->resolve(''));
    $this->assertNull($this->resolver()->resolve('   '));
  }

}
