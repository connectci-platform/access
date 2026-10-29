<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\TokenExchange\ClientRegistry;
use Drupal\access\TokenExchange\TokenIssuer;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access\Fixtures\TestJwtKeyProvider;
use Drupal\Tests\access\Fixtures\TestLogger;
use Drupal\user\Entity\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared setup for the agent-token and token-exchange endpoint tests.
 */
abstract class TokenEndpointTestBase extends KernelTestBase {

  use TokenKeysTestTrait;

  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  const HOST = 'test.access-ci.org';
  const ISSUER = 'https://test.access-ci.org';
  const QA = 'https://qa.access-ci.org';
  const XDMOD = 'https://xdmod.access-ci.org';
  const SECRET = 'correct horse battery staple';

  /**
   * Keypairs keyed by purpose.
   *
   * @var array
   */
  protected array $keys;

  protected TestJwtKeyProvider $keyProvider;

  protected TestLogger $logger;

  protected ClientRegistry $registry;

  protected TokenIssuer $issuer;

  protected AccountProxy $currentUser;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    $this->createAuthmapTable();
    foreach (['cookie', 'agent', 'xdmod'] as $purpose) {
      $this->keys[$purpose] = $this->generateEs256Keypair();
    }
    $this->configure();
  }

  /**
   * (Re)builds the services under test.
   */
  protected function configure(array $slots = [], ?string $issuer = self::ISSUER, ?array $clients = NULL): void {
    $slots += ['agent' => $this->keys['agent']['private'], 'xdmod' => $this->keys['xdmod']['private']];
    $this->keyProvider = new TestJwtKeyProvider($this->keys['cookie']['private'], array_filter($slots, 'is_string'), $issuer, [
      'access_token_exchange_secret_sha256_access-assistant' => hash('sha256', self::SECRET),
    ]);
    \Drupal::configFactory()->getEditable('access.token_exchange')
      ->set('clients', $clients ?? self::clients())
      ->save();
    $this->logger = new TestLogger();
    $this->registry = new ClientRegistry(\Drupal::configFactory(), $this->keyProvider, $this->logger);
    $this->issuer = new TokenIssuer($this->keyProvider, \Drupal::time(), $this->logger);
    $this->currentUser = new AccountProxy(\Drupal::service('event_dispatcher'));
    $this->currentUser->setAccount(new AnonymousUserSession());
  }

  /**
   * The production-shaped registry.
   */
  public static function clients(): array {
    $origins = [];
    foreach (['support', 'qa', 'allocations'] as $host) {
      $origins[] = ['origin' => "https://$host.access-ci.org", 'scopes' => ['agent:read', 'agent:write']];
    }
    $origins[] = ['origin' => 'https://ara.access-ci.org', 'scopes' => ['agent:read']];
    return [
      'access-qa-bot' => [
        'type' => 'browser',
        'audience' => self::QA,
        'origins' => $origins,
      ],
      'access-assistant' => [
        'type' => 'confidential',
        'audience' => self::XDMOD,
        'scopes' => ['xdmod:read'],
        'subject_clients' => ['access-qa-bot'],
      ],
    ];
  }

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
   * Creates a user with an ACCESS ID authmap row (or none).
   */
  protected function createAccessUser(string $name, ?string $sub, int $status = 1): User {
    $user = User::create(['name' => $name, 'mail' => "$name@example.com", 'status' => $status]);
    $user->save();
    if ($sub !== NULL) {
      \Drupal::database()->insert('openid_connect_authmap')
        ->fields(['uid' => (int) $user->id(), 'client_name' => 'cilogon', 'sub' => $sub])
        ->execute();
    }
    return $user;
  }

  protected function signIn(User $user): void {
    $this->currentUser->setAccount(new UserSession([
      'uid' => (int) $user->id(),
      'name' => $user->getAccountName(),
      'roles' => ['authenticated'],
    ]));
  }

  /**
   * Decodes a JSON response body.
   */
  protected function body(Response $response): array {
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Splits a JWT into its decoded header and claims, without verifying.
   */
  protected function parts(string $jwt): array {
    [$header, $claims] = explode('.', $jwt);
    return [
      json_decode(JWT::urlsafeB64Decode($header), TRUE),
      json_decode(JWT::urlsafeB64Decode($claims), TRUE),
    ];
  }

  /**
   * Verifies a JWT with one purpose's public key.
   */
  protected function verifyWith(string $purpose, string $jwt): \stdClass {
    return JWT::decode($jwt, new Key($this->keys[$purpose]['public'], 'ES256'));
  }

  /**
   * Asserts no log line contains a given secret value.
   */
  protected function assertNotLogged(string $value): void {
    foreach ($this->logger->getRecords() as $record) {
      $this->assertStringNotContainsString($value, $record['message']);
    }
  }

}
