<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access\AccessIdResolver;
use Drupal\access_affinitygroup\Access\ActingUserAccess;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves the real ActingUserAccess gate resolves X-Acting-User end to end.
 *
 * Tasks 3-4 set acting_user_uid directly in their fixtures. This test proves
 * the real gate resolves it from the X-Acting-User header via
 * ActingUserAccess -> AccessIdResolver, the integration point most likely to
 * break silently for the profile endpoint.
 *
 * @group access_affinitygroup
 */
class ProfileEndpointGateTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = ['system', 'user', 'field', 'options', 'key', 'access', 'access_affinitygroup'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    Role::create(['id' => 'mcp_service', 'label' => 'MCP Service'])->save();
    $this->createAuthmapTable();
  }

  /*
   * --- helpers copied verbatim from ActingUserAccessTest ---
   */

  /**
   * Creates the openid_connect_authmap table.
   *
   * Mirrors the shape openid_connect_schema() installs (aid/uid/client_name/
   * sub), which is all this gate reads.
   */
  private function createAuthmapTable(): void {
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

  /**
   * Creates an active mcp_service caller.
   */
  private function makeServiceCaller(string $name): User {
    $service = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
    $service->addRole('mcp_service');
    $service->save();
    return $service;
  }

  /**
   * Writes an openid_connect authmap row mapping $sub to $user.
   *
   * Mirrors what CILogon first-login provisioning records: the `sub` is the
   * full ACCESS ID, while the Drupal username is display-style.
   */
  private function writeAuthmap(User $user, string $sub, string $client = 'cilogon'): void {
    \Drupal::database()->insert('openid_connect_authmap')
      ->fields([
        'uid' => (int) $user->id(),
        'client_name' => $client,
        'sub' => $sub,
      ])
      ->execute();
  }

  /*
   * --- end helpers copied verbatim from ActingUserAccessTest ---
   */

  /**
   * Builds the gate under test, wired to a real AccessIdResolver.
   */
  private function gate(): ActingUserAccess {
    return new ActingUserAccess(new AccessIdResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
    ));
  }

  /**
   * An mcp_service caller sending X-Acting-User resolves to the target uid.
   */
  public function testServiceCallerResolvesActingUser(): void {
    $caller = $this->makeServiceCaller('mcp-svc');
    $target = User::create(['name' => 'target-user@access-ci.org', 'mail' => 't@example.com', 'status' => 1]);
    $target->save();
    $this->writeAuthmap($target, 'target-user@access-ci.org');

    $request = Request::create('/api/1.0/profile');
    $request->headers->set('X-Acting-User', 'target-user@access-ci.org');
    $result = $this->gate()->check($caller, $request);

    $this->assertTrue($result->isAllowed());
    $this->assertSame((int) $target->id(), (int) $request->attributes->get('acting_user_uid'));
  }

  /**
   * A non-mcp_service caller sending X-Acting-User is forbidden outright.
   */
  public function testNonServiceCallerWithHeaderForbidden(): void {
    $plain = User::create(['name' => 'plain', 'mail' => 'p@example.com', 'status' => 1]);
    $plain->save();

    $request = Request::create('/api/1.0/profile');
    $request->headers->set('X-Acting-User', 'target-user@access-ci.org');
    $result = $this->gate()->check($plain, $request);

    $this->assertTrue($result->isForbidden());
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * An ACCESS ID with no Drupal account is forbidden, never another user.
   */
  public function testUnknownAccessIdForbidden(): void {
    $caller = $this->makeServiceCaller('mcp-svc');
    $other = User::create(['name' => 'someone@access-ci.org', 'mail' => 's@example.com', 'status' => 1]);
    $other->save();
    $this->writeAuthmap($other, 'someone@access-ci.org');

    $request = Request::create('/api/1.0/profile');
    $request->headers->set('X-Acting-User', 'nobody@access-ci.org');
    $result = $this->gate()->check($caller, $request);

    $this->assertTrue($result->isForbidden());
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

}
