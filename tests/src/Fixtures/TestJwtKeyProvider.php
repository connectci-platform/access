<?php

namespace Drupal\Tests\access\Fixtures;

use Drupal\access\AccessJwtKeyProvider;

/**
 * Key provider stub backed by test-generated ES256 keys.
 *
 * The real provider reads from Pantheon secrets / env, neither of which exists
 * in a kernel test, and it caches the cookie key in a static. This stub takes
 * the PEMs directly so a test can sign and then verify with the matching
 * public key.
 */
class TestJwtKeyProvider extends AccessJwtKeyProvider {

  /**
   * Constructs the stub.
   *
   * @param string $pem
   *   A PEM-encoded EC private key for the cookie slot.
   * @param array<string, string> $slotPems
   *   PEMs for the other slots, keyed by purpose ('agent', 'xdmod'). A
   *   present-but-invalid string models a configured key that fails to load.
   * @param string|null $explicitIssuer
   *   The explicitly configured issuer, or NULL for none.
   * @param array<string, mixed> $secrets
   *   Other secrets-file values, such as verify-only key lists and client
   *   secret hashes.
   * @param array<string, string>|null $pantheonSecrets
   *   Pantheon Secrets values by name, or NULL to model a platform without
   *   pantheon_get_secret() (ddev, CI).
   */
  public function __construct(
    protected string $pem,
    protected array $slotPems = [],
    protected ?string $explicitIssuer = 'https://test.access-ci.org',
    protected array $secrets = [],
    protected ?array $pantheonSecrets = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getIssuer(): string {
    return 'https://test.access-ci.org';
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivateKey() {
    return openssl_pkey_get_private($this->pem) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyId($private_key = NULL): ?string {
    return 'test-kid';
  }

  /**
   * {@inheritdoc}
   */
  protected function readPem(string $purpose): ?string {
    if ($purpose === self::PURPOSE_COOKIE) {
      return $this->pem;
    }
    return parent::readPem($purpose);
  }

  /**
   * {@inheritdoc}
   *
   * Slot PEMs and the explicit issuer are served as secrets.json values, so
   * the provider's real source order is what runs.
   */
  protected function getSecretFromFile(string $name): mixed {
    if (array_key_exists($name, $this->secrets)) {
      return $this->secrets[$name];
    }
    if ($name === 'access_jwt_issuer') {
      return $this->explicitIssuer;
    }
    foreach ($this->slotPems as $purpose => $pem) {
      if ($name === self::KEY_SOURCES[$purpose][0]) {
        return $pem;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getPantheonSecret(string $name): ?string {
    return $this->pantheonSecrets === NULL ? NULL : ($this->pantheonSecrets[$name] ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEnv(string $name): ?string {
    return NULL;
  }

}
