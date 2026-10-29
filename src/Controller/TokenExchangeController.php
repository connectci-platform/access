<?php

namespace Drupal\access\Controller;

use Drupal\access\AccessIdResolver;
use Drupal\access\AccessJwtKeyProvider;
use Drupal\access\TokenExchange\ClientRegistry;
use Drupal\access\TokenExchange\TokenIssuer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Flood\FloodInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /api/token-exchange: OAuth 2.0 token exchange (RFC 8693).
 *
 * A registered confidential client authenticates with HTTP Basic and
 * presents a user's agent token as the subject token. If the agent token is
 * valid, the response is a 15-minute at+jwt token for the same user,
 * addressed to the client's own audience and signed with the xdmod key.
 *
 * The subject token is verified against the agent key only. An exchanged
 * token is signed with a different key, so it can never be exchanged again,
 * and a SESSaccess_auth cookie is signed with a third.
 */
class TokenExchangeController {

  const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';
  const ACCESS_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

  /**
   * Largest subject token accepted, in bytes.
   */
  const MAX_SUBJECT_TOKEN = 4096;

  /**
   * Failed client authentication, per IP: 10 per 15 minutes.
   */
  const FLOOD_AUTH_EVENT = 'access.token_exchange.client_auth_failed';
  const FLOOD_AUTH_LIMIT = 10;
  const FLOOD_AUTH_WINDOW = 900;

  /**
   * Successful exchanges, per client and user: 60 per hour.
   */
  const FLOOD_SUB_EVENT = 'access.token_exchange.sub';
  const FLOOD_SUB_LIMIT = 60;
  const FLOOD_SUB_WINDOW = 3600;

  const FIELDS = ['grant_type', 'subject_token', 'subject_token_type', 'audience', 'scope'];

