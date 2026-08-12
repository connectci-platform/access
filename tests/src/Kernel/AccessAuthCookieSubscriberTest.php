<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\EventSubscriber\AccessAuthCookieSubscriber;
use Drupal\Core\Session\AccountProxy;
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
  // access_affinitygroup.allocations_client. Without it the container fails
  // to compile.
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
  protected function runSubscriber(User $user, ?LoggerInterface $logger = NULL): ?\Symfony\Component\HttpFoundation\Cookie {
    $current_user = new AccountProxy(\Drupal::service('event_dispatcher'));
    $current_user->setAccount(new UserSession([
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
      Request::create('/'),
      HttpKernelInterface::MAIN_REQUEST,
      new Response()
    );
    $subscriber->onResponse($event);

    foreach ($event->getResponse()->headers->getCookies() as $cookie) {
      if ($cookie->getName() === AccessAuthCookieSubscriber::COOKIE_NAME) {
        return $cookie;
      }
    }
    return NULL;
  }

  /**
   * Decodes the cookie's JWT and returns the `sub` claim.
   */
  protected function subClaim(\Symfony\Component\HttpFoundation\Cookie $cookie): string {
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

