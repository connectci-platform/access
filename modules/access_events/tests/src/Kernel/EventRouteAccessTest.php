<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_affinitygroup\Access\ActingUserAccess;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ActingUserAccess gate that guards both event routes.
 *
 * The A2/A3 controller tests call the controller methods directly with
 * `acting_user_uid` pre-set on the request, so they never exercise the
 * actual `_custom_access: 'access_affinitygroup.acting_user_access:check'` gate
 * declared on `access_events.event_detail` and `access_events.event_register`.
 * This task covers that gate.
 *
 * It mirrors the authoritative precedent
 * `Drupal\Tests\access_affinitygroup\Kernel\ActingUserAccessTest`: a kernel test
 * that instantiates the gate directly (`new ActingUserAccess(entityTypeManager,
 * database)`)
 * and calls `check($account, $request)` against synthetic `Request` objects
 * carrying the `X-Acting-User` header. There are ZERO Functional/BrowserTestBase
 * tests under `web/modules/custom/access`, and the gate has no HTTP header
 * harness to mirror, so it is exercised as a unit against the same gate the
 * event routes reference — testing the gate once covers both routes.
 *
 * Because both event routes reference the SAME `_custom_access` gate, the gate's
 * allow/deny behavior and the `acting_user_uid` attribute it sets (the
 * attribute `EventDetailApiController::get()`/`register()` read) are proven here
 * for both routes at once.
 *
 * PARAM-CONVERTER 404 (routing-layer, not exercised here): both routes declare
 * `eventinstance: '\d+'` + `type: 'entity:eventinstance'`, so an unknown or
 * non-numeric id yields a 404 from Drupal's routing / param-conversion BEFORE
 * the controller (and this gate) runs. That is a framework guarantee, not custom
 * logic; a kernel test that calls the gate directly bypasses routing and cannot
 * assert it. It is verified live against prod in Task A5 (curl an unknown id →
 * 404).
 *
 * @covers \Drupal\access_affinitygroup\Access\ActingUserAccess
 * @group access_events
 */
class EventRouteAccessTest extends EventKernelTestBase {

  /**
   * The ACCESS ID the owner is reachable by (their authmap `sub`).
   */
  private const OWNER_ACCESS_ID = 'event-owner@access-ci.org';

  /**
   * The URL path of one of the guarded event routes, for the synthetic request.
   */
  private string $eventPath;

  /**
   * Creates the openid_connect_authmap table (contrib-owned; see the gate).
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The gate's mcp_service branch requires the role to exist.
    Role::create(['id' => 'mcp_service', 'label' => 'MCP Service'])->save();

    // The gate resolves X-Acting-User against the openid_connect authmap only,
    // so the table plus a row for the owner is what makes them addressable.
    $this->createAuthmapTable();
    \Drupal::database()->insert('openid_connect_authmap')
      ->fields([
        'uid' => (int) $this->owner->id(),
        'client_name' => 'cilogon',
        'sub' => self::OWNER_ACCESS_ID,
      ])
      ->execute();

    // A real registrable instance gives the guarded routes a concrete id to
    // target in the request path (the gate itself never loads the instance, but
    // this keeps the synthetic request faithful to the live route).
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: TRUE);
    $this->eventPath = '/api/1.0/events/' . $instance->id();
  }

  /**
   * Builds the gate the way its service definition wires it.
   *
   * Mirrors ActingUserAccessTest::makeAccess() — `@entity_type.manager` plus
   * `@database` (the latter for the authmap lookup).
   */
  private function makeAccess(): ActingUserAccess {
    return new ActingUserAccess(new \Drupal\access\AccessIdResolver(\Drupal::entityTypeManager(), \Drupal::database()));
  }

  /**
   * An mcp_service account + a resolvable X-Acting-User is allowed, and the
   * gate sets acting_user_uid to the RESOLVED acting user's uid.
   *
   * This is the attribute EventDetailApiController reads as the acting uid, so
   * asserting it here is what proves the gate feeds the event controllers the
   * right identity.
   */
  public function testGateAllowsMcpServiceAndSetsEffectiveUid(): void {
    $service = User::create([
      'name' => 'mcp-svc',
      'mail' => 'svc@example.com',
      'status' => 1,
    ]);
    $service->addRole('mcp_service');
    $service->save();

    $request = Request::create($this->eventPath);
    // The acting user is the pre-seeded, active $this->owner.
    $request->headers->set('X-Acting-User', self::OWNER_ACCESS_ID);

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame(
      (int) $this->owner->id(),
      $request->attributes->get('acting_user_uid'),
    );
    // The effective uid is the ACTING user, not the service account.
    $this->assertNotSame(
      (int) $service->id(),
      $request->attributes->get('acting_user_uid'),
    );
  }

  /**
   * An X-Acting-User header WITHOUT the mcp_service role is forbidden, and no
   * effective uid leaks through.
   */
  public function testGateDeniesActingHeaderWithoutMcpServiceRole(): void {
    $plain = User::create([
      'name' => 'plain-user',
      'mail' => 'plain@example.com',
      'status' => 1,
    ]);
    $plain->save();

    $request = Request::create($this->eventPath);
    $request->headers->set('X-Acting-User', self::OWNER_ACCESS_ID);

    $result = $this->makeAccess()->check($plain, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * An mcp_service account whose X-Acting-User resolves to NO active user is
   * forbidden (unknown name, and equally a blocked/inactive user).
   */
  public function testGateDeniesWhenActingUserDoesNotResolve(): void {
    $service = User::create([
      'name' => 'mcp-svc-2',
      'mail' => 'svc2@example.com',
      'status' => 1,
    ]);
    $service->addRole('mcp_service');
    $service->save();

    $request = Request::create($this->eventPath);
    // No user carries this name, so resolveActingUser() returns NULL.
    $request->headers->set('X-Acting-User', 'nobody@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * An anonymous account is forbidden before any header handling.
   */
  public function testGateDeniesAnonymous(): void {
    $request = Request::create($this->eventPath);
    $request->headers->set('X-Acting-User', self::OWNER_ACCESS_ID);

    $result = $this->makeAccess()->check(User::getAnonymousUser(), $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

}
