<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\AccessJwtKeyProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access\Fixtures\TestJwtKeyProvider;

/**
 * Covers the per-purpose key slots and the signing guards.
 *
 * @group access
 */
class AccessJwtKeyProviderTest extends KernelTestBase {

  use TokenKeysTestTrait;

  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  /**
   * Keypairs keyed by purpose.
   *
   * @var array
   */
  protected array $keys;

  protected function setUp(): void {
    parent::setUp();
    foreach (['cookie', 'agent', 'xdmod'] as $purpose) {
      $this->keys[$purpose] = $this->generateEs256Keypair();
    }
  }

  /**
   * A provider holding all three keys, unless overridden.
   */
  protected function provider(array $slots = [], ?string $issuer = 'https://test.access-ci.org', array $secrets = []): TestJwtKeyProvider {
    $slots += [
      'agent' => $this->keys['agent']['private'],
      'xdmod' => $this->keys['xdmod']['private'],
    ];
    return new TestJwtKeyProvider($this->keys['cookie']['private'], array_filter($slots, 'is_string'), $issuer, $secrets);
  }

  public function testEachSlotReportsItsStatus(): void {
    $provider = $this->provider(['agent' => 'not a pem', 'xdmod' => FALSE]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_LOADED, $provider->getKeyStatus('cookie'));
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $provider->getKeyStatus('agent'));
    $this->assertSame(AccessJwtKeyProvider::STATUS_NONE, $provider->getKeyStatus('xdmod'));
  }

  public function testNonP256KeyIsBroken(): void {
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
    openssl_pkey_export($res, $p384);
    $provider = $this->provider(['agent' => $p384]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $provider->getKeyStatus('agent'));
  }

  public function testSlotsHaveTheirOwnKids(): void {
    $provider = $this->provider();
    $this->assertSame($this->kidFor($this->keys['agent']['private']), $provider->getSlotKeyId('agent'));
    $this->assertSame($this->kidFor($this->keys['xdmod']['private']), $provider->getSlotKeyId('xdmod'));
    $this->assertNotSame($provider->getSlotKeyId('agent'), $provider->getSlotKeyId('xdmod'));
  }

  /**
   * Any two slots sharing a key make the non-cookie ones unusable.
   *
   * @dataProvider sameKeyCases
   */
  public function testSameKeyGuard(string $agentFrom, string $xdmodFrom, array $broken): void {
    $provider = $this->provider([
      'agent' => $this->keys[$agentFrom]['private'],
      'xdmod' => $this->keys[$xdmodFrom]['private'],
    ]);
    foreach (['cookie', 'agent', 'xdmod'] as $purpose) {
      $expected = in_array($purpose, $broken, TRUE) ? AccessJwtKeyProvider::STATUS_BROKEN : AccessJwtKeyProvider::STATUS_LOADED;
      $this->assertSame($expected, $provider->getKeyStatus($purpose), $purpose);
    }
  }

  public static function sameKeyCases(): array {
    return [
      'agent reuses cookie' => ['cookie', 'xdmod', ['agent']],
      'xdmod reuses cookie' => ['agent', 'cookie', ['xdmod']],
      'agent and xdmod share' => ['xdmod', 'xdmod', ['agent', 'xdmod']],
    ];
  }

  public function testSigningKeyCarriesKidAndIssuer(): void {
    $signer = $this->provider()->getSigningKey('agent', 'test.access-ci.org');
    $this->assertNotNull($signer);
    $this->assertSame($this->kidFor($this->keys['agent']['private']), $signer['kid']);
    $this->assertSame('https://test.access-ci.org', $signer['iss']);
  }

  public function testNoExplicitIssuerMeansNoSigning(): void {
    $provider = $this->provider([], NULL);
    $this->assertNull($provider->getSigningKey('agent', 'test.access-ci.org'));
    $this->assertNull($provider->getSigningKey('xdmod', 'test.access-ci.org'));
    // The cookie path is untouched by the guard.
    $this->assertNotNull($provider->getPrivateKey());
  }

  /**
   * A multidev holding dev's cloned secrets must not sign as dev.
   */
  public function testHostMismatchMeansNoSigning(): void {
    $provider = $this->provider([], 'https://dev-accessmatch.pantheonsite.io');
    $this->assertNull($provider->getSigningKey('agent', 'md-x-accessmatch.pantheonsite.io'));
    $this->assertNull($provider->getSigningKey('xdmod', 'md-x-accessmatch.pantheonsite.io'));
    $this->assertNotNull($provider->getSigningKey('agent', 'dev-accessmatch.pantheonsite.io'));
    $this->assertNotNull($provider->getSigningKey('agent', 'DEV-accessmatch.pantheonsite.io'), 'Host comparison is case-insensitive.');
  }

  public function testCookieIsNeverASigningSlot(): void {
    $this->assertNull($this->provider()->getSigningKey('cookie', 'test.access-ci.org'));
  }

  public function testBrokenSlotDoesNotSign(): void {
    $this->assertNull($this->provider(['agent' => 'garbage'])->getSigningKey('agent', 'test.access-ci.org'));
  }

  public function testVerifyOnlyKeys(): void {
    $next = $this->generateEs256Keypair();
    $provider = $this->provider([], 'https://test.access-ci.org', [
      'access_jwt_exchange_jwks_extra_public_keys' => [$next['public']],
    ]);
    $result = $provider->getVerifyOnlyKeys('exchange');
    $this->assertSame(AccessJwtKeyProvider::STATUS_LOADED, $result['status']);
    $this->assertCount(1, $result['keys']);
    $this->assertSame(AccessJwtKeyProvider::STATUS_NONE, $provider->getVerifyOnlyKeys('cookie')['status']);

    $bad = $this->provider([], 'https://test.access-ci.org', [
      'access_jwt_exchange_jwks_extra_public_keys' => [$next['public'], 'not a key'],
    ]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $bad->getVerifyOnlyKeys('exchange')['status']);

    // A key from the other document never appears in this one's list.
    $cookieInExchange = $this->provider([], 'https://test.access-ci.org', [
      'access_jwt_exchange_jwks_extra_public_keys' => [$this->keys['cookie']['public']],
    ]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $cookieInExchange->getVerifyOnlyKeys('exchange')['status']);
    $agentInCookie = $this->provider([], 'https://test.access-ci.org', [
      'access_jwt_cookie_jwks_extra_public_keys' => [$this->keys['agent']['public']],
    ]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $agentInCookie->getVerifyOnlyKeys('cookie')['status']);

    $notList = $this->provider([], 'https://test.access-ci.org', [
      'access_jwt_exchange_jwks_extra_public_keys' => $next['public'],
    ]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $notList->getVerifyOnlyKeys('exchange')['status']);
  }

  /**
   * Pantheon Secrets is authoritative for the agent and xdmod slots.
   *
   * Every environment, multidevs included, gets the all-environments value
   * "none"; live gets its real key as an override. A Pantheon value, even
   * "none", wins over secrets.json, so a multidev holding a cloned
   * secrets.json still loads nothing from it.
   */
  public function testPantheonSecretsAreAuthoritative(): void {
    $live_key = $this->generateEs256Keypair();
    $provider = new TestJwtKeyProvider(
      $this->keys['cookie']['private'],
      ['agent' => $this->keys['agent']['private'], 'xdmod' => $this->keys['xdmod']['private']],
      'https://dev-accessmatch.pantheonsite.io',
      [],
      [
        'access_jwt_agent_private_key' => $live_key['private'],
        'access_jwt_exchange_private_key' => 'none',
        'access_jwt_issuer' => 'https://support.access-ci.org',
      ]
    );
    $this->assertSame($this->kidFor($live_key['private']), $provider->getSlotKeyId('agent'), 'The Pantheon key wins over secrets.json.');
    $this->assertSame(AccessJwtKeyProvider::STATUS_NONE, $provider->getKeyStatus('xdmod'), '"none" means not configured, with no fallback to secrets.json.');
    $this->assertSame('https://support.access-ci.org', $provider->getExplicitIssuer());
    // The cookie key is not read from Pantheon Secrets.
    $this->assertSame(AccessJwtKeyProvider::STATUS_LOADED, $provider->getKeyStatus('cookie'));
  }

  public function testPantheonNoneIssuerMeansNoSigning(): void {
    $provider = new TestJwtKeyProvider(
      $this->keys['cookie']['private'],
      ['agent' => $this->keys['agent']['private']],
      'https://dev-accessmatch.pantheonsite.io',
      [],
      ['access_jwt_issuer' => 'none']
    );
    $this->assertNull($provider->getExplicitIssuer());
    $this->assertNull($provider->getSigningKey('agent', 'dev-accessmatch.pantheonsite.io'));
  }

  /**
   * Where Pantheon has no such secret, or no Secrets at all, files still work.
   */
  public function testFallbackWithoutAPantheonSecret(): void {
    foreach ([NULL, []] as $pantheon) {
      $provider = new TestJwtKeyProvider(
        $this->keys['cookie']['private'],
        ['agent' => $this->keys['agent']['private']],
        'https://test.access-ci.org',
        [],
        $pantheon
      );
      $this->assertSame($this->kidFor($this->keys['agent']['private']), $provider->getSlotKeyId('agent'));
      $this->assertSame('https://test.access-ci.org', $provider->getExplicitIssuer());
    }
  }

  public function testPantheonVerifyOnlyListAndClientHash(): void {
    $next = $this->generateEs256Keypair();
    $provider = new TestJwtKeyProvider(
      $this->keys['cookie']['private'],
      [],
      'https://test.access-ci.org',
      ['access_token_exchange_secret_sha256_access_assistant' => 'file-hash'],
      [
        'access_jwt_exchange_jwks_extra_public_keys' => json_encode([$next['public']]),
        'access_jwt_cookie_jwks_extra_public_keys' => 'none',
        'access_token_exchange_secret_sha256_access_assistant' => 'pantheon-hash',
      ]
    );
    $exchange = $provider->getVerifyOnlyKeys('exchange');
    $this->assertSame(AccessJwtKeyProvider::STATUS_LOADED, $exchange['status']);
    $this->assertCount(1, $exchange['keys']);
    $this->assertSame(AccessJwtKeyProvider::STATUS_NONE, $provider->getVerifyOnlyKeys('cookie')['status']);
    $this->assertSame('pantheon-hash', $provider->getConfiguredSecret('access_token_exchange_secret_sha256_access_assistant', 'UNUSED'));

    $bad = new TestJwtKeyProvider($this->keys['cookie']['private'], [], 'https://test.access-ci.org', [], [
      'access_jwt_exchange_jwks_extra_public_keys' => 'not json',
    ]);
    $this->assertSame(AccessJwtKeyProvider::STATUS_BROKEN, $bad->getVerifyOnlyKeys('exchange')['status']);
  }

  /**
   * The real Pantheon lookup, against a stand-in pantheon_get_secret().
   *
   * The fixture's getPantheonSecret() override is bypassed so the platform
   * call path, and the trimming of pasted values, are what run.
   */
  public function testRealPantheonLookup(): void {
    require_once __DIR__ . '/../Fixtures/pantheon_get_secret.php';
    $GLOBALS['access_test_pantheon_secrets'] = [
      'access_jwt_agent_private_key' => "\n" . $this->keys['agent']['private'] . "\n",
      'access_jwt_exchange_private_key' => 'none',
      'access_jwt_issuer' => " https://test.access-ci.org\n",
    ];
    $provider = new class($this->keys['cookie']['private'], ['xdmod' => $this->keys['xdmod']['private']], NULL) extends TestJwtKeyProvider {

      protected function getPantheonSecret(string $name): ?string {
        return AccessJwtKeyProvider::getPantheonSecret(...func_get_args());
      }

    };
    $this->assertSame($this->kidFor($this->keys['agent']['private']), $provider->getSlotKeyId('agent'));
    $this->assertSame(AccessJwtKeyProvider::STATUS_NONE, $provider->getKeyStatus('xdmod'), 'none wins over secrets.json.');
    $this->assertSame('https://test.access-ci.org', $provider->getExplicitIssuer(), 'A pasted value is trimmed.');
    $this->assertNotNull($provider->getSigningKey('agent', 'test.access-ci.org'));
    unset($GLOBALS['access_test_pantheon_secrets']);
  }

}
