<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\TokenExchange\ClientRegistry;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access\Fixtures\TestJwtKeyProvider;
use Drupal\Tests\access\Fixtures\TestLogger;

/**
 * Covers token-client registry validation, which fails closed.
 *
 * @group access
 */
class ClientRegistryTest extends KernelTestBase {

  use TokenKeysTestTrait;

  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  protected TestLogger $logger;

  /**
   * A valid registry: one browser client, one confidential client.
   */
  public static function validClients(): array {
    return [
      'access-qa-bot' => [
        'type' => 'browser',
        'audience' => 'https://qa.access-ci.org',
        'origins' => [
          ['origin' => 'https://support.access-ci.org', 'scopes' => ['agent:read', 'agent:write']],
          ['origin' => 'https://allocations.access-ci.org', 'scopes' => ['agent:read']],
        ],
      ],
      'access-assistant' => [
        'type' => 'confidential',
        'audience' => 'https://xdmod.access-ci.org',
        'scopes' => ['xdmod:read'],
        'subject_clients' => ['access-qa-bot'],
      ],
    ];
  }

  protected function registry(array $clients, array $secrets = []): ClientRegistry {
    // A settings.php override, which is how non-live environments register
    // their origins. Overrides bypass config schema, so malformed shapes reach
    // the registry exactly as they would from settings.
    $GLOBALS['config']['access.token_exchange']['clients'] = $clients;
    \Drupal::configFactory()->reset();
    $this->logger = new TestLogger();
    $keypair = $this->generateEs256Keypair();
    return new ClientRegistry(
      \Drupal::configFactory(),
      new TestJwtKeyProvider($keypair['private'], [], 'https://test.access-ci.org', $secrets),
      $this->logger
    );
  }

  public function testValidRegistryLoads(): void {
    $registry = $this->registry(self::validClients());

    $browser = $registry->findBrowserOrigin('https://allocations.access-ci.org');
    $this->assertSame('access-qa-bot', $browser['client_id']);
    $this->assertSame('https://qa.access-ci.org', $browser['audience']);
    $this->assertSame(['agent:read'], $browser['scopes']);

    $assistant = $registry->getClient('access-assistant');
    $this->assertSame('confidential', $assistant['type']);
    $this->assertSame(['xdmod:read'], $assistant['scopes'], 'A confidential client keeps its non-agent scopes.');
    $this->assertSame([], $this->logger->getRecords());
  }

  public function testUnknownOriginAndEmptyRegistry(): void {
    $this->assertNull($this->registry(self::validClients())->findBrowserOrigin('https://evil.example.org'));
    $this->assertNull($this->registry([])->findBrowserOrigin('https://support.access-ci.org'));
  }

  public function testOriginMatchIsExact(): void {
    $registry = $this->registry(self::validClients());
    foreach (['https://support.access-ci.org/', 'https://SUPPORT.access-ci.org', 'http://support.access-ci.org', 'null', ''] as $origin) {
      $this->assertNull($registry->findBrowserOrigin($origin), $origin);
    }
  }

  /**
   * Each rule makes the offending client unusable.
   *
   * @dataProvider invalidClientCases
   */
  public function testInvalidClientFailsClosed(string $id, array $client, ?string $bad = NULL): void {
    $clients = self::validClients();
    $clients[$id] = $client;
    $registry = $this->registry($clients);
    $this->assertNull($registry->getClient($bad ?? $id));
    $this->assertNotEmpty($this->logger->getRecords(), 'The rejection is logged.');
  }

  public static function invalidClientCases(): array {
    $browser = fn(array $over) => $over + [
      'type' => 'browser',
      'audience' => 'https://qa-test.access-ci.org',
      'origins' => [['origin' => 'https://ara.access-ci.org', 'scopes' => ['agent:read']]],
    ];
    $confidential = fn(array $over) => $over + [
      'type' => 'confidential',
      'audience' => 'https://other.access-ci.org',
      'scopes' => ['xdmod:read'],
      'subject_clients' => ['access-qa-bot'],
    ];
    return [
      'bad id' => ['Bad_Id', $browser([])],
      'missing type' => ['no-type', array_diff_key($browser([]), ['type' => 1])],
      'unknown type' => ['odd-type', $browser(['type' => 'public'])],
      'two audiences' => ['two-aud', $browser(['audience' => ['https://a.access-ci.org', 'https://b.access-ci.org']])],
      'non-https audience' => ['http-aud', $browser(['audience' => 'http://qa.access-ci.org'])],
      'localhost lookalike audience' => ['lh-aud', $browser(['audience' => 'http://localhost.evil.org:8000'])],
      'origin with path' => ['path', $browser(['origins' => [['origin' => 'https://ara.access-ci.org/x', 'scopes' => ['agent:read']]]])],
      'origin with port' => ['port', $browser(['origins' => [['origin' => 'https://ara.access-ci.org:8443', 'scopes' => ['agent:read']]]])],
      'wildcard origin' => ['wild', $browser(['origins' => [['origin' => 'https://*.access-ci.org', 'scopes' => ['agent:read']]]])],
      'null origin' => ['nullo', $browser(['origins' => [['origin' => 'null', 'scopes' => ['agent:read']]]])],
      'no origins' => ['noorig', $browser(['origins' => []])],
      'unknown scope' => ['unk', $browser(['origins' => [['origin' => 'https://ara.access-ci.org', 'scopes' => ['agent:admin']]]])],
      'browser with xdmod:read' => ['bx', $browser(['origins' => [['origin' => 'https://ara.access-ci.org', 'scopes' => ['agent:read', 'xdmod:read']]]])],
      'confidential with agent:read' => ['ca', $confidential(['scopes' => ['xdmod:read', 'agent:read']])],
      'confidential with qa audience' => ['cq', $confidential(['audience' => 'https://qa.access-ci.org'])],
      'confidential naming an unknown subject client' => ['cu', $confidential(['subject_clients' => ['nobody']])],
      'confidential naming a confidential subject client' => ['cc', $confidential(['subject_clients' => ['access-assistant']])],
    ];
  }

