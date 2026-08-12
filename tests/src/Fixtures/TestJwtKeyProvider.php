<?php

namespace Drupal\Tests\access\Fixtures;

use Drupal\access\AccessJwtKeyProvider;

/**
 * Key provider stub backed by a test-generated ES256 key.
 *
 * The real provider reads from Pantheon secrets / env, neither of which exists
 * in a kernel test, and it caches the key in a static. This stub takes the PEM
 * directly so a test can sign and then verify with the matching public key.
 */
class TestJwtKeyProvider extends AccessJwtKeyProvider {

  /**
   * Constructs the stub.
   *
   * @param string $pem
   *   A PEM-encoded EC private key.
   */
  public function __construct(protected string $pem) {}

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
    return openssl_pkey_get_private($this->pem);
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyId($private_key = NULL): ?string {
    return 'test-kid';
  }

}
