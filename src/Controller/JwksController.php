<?php

namespace Drupal\access\Controller;

use Drupal\access\AccessJwtKeyProvider;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves the site's two JWKS documents.
 *
 * - /.well-known/jwks.json publishes the cookie key, which signs
 *   SESSaccess_auth.
 * - /.well-known/exchange-jwks.json publishes the agent and xdmod keys, which
 *   sign the short-lived access tokens from /api/agent-token and
 *   /api/token-exchange.
 *
 * Each document also lists its verify-only keys, which is how a key is
 * published ahead of a rotation and kept after it.
 *
 * A document answers 503 when any key it should list is configured but
 * unusable, rather than serving the keys that did load: relying parties keep
 * their last good set across a failed fetch, whereas a subset (or an empty
 * set) reads as revocation. A document with nothing configured answers an
 * empty set, which is the intended "reject everything" state.
 *
 * Key loading is delegated to AccessJwtKeyProvider (shared with the signers)
 * so kid derivation and key source stay in sync.
 *
 * Response example:
 * @code
 * {
 *   "keys": [{
 *     "kty": "EC",
 *     "crv": "P-256",
 *     "x": "<base64url>",
 *     "y": "<base64url>",
 *     "kid": "a1b2c3d4e5f67890",
 *     "use": "sig",
 *     "alg": "ES256"
 *   }]
 * }
 * @endcode
 */
class JwksController {

  /**
   * The JWT key provider service.
   *
   * @var \Drupal\access\AccessJwtKeyProvider
   */
  protected AccessJwtKeyProvider $keyProvider;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\access\AccessJwtKeyProvider $key_provider
   *   The JWT key provider service.
   */
  public function __construct(AccessJwtKeyProvider $key_provider) {
    $this->keyProvider = $key_provider;
  }

  /**
   * Returns the cookie key set (/.well-known/jwks.json).
   */
  public function jwks(): JsonResponse {
    return $this->document(AccessJwtKeyProvider::DOCUMENT_COOKIE);
  }

  /**
   * Returns the access-token key set (/.well-known/exchange-jwks.json).
   */
  public function exchangeJwks(): JsonResponse {
    return $this->document(AccessJwtKeyProvider::DOCUMENT_EXCHANGE);
  }

  /**
   * Builds one JWKS document.
   *
   * @param string $document
   *   One of the AccessJwtKeyProvider::DOCUMENT_* constants.
   */
  protected function document(string $document): JsonResponse {
    $jwks = [];

    foreach (AccessJwtKeyProvider::DOCUMENT_PURPOSES[$document] as $purpose) {
      $status = $this->keyProvider->getKeyStatus($purpose);
      if ($status === AccessJwtKeyProvider::STATUS_NONE) {
        continue;
      }
      $public = $this->keyProvider->getPublicKey($purpose);
      // The cookie key keeps getKeyId() so its kid is derived exactly as the
      // cookie subscriber derives it.
      $kid = $purpose === AccessJwtKeyProvider::PURPOSE_COOKIE
        ? $this->keyProvider->getKeyId()
        : $this->keyProvider->getSlotKeyId($purpose);
      $jwk = ($public && $kid !== NULL) ? $this->toJwk($public, $kid) : NULL;
      if ($jwk === NULL) {
        return $this->unavailable($document, $purpose . ' key');
      }
      $jwks[$jwk['kid']] = $jwk;
    }

    $verify_only = $this->keyProvider->getVerifyOnlyKeys($document);
    if ($verify_only['status'] === AccessJwtKeyProvider::STATUS_BROKEN) {
      return $this->unavailable($document, 'verify-only key list');
    }
    foreach ($verify_only['keys'] as $public) {
      $kid = $this->keyProvider->thumbprint($public);
      $jwk = $kid !== NULL ? $this->toJwk($public, $kid) : NULL;
      if ($jwk === NULL) {
        return $this->unavailable($document, 'verify-only key list');
      }
      // A verify-only copy of an active key is listed once.
      $jwks[$kid] ??= $jwk;
    }

    return new JsonResponse(['keys' => array_values($jwks)], 200, [
      'Cache-Control' => 'public, max-age=300',
    ]);
  }

  /**
   * Builds a JWK from an EC P-256 public key.
   *
   * @param \OpenSSLAsymmetricKey $public_key
   *   The key.
   * @param string $kid
   *   Its key id.
   *
   * @return array<string, string>|null
   *   The JWK, or NULL if the key is not EC P-256.
   */
  protected function toJwk(\OpenSSLAsymmetricKey $public_key, string $kid): ?array {
    $details = openssl_pkey_get_details($public_key);
    if ($details === FALSE || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
      return NULL;
    }
    $ec = $details['ec'] ?? NULL;
    if (($ec['curve_name'] ?? '') !== 'prime256v1' || empty($ec['x']) || empty($ec['y'])) {
      return NULL;
    }

    // OpenSSL drops leading zero bytes; a P-256 coordinate is always 32 bytes.
    return [
      'kty' => 'EC',
      'crv' => 'P-256',
      'x' => $this->base64urlEncode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
      'y' => $this->base64urlEncode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
      'kid' => $kid,
      'use' => 'sig',
      'alg' => 'ES256',
    ];
  }

  /**
   * The 503 for a document with a configured key that cannot be served.
   */
  protected function unavailable(string $document, string $what): JsonResponse {
    \Drupal::logger('access')->error('JWKS @document document unavailable: a configured @what cannot be loaded.', [
      '@document' => $document,
      '@what' => $what,
    ]);
    return new JsonResponse(['error' => 'temporarily_unavailable'], 503, [
      'Cache-Control' => 'no-store',
    ]);
  }

  /**
   * Base64url-encodes binary data per RFC 7515 section 2.
   *
   * @param string $data
   *   Raw binary string.
   *
   * @return string
   *   Base64url-encoded string (no padding).
   */
  protected function base64urlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

}
