<?php

namespace Drupal\Tests\access\Kernel;

/**
 * Key generation shared by the token kernel tests.
 */
trait TokenKeysTestTrait {

  /**
   * Generates an ES256 (P-256) keypair.
   *
   * @return array{private: string, public: string}
   *   PEM strings.
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

  /**
   * The kid the provider derives for a PEM (public or private).
   */
  protected function kidFor(string $pem): string {
    $key = openssl_pkey_get_private($pem) ?: openssl_pkey_get_public($pem);
    $details = openssl_pkey_get_details($key);
    return substr(hash('sha256', $details['key']), 0, 16);
  }

}