  public function __construct(
    protected readonly ClientRegistry $registry,
    protected readonly TokenIssuer $issuer,
    protected readonly AccessJwtKeyProvider $keyProvider,
    protected readonly AccessIdResolver $accessIdResolver,
    protected readonly FloodInterface $flood,
    protected readonly LoggerInterface $logger,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Handles a token-exchange request.
   */
  public function exchange(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return $this->error(400, 'invalid_request', 'Use POST.');
    }
    // Tokens in a URL end up in logs, so the query string is refused outright.
    foreach (self::FIELDS as $field) {
      if ($request->query->has($field)) {
        return $this->error(400, 'invalid_request', 'Send the parameters in the request body.');
      }
    }

    // Failed client authentication is counted per IP. A correct secret is
    // accepted even past the limit, so someone sharing the client's egress
    // IP cannot lock it out. That means the limit does not slow guessing:
    // client secrets must be generated with at least 256 bits of entropy.
    // What the limit does is turn repeated failures into 429s and stop
    // recording them, so junk traffic cannot grow the flood table.
    $ip = (string) $request->getClientIp();
    $client_id = $this->authenticate($request);
    if ($client_id === NULL) {
      if (!$this->flood->isAllowed(self::FLOOD_AUTH_EVENT, self::FLOOD_AUTH_LIMIT, self::FLOOD_AUTH_WINDOW, $ip)) {
        $this->logger->notice('Token exchange refused: client authentication flood limit for @ip.', ['@ip' => $ip]);
        return $this->error(429, 'slow_down', 'Too many failed attempts. Try again later.', [
          'Retry-After' => (string) self::FLOOD_AUTH_WINDOW,
        ]);
      }
      $this->flood->register(self::FLOOD_AUTH_EVENT, self::FLOOD_AUTH_WINDOW, $ip);
      return $this->error(401, 'invalid_client', 'Client authentication failed.', [
        'WWW-Authenticate' => 'Basic realm="token-exchange"',
      ]);
    }
    $client = $this->registry->getClient($client_id);

    // Read through all() so a bracketed field (grant_type[]=x) is a value
    // that fails is_string(), not an exception and a generic 400.
    $body = $request->request->all();
    foreach (self::FIELDS as $field) {
      if (isset($body[$field]) && !is_string($body[$field])) {
        return $this->error(400, 'invalid_request', "$field is malformed.");
      }
    }
    $grant_type = $body['grant_type'] ?? '';
    if ($grant_type === '') {
      return $this->error(400, 'invalid_request', 'grant_type is required.');
    }
    if ($grant_type !== self::GRANT_TYPE) {
      return $this->error(400, 'unsupported_grant_type', 'Only token exchange is supported.');
    }
    if (($body['subject_token_type'] ?? NULL) !== self::ACCESS_TOKEN_TYPE) {
      return $this->error(400, 'invalid_request', 'subject_token_type must be ' . self::ACCESS_TOKEN_TYPE . '.');
    }
    $subject_token = $body['subject_token'] ?? NULL;
    if (!is_string($subject_token) || $subject_token === '' || strlen($subject_token) > self::MAX_SUBJECT_TOKEN) {
      return $this->error(400, 'invalid_request', 'subject_token is missing or malformed.');
    }
    $audience = $body['audience'] ?? NULL;
    $scope = $body['scope'] ?? NULL;
    if (!is_string($audience) || $audience === '' || !is_string($scope) || trim($scope) === '') {
      return $this->error(400, 'invalid_request', 'audience and scope are required.');
    }
    if ($audience !== $client['audience']) {
      return $this->error(400, 'invalid_target', 'The audience is not allowed for this client.');
    }
    $scopes = array_values(array_unique(preg_split('/ +/', trim($scope)) ?: []));
    if (array_diff($scopes, $client['scopes'])) {
      return $this->error(400, 'invalid_scope', 'The scope is not allowed for this client.');
    }

    $signer = $this->keyProvider->getSigningKey(AccessJwtKeyProvider::PURPOSE_XDMOD, $request->getHost());
    $agent_key = $this->keyProvider->getPublicKey(AccessJwtKeyProvider::PURPOSE_AGENT);
    if ($signer === NULL || $agent_key === NULL) {
      return $this->error(500, 'server_error', 'Token exchange is not available.');
    }

    $sub = $this->verifySubjectToken($subject_token, $client, $agent_key, $signer['iss'], $client_id);
    if ($sub === NULL) {
      return $this->error(400, 'invalid_grant', 'The subject token is not valid.');
    }

    $flood_id = $client_id . ':' . $sub;
    if (!$this->flood->isAllowed(self::FLOOD_SUB_EVENT, self::FLOOD_SUB_LIMIT, self::FLOOD_SUB_WINDOW, $flood_id)) {
      $this->logger->notice('Token exchange refused: flood limit for client @client, sub @sub.', [
        '@client' => $client_id,
        '@sub' => $sub,
      ]);
      return $this->error(429, 'slow_down', 'Too many requests. Try again later.', [
        'Retry-After' => (string) self::FLOOD_SUB_WINDOW,
      ]);
    }
    $token = $this->issuer->issue(
      AccessJwtKeyProvider::PURPOSE_XDMOD,
      $request->getHost(),
      $sub,
      $client['audience'],
      $client_id,
      $scopes,
      ['endpoint' => 'token-exchange']
    );
    if ($token === NULL) {
      return $this->error(500, 'server_error', 'Token exchange is not available.');
    }
    // Counted only once issued, so server errors spend no allowance.
    $this->flood->register(self::FLOOD_SUB_EVENT, self::FLOOD_SUB_WINDOW, $flood_id);

    return $this->noStore(new JsonResponse([
      'access_token' => $token,
      'issued_token_type' => self::ACCESS_TOKEN_TYPE,
      'token_type' => 'Bearer',
      'expires_in' => TokenIssuer::LIFETIME,
    ]));
  }

  /**
   * Authenticates the client from HTTP Basic credentials.
   *
   * The id and secret are each form-urlencoded before base64 encoding
   * (RFC 6749 section 2.3.1), so they are decoded after the split.
   *
   * @return string|null
   *   The authenticated confidential client's id, or NULL.
   */
  protected function authenticate(Request $request): ?string {
    $header = (string) $request->headers->get('Authorization', '');
    if (!preg_match('/^Basic +([A-Za-z0-9+\/=]+)$/i', $header, $m)) {
      return NULL;
    }
    $decoded = base64_decode($m[1], TRUE);
    if ($decoded === FALSE || !str_contains($decoded, ':')) {
      return NULL;
    }
    [$id, $secret] = explode(':', $decoded, 2);
    $id = urldecode($id);
    if ($this->registry->verifySecret($id, urldecode($secret))) {
      return $id;
    }
    $this->logger->notice('Token exchange refused: client authentication failed for @client.', [
      '@client' => preg_match('/^[a-z0-9-]{1,64}$/', $id) ? $id : '(invalid id)',
    ]);
    return NULL;
  }

