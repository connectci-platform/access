<?php

namespace Drupal\access\TokenExchange;

use Drupal\access\AccessJwtKeyProvider;
use Drupal\Component\Datetime\TimeInterface;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;

/**
 * Mints and logs the short-lived access tokens for both token endpoints.
 *
 * Tokens are ES256 JWTs typed at+jwt, signed with the key for their purpose
 * (agent or xdmod), and live exactly LIFETIME seconds: iat and exp come from
 * one clock read, and no nbf is set, so a relying party that caps
 * exp - iat at the lifetime never sees a token one second over.
 */
class TokenIssuer {

  /**
   * Token lifetime in seconds.
   */
  const LIFETIME = 900;

  public function __construct(
    protected readonly AccessJwtKeyProvider $keyProvider,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Mints a token.
   *
   * @param string $purpose
   *   AccessJwtKeyProvider::PURPOSE_AGENT or PURPOSE_XDMOD.
   * @param string $host
   *   The current request's host, for the issuer host-match guard.
   * @param string $sub
   *   The ACCESS ID.
   * @param string $audience
   *   The token audience.
   * @param string $client_id
   *   The client the token is issued to, stamped as azp.
   * @param string[] $scopes
   *   The granted scopes.
   * @param array<string, string> $log_context
   *   Extra fields for the issuance log line: endpoint, and origin for the
   *   browser grant.
   *
   * @return string|null
   *   The token, or NULL if signing is unavailable (already logged).
   */
  public function issue(string $purpose, string $host, string $sub, string $audience, string $client_id, array $scopes, array $log_context): ?string {
    $signer = $this->keyProvider->getSigningKey($purpose, $host);
    if ($signer === NULL) {
      return NULL;
    }

    $now = $this->time->getCurrentTime();
    $payload = [
      'iss' => $signer['iss'],
      'sub' => $sub,
      'aud' => $audience,
      'azp' => $client_id,
      'scope' => implode(' ', $scopes),
      'iat' => $now,
      'exp' => $now + self::LIFETIME,
      'jti' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
    ];

    try {
      $token = JWT::encode($payload, $signer['key'], 'ES256', $signer['kid'], ['typ' => 'at+jwt']);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to sign @purpose token: @message', [
        '@purpose' => $purpose,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    // Never the token itself: jti is what ties a log line to a token.
    $this->logger->info('Issued @endpoint token: client @client, origin @origin, sub @sub, aud @aud, scope @scope, jti @jti, exp @exp.', [
      '@endpoint' => $log_context['endpoint'] ?? $purpose,
      '@client' => $client_id,
      '@origin' => $log_context['origin'] ?? '-',
      '@sub' => $sub,
      '@aud' => $audience,
      '@scope' => $payload['scope'],
      '@jti' => $payload['jti'],
      '@exp' => $payload['exp'],
    ]);

    return $token;
  }

}
