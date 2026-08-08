<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Access\ActingUserAccess;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group access_affinitygroup
 */
class ActingUserAccessTest extends KernelTestBase {

  protected static $modules = ['access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    // Create the mcp_service role for the privileged path.
    Role::create(['id' => 'mcp_service', 'label' => 'MCP Service'])->save();
  }

  protected function makeAccess(): ActingUserAccess {
    return new ActingUserAccess(\Drupal::entityTypeManager());
  }

  public function testAnonymousIsForbidden(): void {
    $anon = User::getAnonymousUser();
    $request = Request::create('/api/1.0/rp-account/1');

    $result = $this->makeAccess()->check($anon, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  public function testNoActingHeaderFallsBackToSessionUid(): void {
    $user = User::create(['name' => 'session-user@access-ci.org', 'mail' => 'a@example.com', 'status' => 1]);
    $user->save();

    $request = Request::create('/api/1.0/rp-account/1');

    $result = $this->makeAccess()->check($user, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $user->id(), $request->attributes->get('acting_user_uid'));
  }

  public function testActingHeaderWithoutMcpServiceRoleIsForbidden(): void {
    $user = User::create(['name' => 'plain-user@access-ci.org', 'mail' => 'a@example.com', 'status' => 1]);
    $user->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'somebody');

    $result = $this->makeAccess()->check($user, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    // Critically, no fall-through to session uid happened.
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  public function testActingHeaderResolvesToActiveUserSetsAttribute(): void {
    $service = User::create(['name' => 'mcp-svc', 'mail' => 's@example.com', 'status' => 1]);
    $service->addRole('mcp_service');
    $service->save();

    $target = User::create(['name' => 'target-user@access-ci.org', 'mail' => 't@example.com', 'status' => 1]);
    $target->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'target-user@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $target->id(), $request->attributes->get('acting_user_uid'));
    // Did NOT fall back to the service uid.
    $this->assertNotSame((int) $service->id(), $request->attributes->get('acting_user_uid'));
  }

  public function testActingHeaderResolvesToInactiveUserIsForbidden(): void {
    $service = User::create(['name' => 'mcp-svc-2', 'mail' => 's2@example.com', 'status' => 1]);
    $service->addRole('mcp_service');
    $service->save();

    // Blocked target user.
    $target = User::create(['name' => 'blocked-user@access-ci.org', 'mail' => 'b@example.com', 'status' => 0]);
    $target->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'blocked-user@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  public function testEmailHeaderTakesPrecedenceOverNameHeader(): void {
    $service = User::create(['name' => 'mcp-svc-3', 'mail' => 's3@example.com', 'status' => 1]);
    $service->addRole('mcp_service');
    $service->save();

    $email_user = User::create(['name' => 'email-target@access-ci.org', 'mail' => 'email-target@example.com', 'status' => 1]);
    $email_user->save();

    $name_user = User::create(['name' => 'name-target@access-ci.org', 'mail' => 'name-target@example.com', 'status' => 1]);
    $name_user->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User-Email', 'email-target@example.com');
    $request->headers->set('X-Acting-User', 'name-target@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $email_user->id(), $request->attributes->get('acting_user_uid'));
  }

  public function testResolveAllowsAnonymousWithNoHeader(): void {
    $gate = new ActingUserAccess(\Drupal::entityTypeManager());
    $anon = new \Drupal\Core\Session\AnonymousUserSession();
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/2.3/events/1');
    $result = $gate->resolve($anon, $request);
    $this->assertTrue($result->isAllowed());
    $this->assertLessThan(1, (int) $request->attributes->get('acting_user_uid', 0));
  }

  public function testResolveForbidsHeaderFromNonServiceCaller(): void {
    // Confused-deputy: an anonymous/non-service caller sending X-Acting-User must be FORBIDDEN.
    $gate = new ActingUserAccess(\Drupal::entityTypeManager());
    $target = \Drupal\user\Entity\User::create(['name' => 'cd-target@access-ci.org', 'mail' => 'cd@example.com', 'status' => 1]);
    $target->save();
    $nonService = new \Drupal\Core\Session\AnonymousUserSession();
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/2.3/events/1');
    $request->headers->set('X-Acting-User', $target->getAccountName());
    $result = $gate->resolve($nonService, $request);
    $this->assertTrue($result->isForbidden());
  }

  public function testResolveWithServiceCallerAndHeaderSetsActingUid(): void {
    $service = \Drupal\user\Entity\User::create(['name' => 'resolve-svc', 'mail' => 'rsvc@example.com', 'status' => 1]);
    $service->addRole('mcp_service'); // role already exists from setUp
    $service->save();
    $target = \Drupal\user\Entity\User::create(['name' => 'resolve-target@access-ci.org', 'mail' => 'rt@example.com', 'status' => 1]);
    $target->save();
    $gate = new ActingUserAccess(\Drupal::entityTypeManager());
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/2.3/events/1');
    $request->headers->set('X-Acting-User', $target->getAccountName());
    $result = $gate->resolve($service, $request);
    $this->assertTrue($result->isAllowed());
    $this->assertSame((int) $target->id(), (int) $request->attributes->get('acting_user_uid'));
  }
}
