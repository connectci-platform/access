<?php

namespace Drupal\Tests\access\Kernel;

use Symfony\Component\HttpFoundation\Cookie;
use Drupal\access\EventSubscriber\AccessAuthCookieSubscriber;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access\Fixtures\TestJwtKeyProvider;
use Drupal\Tests\access\Fixtures\TestLogger;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Covers the ACCESS ID (JWT `sub`) derivation for the identity cookie.
 *
 * The username is NOT reliably the ACCESS ID: CILogon-provisioned accounts get
 * display-style names, so the authmap has to be consulted.
 *
 * @group access
 */
class AccessAuthCookieSubscriberTest extends KernelTestBase {

  // access_affinitygroup is required, not incidental: access.services.yml
  // declares access.eligibility_check_subscriber, which depends on
  // access_affinitygroup.allocations_client. Without it the container fails.
  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  /**
   * Generated ES256 keypair, shared across the test.
   *
   * @var array
   */
  protected array $keypair;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    $this->createAuthmapTable();
    $this->keypair = $this->generateEs256Keypair();
  }

  /**
   * Creates the openid_connect_authmap table (see the contrib hook_schema()).
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
   * Generates an ES256 (P-256) keypair for signing/verifying in-test.
   */
  protected function generateEs256Keypair(): array {
    $res = openssl_pkey_new([
      'private_key_type' => OPENSSL_KEYTYPE_EC,
      'curve_name' => 'prime256v1',
    ]);
    $this->assertNotFalse($res, 'Generated an EC keypair.');
    openssl_pkey_export($res, $private_pem);
    $details = openssl_pkey_get_details($res);
    return ['private' => $private_pem, 'public' => $details['key']];
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

  /**
   * Runs the subscriber for $user and returns the SESSaccess_auth cookie.
   */
  protected function runSubscriber(User $user, ?LoggerInterface $logger = NULL, ?Request $request = NULL, ?Response $response = NULL, bool $anonymous = FALSE): ?Cookie {
    $current_user = new AccountProxy(\Drupal::service('event_dispatcher'));
    $current_user->setAccount($anonymous
      ? new AnonymousUserSession()
      : new UserSession([
        'uid' => (int) $user->id(),
        'name' => $user->getAccountName(),
        'roles' => ['authenticated'],
      ]));

    $subscriber = new AccessAuthCookieSubscriber(
      new TestJwtKeyProvider($this->keypair['private']),
      $current_user,
      \Drupal::entityTypeManager(),
      \Drupal::state(),
      $logger ?? new NullLogger(),
      \Drupal::database()
    );

    $event = new ResponseEvent(
      \Drupal::service('http_kernel'),
      $request ?? Request::create('/'),
      HttpKernelInterface::MAIN_REQUEST,
      $response ?? new Response()
    );
    $subscriber->onResponse($event);
    $this->lastResponse = $event->getResponse();

    $found = $this->authCookies($event->getResponse());
    return $found ? reset($found) : NULL;
  }

  /**
   * Every Set-Cookie for the identity cookie on a response.
   *
   * Plural deliberately: an implementation that clears on one domain and mints
   * on another emits two, and taking only the first would call that a pass.
   *
   * @return array<int, \Symfony\Component\HttpFoundation\Cookie>
   *   Every matching cookie on the response.
   */
  protected function authCookies(Response $response): array {
    $found = [];
    foreach ($response->headers->getCookies() as $cookie) {
      if ($cookie->getName() === AccessAuthCookieSubscriber::COOKIE_NAME) {
        $found[] = $cookie;
      }
    }
    return $found;
  }

  /**
   * The response from the last runSubscriber() call.
   *
   * @var \Symfony\Component\HttpFoundation\Response|null
   */
  protected ?Response $lastResponse = NULL;

  /**
   * Decodes the cookie's JWT and returns the `sub` claim.
   */
  protected function subClaim(Cookie $cookie): string {
    $decoded = JWT::decode($cookie->getValue(), new Key($this->keypair['public'], 'ES256'));
    return $decoded->sub;
  }

  /**
   * CILogon-provisioned user: display-style username, authmap holds the id.
   */
  public function testAuthmapUserGetsCookieWithAuthmapSub(): void {
    $user = User::create(['name' => 'andrew-pasquale-4', 'mail' => 'ap@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale1@access-ci.org');

    $logger = new TestLogger();
    $cookie = $this->runSubscriber($user, $logger);

    $this->assertNotNull($cookie, 'A display-named CILogon user must still get the identity cookie.');
    $this->assertSame('apasquale1@access-ci.org', $this->subClaim($cookie));
    // The happy path must stay silent — this warning fires per response.
    $this->assertSame([], $logger->getWarnings());
  }

  /**
   * Logout clears the cookie, on the response shape production emits.
   *
   * Observed on production: the logout response carried no Set-Cookie for this
   * cookie at all, leaving a live 18-hour token in the browser. The guard
   * forces the clear. Parameterised over a redirect because every real logout
   * redirects (UserController::logout, UserLogoutConfirm::submitForm, and the
   * seamless module's TrustedRedirectResponse) — a plain 200 is a shape this
   * code never sees in production.
   *
   * @dataProvider logoutCases
   */
  public function testLogoutClearsTheCookie(string $route, string $path, bool $redirect): void {
    $key = substr(md5($route . $path . (int) $redirect), 0, 8);
    $user = User::create([
      'name' => 'ap-logout-' . $key,
      'mail' => 'apl' . $key . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-logout@access-ci.org');

    $request = Request::create($path);
    $request->attributes->set('_route', $route);
    $request->cookies->set(AccessAuthCookieSubscriber::COOKIE_NAME, 'a-still-valid-token');

    $cookie = $this->runSubscriber(
      $user,
      NULL,
      $request,
      $redirect ? new RedirectResponse('/') : new Response(),
    );

    $this->assertNotNull($cookie, 'The logout response must carry a Set-Cookie for the identity cookie.');
    // Exactly one: clearing on one domain while minting on another would
    // otherwise read as a pass.
    $this->assertCount(1, $this->authCookies($this->lastResponse));
    $this->assertEmpty($cookie->getValue(), 'The cookie is cleared, not re-minted.');
    $this->assertLessThan(
      \Drupal::time()->getRequestTime(),
      $cookie->getExpiresTime(),
      'The cleared cookie expires in the past so the browser deletes it.',
    );
    // A browser only deletes on matching name, domain and path; the rest must
    // match too or the delete describes a different cookie.
    $this->assertSame($this->expectedAttributes(), $this->attributesOf($cookie));
  }

  /**
   * Logout clears even when the request did not carry the cookie.
   *
   * A cookie the browser holds but did not send — a scoping or timing edge —
   * is exactly the one that must not survive logout. The seamless module
   * expires its own cookie unconditionally for the same reason.
   */
  public function testLogoutClearsEvenWithoutTheCookieOnTheRequest(): void {
    $user = User::create(['name' => 'ap-logout-nocookie', 'mail' => 'apln@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-nocookie@access-ci.org');

    $request = Request::create('/user/logout');
    $request->attributes->set('_route', 'user.logout');

    $cookie = $this->runSubscriber($user, NULL, $request, new RedirectResponse('/'));

    $this->assertNotNull($cookie, 'The clear is unconditional on the logout path.');
    $this->assertEmpty($cookie->getValue());
  }

  /**
   * The logout routes, with the real path each one is served at.
   *
   * The path matters: an implementation matching on path rather than route
   * name passes a route-only provider. user.logout.confirm is deliberately
   * absent — see LOGOUT_ROUTES and testConfirmFormGetDoesNotClear().
   *
   * @return array<string, array<int, string|bool>>
   *   Route name, request path, and whether the response is a redirect.
   */
  public static function logoutCases(): array {
    return [
      'ui link, redirect' => ['user.logout', '/user/logout', TRUE],
      'ui link, plain response' => ['user.logout', '/user/logout', FALSE],
      'rest logout, redirect' => ['user.logout.http', '/user/logout', TRUE],
      'rest logout, plain response' => ['user.logout.http', '/user/logout', FALSE],
    ];
  }

  /**
   * A force-logout attribute clears the cookie on a non-logout route.
   *
   * The seamless module logs a user out when its SSO cookie goes missing, on
   * whatever page they requested, so no route list reaches it. It sets the
   * attribute instead and this subscriber does the clearing, which keeps the
   * cookie's rules in one module (D8-2896).
   */
  public function testForceLogoutAttributeClearsOnAnyRoute(): void {
    $user = User::create(['name' => 'ap-forced', 'mail' => 'apf@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-forced@access-ci.org');

    $request = Request::create('/some/page');
    $request->attributes->set('_route', 'entity.node.canonical');
    // The literal, not the constant: this is a contract with another module
    // (drupal_seamless_cilogon writes this exact string). Asserting the
    // constant would move with a rename and let the two sides diverge
    // silently, which is the failure this attribute was chosen to avoid.
    $request->attributes->set('access_force_logout', TRUE);
    // Anonymous, as production is here: seamless calls user_logout() before
    // setting its response, and that resets the same account proxy this
    // subscriber reads. The attribute's contribution is making the clear
    // unconditional -- without it the else-branch clear still fires, but only
    // when the browser actually sent the cookie.
    $cookie = $this->runSubscriber($user, NULL, $request, NULL, TRUE);

    $this->assertNotNull($cookie, 'The attribute forces a clear.');
    $this->assertEmpty($cookie->getValue(), 'Cleared, not re-minted.');
    $this->assertLessThan(\Drupal::time()->getRequestTime(), $cookie->getExpiresTime());
  }

  /**
   * The constant and the string other modules write are the same thing.
   *
   * The drupal_seamless_cilogon module writes the bare literal. Nothing else
   * in either repo pins that the two halves of that contract match.
   */
  public function testForceLogoutAttributeNameIsStable(): void {
    $this->assertSame(
      'access_force_logout',
      AccessAuthCookieSubscriber::FORCE_LOGOUT_ATTRIBUTE,
      'Renaming this breaks drupal_seamless_cilogon, which writes the literal.',
    );
  }

  /**
   * The forced clear is unconditional, as on the logout routes.
   *
   * A cookie the browser holds but did not send is the one that must not
   * survive a logout.
   */
  public function testForceLogoutClearsEvenWithoutTheCookieOnTheRequest(): void {
    $user = User::create(['name' => 'ap-forced-none', 'mail' => 'apfn@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-forcednone@access-ci.org');

    $request = Request::create('/some/page');
    $request->attributes->set('_route', 'entity.node.canonical');
    $request->attributes->set('access_force_logout', TRUE);

    // No cookie on the request, and anonymous: without the attribute the
    // else-branch clear early-returns and nothing is sent. This is the case
    // the attribute exists for.
    $this->assertNotNull($this->runSubscriber($user, NULL, $request, NULL, TRUE));
  }

  /**
   * Without the attribute, the same route still mints.
   *
   * Pins that the attribute is what does the work, not the route.
   */
  public function testSameRouteWithoutTheAttributeStillMints(): void {
    $user = User::create(['name' => 'ap-unforced', 'mail' => 'apu@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-unforced@access-ci.org');

    $request = Request::create('/some/page');
    $request->attributes->set('_route', 'entity.node.canonical');

    $cookie = $this->runSubscriber($user, NULL, $request);

    $this->assertNotNull($cookie);
    $this->assertNotEmpty($cookie->getValue(), 'A normal page still mints.');
  }

  /**
   * The confirm form's GET must not clear: nobody is logged out yet.
   *
   * User_logout() runs only in UserLogoutConfirm::submitForm(), so the GET
   * that renders "are you sure?" belongs to a user who is still signed in and
   * may click Cancel. Clearing there would drop their identity assertion
   * everywhere while their session continues.
   */
  public function testConfirmFormGetDoesNotClear(): void {
    $user = User::create(['name' => 'ap-confirm', 'mail' => 'apc@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-confirm@access-ci.org');

    $request = Request::create('/user/logout/confirm');
    $request->attributes->set('_route', 'user.logout.confirm');
    $request->cookies->set(AccessAuthCookieSubscriber::COOKIE_NAME, 'a-still-valid-token');

    $cookie = $this->runSubscriber($user, NULL, $request);

    $this->assertNotNull($cookie);
    $this->assertNotEmpty($cookie->getValue(), 'A still-authenticated user keeps their cookie.');
    $this->assertSame('apasquale-confirm@access-ci.org', $this->subClaim($cookie));
  }

  /**
   * Authenticated non-logout requests still get the rolling re-mint.
   *
   * The adjacent routes are the point: a guard matching "user.*" or anything
   * containing "log" would break login and the OIDC callback, and a list that
   * quietly grew extra entries would too.
   *
   * @dataProvider nonLogoutRoutes
   */
  public function testAuthenticatedNonLogoutRequestStillGetsTheCookie(string $route): void {
    $key = substr(md5($route), 0, 8);
    $user = User::create([
      'name' => 'ap-keep-' . $key,
      'mail' => 'apk' . $key . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->writeAuthmap($user, 'apasquale-keep@access-ci.org');

    $request = Request::create('/some/page');
    $request->attributes->set('_route', $route);

    $cookie = $this->runSubscriber($user, NULL, $request);

    $this->assertNotNull($cookie, "Route $route must still mint.");
    $this->assertNotEmpty($cookie->getValue(), 'A normal authenticated response still mints the JWT.');
    $this->assertSame('apasquale-keep@access-ci.org', $this->subClaim($cookie));
  }

  /**
   * Routes adjacent to logout that must keep minting.
   *
   * @return array<string, array<int, string>>
   *   One route name per case.
   */
  public static function nonLogoutRoutes(): array {
    return [
      'node page' => ['entity.node.canonical'],
      'login' => ['user.login'],
      'user page' => ['user.page'],
      'own profile' => ['entity.user.canonical'],
      'oidc callback' => [
        'openid_connect.redirect_controller_redirect',
      ],
    ];
  }

  /**
   * An anonymous request carrying the cookie has it cleared.
   *
   * The else branch: this is what catches a session that ended without passing
   * through a logout route at all (expiry, a blocked account, the seamless
   * module's safety logout).
   */
  public function testAnonymousRequestWithCookieIsCleared(): void {
    $user = User::create(['name' => 'ap-anon', 'mail' => 'apa@example.com', 'status' => 1]);
    $user->save();

    $request = Request::create('/some/page');
    $request->attributes->set('_route', 'entity.node.canonical');
    $request->cookies->set(AccessAuthCookieSubscriber::COOKIE_NAME, 'a-stale-token');

    $cookie = $this->runSubscriber($user, NULL, $request, NULL, TRUE);

    $this->assertNotNull($cookie, 'A stale cookie on an anonymous request is cleared.');
    $this->assertEmpty($cookie->getValue());
  }

  /**
   * An anonymous request with no cookie sends no header, so pages stay cacheable.
   */
  public function testAnonymousRequestWithoutCookieSendsNothing(): void {
    $user = User::create(['name' => 'ap-anon-none', 'mail' => 'apan@example.com', 'status' => 1]);
    $user->save();

    $request = Request::create('/some/page');
    $request->attributes->set('_route', 'entity.node.canonical');

    $this->assertNull($this->runSubscriber($user, NULL, $request, NULL, TRUE));
  }

  /**
   * The attribute tuple setAuthCookie() mints with, which the clear must match.
   *
   * @return array<string, mixed>
   *   Path, domain, secure, httponly and samesite.
   */
  protected function expectedAttributes(): array {
    return [
      'path' => '/',
      'domain' => getenv('ACCESS_JWT_COOKIE_DOMAIN') ?: '.access-ci.org',
      'secure' => TRUE,
      'httponly' => TRUE,
      'samesite' => 'none',
    ];
  }

  /**
   * The same tuple, read back off a cookie.
   *
   * @return array<string, mixed>
   *   Path, domain, secure, httponly and samesite.
   */
  protected function attributesOf(Cookie $cookie): array {
    return [
      'path' => $cookie->getPath(),
      'domain' => $cookie->getDomain(),
      'secure' => $cookie->isSecure(),
      'httponly' => $cookie->isHttpOnly(),
      'samesite' => $cookie->getSameSite(),
    ];
  }

  /**
   * Even a full-form USERNAME is not an identity source: without an authmap
   * row there is no cookie.
   */
  public function testFullFormUsernameWithoutAuthmapGetsNoCookie(): void {
    $user = User::create(['name' => 'jsmith@access-ci.org', 'mail' => 'js@example.com', 'status' => 1]);
    $user->save();

    $this->assertNull($this->runSubscriber($user));
  }

  /**
   * A full-form username DOES get a cookie once the authmap row exists, and
   * the claim comes from the authmap.
   */
  public function testFullFormUsernameWithAuthmapGetsCookie(): void {
    $user = User::create(['name' => 'jsmith@access-ci.org', 'mail' => 'js2@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'jsmith@access-ci.org');

    $cookie = $this->runSubscriber($user);

    $this->assertNotNull($cookie);
    $this->assertSame('jsmith@access-ci.org', $this->subClaim($cookie));
  }

  /**
   * A bare username is not an ACCESS ID and yields no cookie.
   *
   * Under the ACCESS-ID-only ruling the authmap is the sole identity source.
   * That is complete rather than lossy: an authenticated session implies a
   * CILogon login, which writes the row (and connect_existing_users links a
   * legacy account to it).
   */
  public function testBareUsernameGetsNoCookie(): void {
    $user = User::create(['name' => 'apasquale', 'mail' => 'ap2@example.com', 'status' => 1]);
    $user->save();

    $logger = new TestLogger();
    $this->assertNull($this->runSubscriber($user, $logger));

    // The account holds a session but has no ACCESS ID, so it must fail
    // loudly rather than silently lose the cookie.
    $warnings = $logger->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString((string) $user->id(), $warnings[0]);
  }

  /**
   * A generated display-style username is likewise not an ACCESS ID.
   */
  public function testGeneratedDisplayNameGetsNoCookie(): void {
    $user = User::create(['name' => 'Eric.Brown', 'mail' => 'eb2@example.com', 'status' => 1]);
    $user->save();

    $this->assertNull($this->runSubscriber($user));
  }

  /**
   * The authmap is the identity source, so its sub is the claim even when the
   * username looks like a different (full-form) id.
   */
  public function testAuthmapWinsOverUsername(): void {
    $user = User::create(['name' => 'wrongname@access-ci.org', 'mail' => 'wn@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'realid@access-ci.org');

    $cookie = $this->runSubscriber($user);

    $this->assertNotNull($cookie);
    $this->assertSame('realid@access-ci.org', $this->subClaim($cookie));
  }

  /**
   * An opaque CILogon sub is not an ACCESS ID, and a bare username is not
   * either — so this user gets no cookie.
   */
  public function testOpaqueAuthmapSubWithBareUsernameGetsNoCookie(): void {
    $user = User::create(['name' => 'ebrown', 'mail' => 'eb@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'http://cilogon.org/serverA/users/31508341');

    $this->assertNull($this->runSubscriber($user));
  }

  /**
   * An opaque sub yields no cookie even when the username is full-form: the
   * username is never an identity source.
   */
  public function testOpaqueAuthmapSubWithFullFormUsernameGetsNoCookie(): void {
    $user = User::create(['name' => 'ebrown@access-ci.org', 'mail' => 'eb3@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'http://cilogon.org/serverA/users/31508341');

    $this->assertNull($this->runSubscriber($user));
  }

  /**
   * An ACCESS-ID sub is preferred when a user holds both it and an opaque one.
   */
  public function testAccessIdSubPreferredOverOpaqueSub(): void {
    $user = User::create(['name' => 'multi-sub-user', 'mail' => 'ms@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'http://cilogon.org/serverA/users/999', 'cilogon');
    $this->writeAuthmap($user, 'multisub@access-ci.org', 'cilogon');

    $cookie = $this->runSubscriber($user);

    $this->assertNotNull($cookie);
    $this->assertSame('multisub@access-ci.org', $this->subClaim($cookie));
  }

  /**
   * Defensive: nothing yields an ACCESS ID, so no cookie.
   */
  public function testUserWithNoDerivableIdGetsNoCookie(): void {
    $user = User::create(['name' => 'has spaces@bad', 'mail' => 'bad@example.com', 'status' => 1]);
    $user->save();

    $this->assertNull($this->runSubscriber($user));
  }

}