  /**
   * An origin registered under two clients disables both.
   */
  public function testDuplicateOriginDisablesBothClients(): void {
    $clients = self::validClients();
    $clients['second-bot'] = [
      'type' => 'browser',
      'audience' => 'https://qa2.access-ci.org',
      'origins' => [['origin' => 'https://support.access-ci.org', 'scopes' => ['agent:read']]],
    ];
    $registry = $this->registry($clients);
    $this->assertNull($registry->getClient('second-bot'));
    $this->assertNull($registry->getClient('access-qa-bot'));
    $this->assertNull($registry->findBrowserOrigin('https://support.access-ci.org'));
    // access-assistant named access-qa-bot, which is now gone.
    $this->assertNull($registry->getClient('access-assistant'));
  }

  public function testSecretVerification(): void {
    $registry = $this->registry(self::validClients(), [
      'access_token_exchange_secret_sha256_access_assistant' => hash('sha256', 's3cret'),
    ]);
    $this->assertTrue($registry->verifySecret('access-assistant', 's3cret'));
    $this->assertFalse($registry->verifySecret('access-assistant', 'wrong'));
    $this->assertFalse($registry->verifySecret('access-assistant', ''));
    // A browser client never authenticates with a secret, even if one is set.
    $this->assertFalse($registry->verifySecret('access-qa-bot', 's3cret'));
    $this->assertFalse($registry->verifySecret('unknown', 's3cret'));
  }

  public function testMissingOrMalformedSecretHashFails(): void {
    $this->assertFalse($this->registry(self::validClients())->verifySecret('access-assistant', 's3cret'));
    $registry = $this->registry(self::validClients(), [
      'access_token_exchange_secret_sha256_access_assistant' => 's3cret',
    ]);
    $this->assertFalse($registry->verifySecret('access-assistant', 's3cret'), 'A plaintext secret in place of the hash is refused.');
  }

  /**
   * A localhost audience is allowed; a localhost origin never is.
   *
   * A page on localhost minting from the site is a cross-site request, which
   * the endpoint refuses and browsers send without the session cookie.
   */
  public function testLocalhostAudienceButNotOrigin(): void {
    $clients = [
      'access-qa-bot' => [
        'type' => 'browser',
        'audience' => 'http://localhost:8000',
        'origins' => [
          ['origin' => 'https://test-accessmatch.pantheonsite.io', 'scopes' => ['agent:read', 'agent:write']],
        ],
      ],
    ];
    $this->assertSame('http://localhost:8000', $this->registry($clients)->getClient('access-qa-bot')['audience']);

    $clients['access-qa-bot']['origins'][] = ['origin' => 'http://localhost:3000', 'scopes' => ['agent:read']];
    $this->assertNull($this->registry($clients)->getClient('access-qa-bot'));
  }

  /**
   * A settings.php registry replaces the stored one; it does not merge.
   *
   * A live database cloned to dev carries live's stored registry. Config
   * overrides merge into it entry by entry, which would leave live's other
   * origins registered on dev; the access_token_clients setting is used
   * instead of the stored config, whole.
   */
  public function testSettingsRegistryReplacesStoredConfig(): void {
    unset($GLOBALS['config']['access.token_exchange']);
    \Drupal::configFactory()->getEditable('access.token_exchange')->set('clients', self::validClients())->save();
    $this->setSetting('access_token_clients', [
      'access-qa-bot' => [
        'type' => 'browser',
        'audience' => 'http://localhost:8000',
        'origins' => [['origin' => 'https://dev-accessmatch.pantheonsite.io', 'scopes' => ['agent:read']]],
      ],
    ]);
    $registry = new ClientRegistry(\Drupal::configFactory(), new TestJwtKeyProvider($this->generateEs256Keypair()['private']), new TestLogger());

    $this->assertNotNull($registry->findBrowserOrigin('https://dev-accessmatch.pantheonsite.io'));
    $this->assertNull($registry->findBrowserOrigin('https://support.access-ci.org'), 'The stored origins are gone.');
    $this->assertNull($registry->findBrowserOrigin('https://allocations.access-ci.org'));
    $this->assertNull($registry->getClient('access-assistant'), 'A client only in stored config does not survive.');
  }

}