  /**
   * Verifies the subject token and returns its ACCESS ID.
   *
   * Every failure is logged by category and returns NULL, which the caller
   * answers with one undifferentiated invalid_grant.
   *
   * @param string $jwt
   *   The subject token.
   * @param array<string, mixed> $client
   *   The authenticated confidential client.
   * @param \OpenSSLAsymmetricKey $agent_key
   *   The agent public key, the only key a subject token may be signed with.
   * @param string $issuer
   *   This site's explicit issuer.
   * @param string $client_id
   *   The client id, for logging.
   *
   * @return string|null
   *   The subject's ACCESS ID, or NULL if the token is not acceptable.
   */
  protected function verifySubjectToken(string $jwt, array $client, \OpenSSLAsymmetricKey $agent_key, string $issuer, string $client_id): ?string {
    $reject = function (string $why) use ($client_id): ?string {
      $this->logger->notice('Token exchange refused for client @client: subject token @why.', [
        '@client' => $client_id,
        '@why' => $why,
      ]);
      return NULL;
    };

    // The header is checked before the signature: it must name the agent key
    // and the access-token type.
    $segments = explode('.', $jwt);
    $header = count($segments) === 3 ? json_decode((string) JWT::urlsafeB64Decode($segments[0]), TRUE) : NULL;
    if (!is_array($header)
      || ($header['alg'] ?? NULL) !== 'ES256'
      || ($header['typ'] ?? NULL) !== 'at+jwt'
      || ($header['kid'] ?? NULL) !== $this->keyProvider->getSlotKeyId(AccessJwtKeyProvider::PURPOSE_AGENT)) {
      return $reject('has the wrong header');
    }

    try {
      $claims = JWT::decode($jwt, new Key($agent_key, 'ES256'));
    }
    catch (\Throwable $e) {
      return $reject('failed verification (' . get_class($e) . ')');
    }

    if (($claims->iss ?? NULL) !== $issuer) {
      return $reject('has the wrong issuer');
    }
    $iat = $claims->iat ?? NULL;
    $exp = $claims->exp ?? NULL;
    if (!is_int($iat) || !is_int($exp) || $exp <= $this->time->getCurrentTime() || $exp - $iat > TokenIssuer::LIFETIME) {
      return $reject('has a missing, expired or over-long lifetime');
    }

    // The azp must be a browser client this client may exchange for, and
    // the aud that browser client's audience.
    $azp = $claims->azp ?? NULL;
    $browser = is_string($azp) ? $this->registry->getClient($azp) : NULL;
    if ($browser === NULL
      || $browser['type'] !== ClientRegistry::TYPE_BROWSER
      || !in_array($azp, $client['subject_clients'], TRUE)
      || ($claims->aud ?? NULL) !== $browser['audience']) {
      return $reject('is not an agent token this client may exchange');
    }

    $scope = $claims->scope ?? NULL;
    if (!is_string($scope) || !in_array('agent:read', explode(' ', $scope), TRUE)) {
      return $reject('lacks agent:read');
    }

    $sub = $claims->sub ?? NULL;
    if (!is_string($sub) || $this->accessIdResolver->qualify($sub) !== $sub || $this->accessIdResolver->resolve($sub) === NULL) {
      return $reject('names no active ACCESS ID');
    }
    return $sub;
  }

  /**
   * An RFC 6749 section 5.2 error response.
   *
   * @param int $status
   *   The HTTP status.
   * @param string $error
   *   The error code.
   * @param string $description
   *   The error description.
   * @param array<string, string> $headers
   *   Extra response headers.
   */
  protected function error(int $status, string $error, string $description, array $headers = []): JsonResponse {
    return $this->noStore(new JsonResponse(['error' => $error, 'error_description' => $description], $status, $headers));
  }

  /**
   * Marks a response as never cacheable.
   */
  protected function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
