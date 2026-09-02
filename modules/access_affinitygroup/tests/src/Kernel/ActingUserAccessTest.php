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

  // 'access' provides access.access_id_resolver, which the gate now depends on.
  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    // The authmap table backs the ACCESS-ID -> user mapping for
    // CILogon-provisioned accounts. Only the table is needed here; enabling
    // the whole openid_connect module would pull in file.repository and the
    // rest of its service graph, which this gate does not touch.
    $this->createAuthmapTable();

    // Create the mcp_service role for the privileged path.
    Role::create(['id' => 'mcp_service', 'label' => 'MCP Service'])->save();
  }

  protected function makeAccess(): ActingUserAccess {
    return new ActingUserAccess(new \Drupal\access\AccessIdResolver(\Drupal::entityTypeManager(), \Drupal::database()));
  }

  /**
   * Creates the openid_connect_authmap table.
   *
   * Mirrors the shape openid_connect_schema() installs (aid/uid/client_name/
   * sub), which is all this gate reads.
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

  /**
   * Creates an active mcp_service caller.
   */
  protected function makeServiceCaller(string $name): User {
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
  protected function writeAuthmap(User $user, string $sub, string $client = 'cilogon'): void {
    \Drupal::database()->insert('openid_connect_authmap')
      ->fields([
        'uid' => (int) $user->id(),
        'client_name' => $client,
        'sub' => $sub,
      ])
      ->execute();
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
    $this->writeAuthmap($target, 'target-user@access-ci.org');

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

    // Blocked target user, reachable by ACCESS ID but inactive.
    $target = User::create(['name' => 'blocked-user@access-ci.org', 'mail' => 'b@example.com', 'status' => 0]);
    $target->save();
    $this->writeAuthmap($target, 'blocked-user@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'blocked-user@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * X-Acting-User-Email is INERT: it resolves nobody, even for a real address.
   *
   * The email header was a second, non-ACCESS-ID resolution channel with no
   * senders anywhere in the stack. Emails are mutable, sometimes placeholders,
   * and were never part of the signed assertion chain — the resolution space
   * must equal the assertion space.
   */
  public function testEmailHeaderAloneDoesNotResolve(): void {
    $service = $this->makeServiceCaller('mcp-svc-email-alone');

    $email_user = User::create(['name' => 'email-target@access-ci.org', 'mail' => 'email-target@example.com', 'status' => 1]);
    $email_user->save();
    $this->writeAuthmap($email_user, 'email-target@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User-Email', 'email-target@example.com');

    $result = $this->makeAccess()->check($service, $request);

    // Forbidden, NOT a silent fall-through to acting as the caller: the email
    // header still counts as an acting-header for the trigger check, so a
    // service account sending only it cannot quietly act as itself.
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * With both headers present, the ACCESS ID decides and the email is ignored.
   */
  public function testEmailHeaderIsIgnoredWhenAccessIdResolves(): void {
    $service = $this->makeServiceCaller('mcp-svc-email-inert');

    $email_user = User::create(['name' => 'email-target-2@access-ci.org', 'mail' => 'email-target2@example.com', 'status' => 1]);
    $email_user->save();
    $this->writeAuthmap($email_user, 'email-target-2@access-ci.org');

    $id_user = User::create(['name' => 'id-display-name', 'mail' => 'id-target@example.com', 'status' => 1]);
    $id_user->save();
    $this->writeAuthmap($id_user, 'id-target@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User-Email', 'email-target2@example.com');
    $request->headers->set('X-Acting-User', 'id-target');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $id_user->id(), $request->attributes->get('acting_user_uid'));
  }

  /**
   * A CILogon-provisioned user has a display-style username, so the ACCESS ID
   * only reaches them through the authmap. Bare form.
   */
  public function testAuthmapResolvesDisplayNamedUserFromBareAccessId(): void {
    $service = $this->makeServiceCaller('mcp-svc-authmap-bare');

    $target = User::create(['name' => 'andrew-pasquale-4', 'mail' => 'ap@example.com', 'status' => 1]);
    $target->save();
    $this->writeAuthmap($target, 'apasquale1@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'apasquale1');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $target->id(), $request->attributes->get('acting_user_uid'));
  }

  /**
   * Same user, full-form ACCESS ID in the header.
   */
  public function testAuthmapResolvesDisplayNamedUserFromFullAccessId(): void {
    $service = $this->makeServiceCaller('mcp-svc-authmap-full');

    $target = User::create(['name' => 'andrew-pasquale-5', 'mail' => 'ap5@example.com', 'status' => 1]);
    $target->save();
    $this->writeAuthmap($target, 'apasquale2@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'apasquale2@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $target->id(), $request->attributes->get('acting_user_uid'));
  }

  /**
   * An account with NO authmap row does not resolve, whatever its username.
   *
   * Intended under the ACCESS-ID-only ruling: un-linked legacy accounts (e.g.
   * import-created ones that have never logged in) are out of scope until
   * their first CILogon login writes the row, or until they are merged.
   */
  public function testBareUsernameWithoutAuthmapDoesNotResolve(): void {
    $service = $this->makeServiceCaller('mcp-svc-legacy');

    $target = User::create(['name' => 'apasquale3', 'mail' => 'ap3@example.com', 'status' => 1]);
    $target->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'apasquale3');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * A full-form USERNAME is not an identifier either — only the authmap is.
   */
  public function testFullFormUsernameWithoutAuthmapDoesNotResolve(): void {
    $service = $this->makeServiceCaller('mcp-svc-fullname-noauthmap');

    $target = User::create(['name' => 'apasquale6@access-ci.org', 'mail' => 'ap6@example.com', 'status' => 1]);
    $target->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'apasquale6@access-ci.org');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * Status discipline on the authmap path: a blocked user does not resolve.
   */
  public function testBlockedUserWithAuthmapRowDoesNotResolve(): void {
    $service = $this->makeServiceCaller('mcp-svc-authmap-blocked');

    $target = User::create(['name' => 'blocked-display-name', 'mail' => 'bd@example.com', 'status' => 0]);
    $target->save();
    $this->writeAuthmap($target, 'blockedguy@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'blockedguy');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * An unknown ACCESS ID must still be refused, not silently downgraded.
   */
  public function testUnknownAccessIdIsForbidden(): void {
    $service = $this->makeServiceCaller('mcp-svc-unknown');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'nosuchperson');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * A foreign domain resolves NOTHING and is never probed as a username.
   *
   * "fred@gmail.com" must not reach the local account "fred", nor the account
   * literally named "fred@gmail.com" — a non-ACCESS-CI domain fails before any
   * lookup happens.
   */
  public function testForeignDomainResolvesNothing(): void {
    $service = $this->makeServiceCaller('mcp-svc-foreign');

    // Both a local-part-named account and a literally-named one exist.
    $fred = User::create(['name' => 'fred', 'mail' => 'fred@example.com', 'status' => 1]);
    $fred->save();
    $literal = User::create(['name' => 'fred@gmail.com', 'mail' => 'fred2@example.com', 'status' => 1]);
    $literal->save();

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'fred@gmail.com');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertNull($request->attributes->get('acting_user_uid'));
  }

  /**
   * A foreign-domain value is not rescued by an authmap row on that sub
   * either: the domain check fails before the lookup.
   */
  public function testForeignDomainNotRescuedByAuthmapRow(): void {
    $service = $this->makeServiceCaller('mcp-svc-foreign-authmap');

    $user = User::create(['name' => 'gmail-person', 'mail' => 'gp@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'fred@gmail.com');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'fred@gmail.com');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Documents (does not endorse) case-insensitive matching.
   *
   * `openid_connect_authmap.sub` is utf8mb4_general_ci, so the DB comparison
   * ignores case even though the PHP-side domain check is explicitly
   * lowercased. Recorded so a future move to a _bin/_cs collation surfaces
   * here as a failure.
   */
  public function testMatchingIsCaseInsensitiveViaDbCollation(): void {
    $service = $this->makeServiceCaller('mcp-svc-case');

    $user = User::create(['name' => 'case-display-name', 'mail' => 'ap9@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale9@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'APASQUALE9');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $user->id(), $request->attributes->get('acting_user_uid'));
  }

  /**
   * An uppercase full-form value is still recognized as ACCESS-CI-domained.
   */
  public function testUppercaseDomainIsAccepted(): void {
    $service = $this->makeServiceCaller('mcp-svc-upper-domain');

    $user = User::create(['name' => 'upper-display-name', 'mail' => 'ud@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'upperguy@access-ci.org');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'upperguy@ACCESS-CI.ORG');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultAllowed::class, $result);
    $this->assertSame((int) $user->id(), $request->attributes->get('acting_user_uid'));
  }

  /**
   * Opaque CILogon subs (http://cilogon.org/serverA/users/NNN) exist in the
   * authmap alongside ACCESS-ID subs. They must never be matched by an
   * ACCESS-ID header.
   */
  public function testOpaqueCilogonSubIsNotMatchedByAccessId(): void {
    $service = $this->makeServiceCaller('mcp-svc-opaque');

    $target = User::create(['name' => 'Eric.Brown.Test', 'mail' => 'eb@example.com', 'status' => 1]);
    $target->save();
    $this->writeAuthmap($target, 'http://cilogon.org/serverA/users/31508341');

    $request = Request::create('/api/1.0/rp-account/1');
    $request->headers->set('X-Acting-User', 'Eric.Brown.Test');

    $result = $this->makeAccess()->check($service, $request);

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  public function testResolveAllowsAnonymousWithNoHeader(): void {
    $gate = $this->makeAccess();
    $anon = new \Drupal\Core\Session\AnonymousUserSession();
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/2.3/events/1');
    $result = $gate->resolve($anon, $request);
    $this->assertTrue($result->isAllowed());
    $this->assertLessThan(1, (int) $request->attributes->get('acting_user_uid', 0));
  }

  public function testResolveForbidsHeaderFromNonServiceCaller(): void {
    // Confused-deputy: an anonymous/non-service caller sending X-Acting-User must be FORBIDDEN.
    $gate = $this->makeAccess();
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
    $this->writeAuthmap($target, 'resolve-target@access-ci.org');
    $gate = $this->makeAccess();
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/2.3/events/1');
    $request->headers->set('X-Acting-User', 'resolve-target@access-ci.org');
    $result = $gate->resolve($service, $request);
    $this->assertTrue($result->isAllowed());
    $this->assertSame((int) $target->id(), (int) $request->attributes->get('acting_user_uid'));
  }
}
