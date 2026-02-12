<?php

namespace Drupal\access;

/**
 * Provides the EC P-256 private key and derived kid for JWT operations.
 *
 * Shared by AccessAuthCookieSubscriber (signing) and JwksController
 * (public key publishing) so key loading logic lives in one place.
 *
 * The private key is loaded from environment variables:
 *   1. ACCESS_JWT_PRIVATE_KEY — PEM-encoded string
 *   2. ACCESS_JWT_PRIVATE_KEY_FILE — path to a PEM file
 *
 * On Pantheon, set ACCESS_JWT_PRIVATE_KEY via the dashboard. For DDEV,
 * either env var works.
 */
class AccessJwtKeyProvider {

  /**
   * Default issuer for JWTs signed by this site.
   *
   * Override via ACCESS_JWT_ISSUER env var for staging/DDEV.
   */
  const JWT_ISSUER_DEFAULT = 'https://support.access-ci.org';

  /**
   * Cached private key (NULL = not loaded, FALSE = load failed).
   *
   * @var \OpenSSLAsymmetricKey|null|false
   */
  protected static $privateKey = NULL;

  /**
   * Whether the key has been loaded (to distinguish NULL from "not tried").
   *
   * @var bool
   */
  protected static bool $loaded = FALSE;

  /**
   * Returns the JWT issuer (iss) for this site.
   *
   * @return string
   *   The issuer URL.
   */
  public function getIssuer(): string {
    $issuer = getenv('ACCESS_JWT_ISSUER');
    return !empty($issuer) ? $issuer : self::JWT_ISSUER_DEFAULT;
  }

  /**
   * Gets the EC private key for JWT signing.
   *
   * Uses static caching so the key is loaded at most once per PHP process.
   *
   * @return \OpenSSLAsymmetricKey|null
   *   The private key, or NULL if not configured or invalid.
   */
  public function getPrivateKey() {
    if (self::$loaded) {
      return self::$privateKey ?: NULL;
    }
    self::$loaded = TRUE;

    // Try PEM string from env var first.
    $pem = getenv('ACCESS_JWT_PRIVATE_KEY');
    if (!empty($pem)) {
      $key = openssl_pkey_get_private($pem);
      if ($key === FALSE) {
        \Drupal::logger('access')->warning(
          'ACCESS_JWT_PRIVATE_KEY is set but is not a valid EC private key.'
        );
        self::$privateKey = FALSE;
        return NULL;
      }
      self::$privateKey = $key;
      return $key;
    }

    // Try file path from env var.
    $path = getenv('ACCESS_JWT_PRIVATE_KEY_FILE');
    if (!empty($path) && file_exists($path)) {
      $pem = file_get_contents($path);
      $key = openssl_pkey_get_private($pem);
      if ($key === FALSE) {
        \Drupal::logger('access')->warning(
          'ACCESS_JWT_PRIVATE_KEY_FILE (@path) is not a valid EC private key.', [
            '@path' => $path,
          ]
        );
        self::$privateKey = FALSE;
        return NULL;
      }
      self::$privateKey = $key;
      return $key;
    }

    \Drupal::logger('access')->warning(
      'JWT signing key not found. Set ACCESS_JWT_PRIVATE_KEY (PEM string) or ACCESS_JWT_PRIVATE_KEY_FILE (path).'
    );
    self::$privateKey = FALSE;
    return NULL;
  }

  /**
   * Derives a Key ID (kid) from the private key's public component.
   *
   * Uses the SHA-256 thumbprint of the public key PEM, truncated to 16 hex
   * chars. The same kid appears in JWT headers and in the JWKS endpoint so
   * the agent can match tokens to verification keys.
   *
   * @param \OpenSSLAsymmetricKey|null $private_key
   *   The private key. If NULL, attempts to load it.
   *
   * @return string|null
   *   The key ID, or NULL if key details cannot be extracted.
   */
  public function getKeyId($private_key = NULL): ?string {
    $private_key = $private_key ?? $this->getPrivateKey();
    if ($private_key === NULL) {
      return NULL;
    }

    $details = openssl_pkey_get_details($private_key);
    if ($details === FALSE || empty($details['key'])) {
      \Drupal::logger('access')->warning('Failed to extract public key details for kid derivation.');
      return NULL;
    }
    return substr(hash('sha256', $details['key']), 0, 16);
  }

}
