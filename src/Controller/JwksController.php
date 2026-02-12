<?php

namespace Drupal\access\Controller;

use Drupal\access\AccessJwtKeyProvider;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves the JWKS endpoint for JWT cookie verification.
 *
 * Publishes the site's ES256 (EC P-256) public key in JWK format at
 * /.well-known/jwks.json so that the QA Bot agent (and any other relying
 * party) can verify JWT cookies signed by this site.
 *
 * Key loading is delegated to AccessJwtKeyProvider (shared with the
 * event subscriber) so kid derivation and key source stay in sync.
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
   * Returns the JWKS response containing the site's public signing key.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the keys array.
   */
  public function jwks(): JsonResponse {
    $private_key = $this->keyProvider->getPrivateKey();
    if ($private_key === NULL) {
      // No key configured — return empty key set rather than error,
      // per RFC 7517 section 5. The agent will simply reject all JWTs.
      return new JsonResponse(['keys' => []], 200, [
        'Cache-Control' => 'public, max-age=3600',
      ]);
    }

    $details = openssl_pkey_get_details($private_key);
    if ($details === FALSE || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
      \Drupal::logger('access')->error('JWKS: configured key is not an EC key.');
      return new JsonResponse(['keys' => []], 200);
    }

    $kid = $this->keyProvider->getKeyId($private_key);
    if ($kid === NULL) {
      return new JsonResponse(['keys' => []], 200);
    }

    $ec = $details['ec'] ?? NULL;
    if (empty($ec['x']) || empty($ec['y'])) {
      \Drupal::logger('access')->error('JWKS: EC key missing x or y coordinates.');
      return new JsonResponse(['keys' => []], 200);
    }

    // Build the JWK from the EC public key coordinates.
    $jwk = [
      'kty' => 'EC',
      'crv' => 'P-256',
      'x' => $this->base64urlEncode($ec['x']),
      'y' => $this->base64urlEncode($ec['y']),
      'kid' => $kid,
      'use' => 'sig',
      'alg' => 'ES256',
    ];

    return new JsonResponse(['keys' => [$jwk]], 200, [
      'Cache-Control' => 'public, max-age=3600',
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
