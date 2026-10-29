<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\Controller\TokenExchangeController;
use Drupal\Core\Flood\FloodInterface;
use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers POST /api/token-exchange (RFC 8693) for the XDMoD audience.
 *
 * @group access
 */
class TokenExchangeControllerTest extends TokenEndpointTestBase {

  const GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';
  const ACCESS_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

  /**
   * A flood service to use instead of the container's, if set.
   */
  protected ?FloodInterface $flood = NULL;

  protected function setUp(): void {
    parent::setUp();
    $this->createAccessUser('jsmith', 'jsmith@access-ci.org');
  }

  protected function controller(): TokenExchangeController {
    return new TokenExchangeController(
      $this->registry,
      $this->issuer,
      $this->keyProvider,
      \Drupal::service('access.access_id_resolver'),
      $this->flood ?? \Drupal::flood(),
      $this->logger,
      \Drupal::time(),
    );
  }

  /**
   * An agent token as /api/agent-token would mint it, with overrides.
   */
  protected function agentToken(array $claims = [], array $header = [], string $key = 'agent'): string {
    $now = time();
    $claims += [
      'iss' => self::ISSUER,
      'sub' => 'jsmith@access-ci.org',
      'aud' => self::QA,
      'azp' => 'access-qa-bot',
      'scope' => 'agent:read agent:write',
      'iat' => $now,
      'exp' => $now + 900,
      'jti' => 'jti-' . bin2hex(random_bytes(4)),
    ];
    $claims = array_filter($claims, fn($v) => $v !== NULL);
    $kid = $header['kid'] ?? $this->kidFor($this->keys[$key]['private']);
    $head = array_diff_key($header, ['kid' => 1]) + ['typ' => 'at+jwt'];
    return JWT::encode($claims, $this->keys[$key]['private'], 'ES256', $kid, $head);
  }

  protected function fields(array $over = []): array {
    return array_filter($over + [
      'grant_type' => self::GRANT,
      'subject_token' => $this->agentToken(),
      'subject_token_type' => self::ACCESS_TOKEN_TYPE,
      'audience' => self::XDMOD,
      'scope' => 'xdmod:read',
    ], fn($v) => $v !== NULL);
  }

  protected function request(array $fields, ?string $basic = NULL, string $method = 'POST', array $query = []): Request {
    $basic ??= base64_encode('access-assistant:' . rawurlencode(self::SECRET));
    $uri = 'https://' . self::HOST . '/api/token-exchange' . ($query ? '?' . http_build_query($query) : '');
    $server = ['REMOTE_ADDR' => '192.0.2.10', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
    if ($basic !== '') {
      $server['HTTP_AUTHORIZATION'] = 'Basic ' . $basic;
    }
    return Request::create($uri, $method, $method === 'POST' ? $fields : [], [], [], $server);
  }

  protected function exchange(?Request $request = NULL): Response {
    return $this->controller()->exchange($request ?? $this->request($this->fields()));
  }

  protected function assertError(Response $response, int $status, string $error): void {
    $this->assertSame($status, $response->getStatusCode(), $response->getContent());
    $this->assertSame($error, $this->body($response)['error']);
    $this->assertArrayHasKey('error_description', $this->body($response));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
  }

  public function testExchangeMintsAnXdmodToken(): void {
    $subject = $this->agentToken();
    $response = $this->exchange($this->request($this->fields(['subject_token' => $subject])));

    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $body = $this->body($response);
    $this->assertSame(self::ACCESS_TOKEN_TYPE, $body['issued_token_type']);
    $this->assertSame('Bearer', $body['token_type']);
    $this->assertSame(900, $body['expires_in']);

    [$header, $claims] = $this->parts($body['access_token']);
    $this->assertSame([
      'typ' => 'at+jwt',
      'alg' => 'ES256',
      'kid' => $this->kidFor($this->keys['xdmod']['private']),
    ], $header);
    $this->assertSame(['iss', 'sub', 'aud', 'azp', 'scope', 'iat', 'exp', 'jti'], array_keys($claims));
    $this->assertSame(self::ISSUER, $claims['iss']);
    $this->assertSame('jsmith@access-ci.org', $claims['sub']);
    $this->assertSame(self::XDMOD, $claims['aud']);
    $this->assertSame('access-assistant', $claims['azp']);
    $this->assertSame('xdmod:read', $claims['scope']);
    $this->assertSame(900, $claims['exp'] - $claims['iat']);
    $this->assertArrayNotHasKey('nbf', $claims);
    $this->assertSame('jsmith@access-ci.org', $this->verifyWith('xdmod', $body['access_token'])->sub);

    $this->assertNotLogged($body['access_token']);
    $this->assertNotLogged($subject);
    $this->assertNotLogged(self::SECRET);
    $this->assertNotLogged(base64_encode('access-assistant:' . rawurlencode(self::SECRET)));
  }

  /**
   * The Basic credentials are URL-decoded after base64 (RFC 6749 2.3.1).
   */
  public function testUrlEncodedSecretDecodes(): void {
    $this->configure();
    $raw = 'p@ss:w/rd+%';
    $this->keyProvider = new \Drupal\Tests\access\Fixtures\TestJwtKeyProvider(
      $this->keys['cookie']['private'],
      ['agent' => $this->keys['agent']['private'], 'xdmod' => $this->keys['xdmod']['private']],
      self::ISSUER,
      ['access_token_exchange_secret_sha256_access-assistant' => hash('sha256', $raw)],
    );
    $this->registry = new \Drupal\access\TokenExchange\ClientRegistry(\Drupal::configFactory(), $this->keyProvider, $this->logger);
    $this->issuer = new \Drupal\access\TokenExchange\TokenIssuer($this->keyProvider, \Drupal::time(), $this->logger);
    $response = $this->exchange($this->request($this->fields(), base64_encode('access-assistant:' . urlencode($raw))));
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
  }

  /**
   * @dataProvider clientAuthCases
   */
  public function testClientAuthFailure(string $basic): void {
    $response = $this->exchange($this->request($this->fields(), $basic));
    $this->assertError($response, 401, 'invalid_client');
    $this->assertStringStartsWith('Basic', $response->headers->get('WWW-Authenticate'));
  }

  public static function clientAuthCases(): array {
    return [
      'missing' => [''],
      'wrong secret' => [base64_encode('access-assistant:wrong')],
      'unknown client' => [base64_encode('nobody:' . self::SECRET)],
      'browser client' => [base64_encode('access-qa-bot:' . self::SECRET)],
      'not base64' => ['%%%'],
      'no colon' => [base64_encode('access-assistant')],
    ];
  }

  public function testClientAuthFailuresAreLimitedPerIp(): void {
    $bad = base64_encode('access-assistant:wrong');
    for ($i = 0; $i < 10; $i++) {
      $this->assertError($this->exchange($this->request($this->fields(), $bad)), 401, 'invalid_client');
    }
    // Past the limit, a wrong secret gets 429 rather than another 401.
    $response = $this->exchange($this->request($this->fields(), $bad));
    $this->assertError($response, 429, 'slow_down');
    $this->assertSame('900', $response->headers->get('Retry-After'));
    $this->assertError($this->exchange($this->request($this->fields(), '')), 429, 'slow_down');

    // The real client still gets through: someone sharing its egress IP
    // cannot lock it out by sending junk.
    $this->assertSame(200, $this->exchange()->getStatusCode());

    // Past the limit, failures stop adding flood entries, so junk traffic
    // cannot grow the flood table without bound.
    $this->flood = new class(\Drupal::flood()) implements FloodInterface {

      public int $registered = 0;

      public function __construct(protected FloodInterface $inner) {}

      public function register($name, $window = 3600, $identifier = NULL) {
        $this->registered++;
        $this->inner->register($name, $window, $identifier);
      }

      public function clear($name, $identifier = NULL) {
        $this->inner->clear($name, $identifier);
      }

      public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL) {
        return $this->inner->isAllowed($name, $threshold, $window, $identifier);
      }

      public function garbageCollection() {
        $this->inner->garbageCollection();
      }

    };
    for ($i = 0; $i < 5; $i++) {
      $this->assertError($this->exchange($this->request($this->fields(), $bad)), 429, 'slow_down');
    }
    $this->assertSame(0, $this->flood->registered);
  }

  /**
   * A server error does not spend the client's per-user allowance.
   */
  public function testServerErrorsAreNotCounted(): void {
    $this->configure([], 'https://dev-accessmatch.pantheonsite.io');
    for ($i = 0; $i < 70; $i++) {
      $this->assertSame(500, $this->exchange()->getStatusCode());
    }
    $this->configure();
    $this->assertSame(200, $this->exchange()->getStatusCode());
  }

  /**
   * Bracketed form fields get the contract's JSON error, not a generic 400.
   */
  public function testArrayFieldsAreInvalidRequest(): void {
    foreach (self::FIELDS_UNDER_TEST as $field) {
      $fields = $this->fields();
      $fields[$field] = ['x'];
      $this->assertError($this->exchange($this->request($fields)), 400, 'invalid_request');
    }
  }

  const FIELDS_UNDER_TEST = ['grant_type', 'subject_token', 'subject_token_type', 'audience', 'scope'];

  /**
   * The Basic scheme name is case-insensitive (RFC 7617).
   */
  public function testBasicSchemeIsCaseInsensitive(): void {
    $request = $this->request($this->fields());
    $request->headers->set('Authorization', 'basic ' . base64_encode('access-assistant:' . rawurlencode(self::SECRET)));
    $this->assertSame(200, $this->exchange($request)->getStatusCode());
  }

  public function testMethodAndQueryString(): void {
    $this->assertError($this->exchange($this->request([], NULL, 'GET')), 400, 'invalid_request');
    $fields = $this->fields();
    $request = $this->request($fields, NULL, 'POST', ['subject_token' => $fields['subject_token']]);
    $this->assertError($this->exchange($request), 400, 'invalid_request');
  }

  /**
   * @dataProvider fieldCases
   */
  public function testFieldErrors(array $over, string $error): void {
    $this->assertError($this->exchange($this->request($this->fields($over))), 400, $error);
  }

  public static function fieldCases(): array {
    return [
      'grant_type missing' => [['grant_type' => NULL], 'invalid_request'],
      'grant_type other' => [['grant_type' => 'client_credentials'], 'unsupported_grant_type'],
      'subject_token_type wrong' => [['subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt'], 'invalid_request'],
      'subject_token missing' => [['subject_token' => NULL], 'invalid_request'],
      'subject_token too long' => [['subject_token' => str_repeat('a', 4097)], 'invalid_request'],
      'audience missing' => [['audience' => NULL], 'invalid_request'],
      'scope missing' => [['scope' => NULL], 'invalid_request'],
      'audience not allowed' => [['audience' => 'https://qa.access-ci.org'], 'invalid_target'],
      'scope not allowed' => [['scope' => 'xdmod:read agent:write'], 'invalid_scope'],
    ];
  }

  /**
   * Every subject-token defect is the same invalid_grant.
   *
   * @dataProvider subjectTokenCases
   */
  public function testSubjectTokenRejected(string $case): void {
    $response = $this->exchange($this->request($this->fields(['subject_token' => $this->badSubjectToken($case)])));
    $this->assertError($response, 400, 'invalid_grant');
    $this->assertSame('The subject token is not valid.', $this->body($response)['error_description']);
  }

  public static function subjectTokenCases(): array {
    return [
      'signed with the cookie key' => ['signed with the cookie key'],
      'signed with the xdmod key' => ['signed with the xdmod key'],
      'agent kid, xdmod signature' => ['agent kid, xdmod signature'],
      'an XDMoD token (no chaining)' => ['an XDMoD token (no chaining)'],
      'cookie-shaped JWT' => ['cookie-shaped JWT'],
      'unrelated key' => ['unrelated key'],
      'HS256' => ['HS256'],
      'alg none' => ['alg none'],
      'typ JWT' => ['typ JWT'],
      'wrong aud' => ['wrong aud'],
      'aud as a list' => ['aud as a list'],
      'wrong azp' => ['wrong azp'],
      'azp is the confidential client' => ['azp is the confidential client'],
      'missing azp' => ['missing azp'],
      'wrong iss' => ['wrong iss'],
      'expired' => ['expired'],
      'no exp' => ['no exp'],
      'lifetime over 900' => ['lifetime over 900'],
      'iat in the future' => ['iat in the future'],
      'unknown sub' => ['unknown sub'],
      'bare sub' => ['bare sub'],
      'garbage' => ['garbage'],
      'scope without agent:read' => ['scope without agent:read'],
      'no scope' => ['no scope'],
      'agent:read as a substring' => ['agent:read as a substring'],
    ];
  }

  /**
   * Builds the subject token for one subjectTokenCases() case.
   */
  protected function badSubjectToken(string $case): string {
    $now = time();
    return match ($case) {
      'signed with the cookie key' => $this->agentToken([], [], 'cookie'),
      'signed with the xdmod key' => $this->agentToken([], [], 'xdmod'),
      'agent kid, xdmod signature' => $this->agentToken([], ['kid' => $this->kidFor($this->keys['agent']['private'])], 'xdmod'),
      'an XDMoD token (no chaining)' => $this->agentToken(['aud' => self::XDMOD, 'azp' => 'access-assistant'], [], 'xdmod'),
      'cookie-shaped JWT' => JWT::encode(['iss' => self::ISSUER, 'sub' => 'jsmith@access-ci.org', 'iat' => $now, 'exp' => $now + 3600], $this->keys['cookie']['private'], 'ES256', 'test-kid'),
      'unrelated key' => JWT::encode(['sub' => 'jsmith@access-ci.org'], $this->generateEs256Keypair()['private'], 'ES256', $this->kidFor($this->keys['agent']['private']), ['typ' => 'at+jwt']),
      'HS256' => JWT::encode(['iss' => self::ISSUER, 'sub' => 'jsmith@access-ci.org', 'aud' => self::QA, 'azp' => 'access-qa-bot', 'iat' => $now, 'exp' => $now + 900], $this->keys['agent']['public'], 'HS256', $this->kidFor($this->keys['agent']['private']), ['typ' => 'at+jwt']),
      'alg none' => JWT::urlsafeB64Encode(json_encode(['typ' => 'at+jwt', 'alg' => 'none', 'kid' => $this->kidFor($this->keys['agent']['private'])])) . '.' . JWT::urlsafeB64Encode(json_encode(['sub' => 'jsmith@access-ci.org', 'aud' => self::QA, 'azp' => 'access-qa-bot', 'iss' => self::ISSUER, 'iat' => $now, 'exp' => $now + 900])) . '.',
      'typ JWT' => $this->agentToken([], ['typ' => 'JWT']),
      'wrong aud' => $this->agentToken(['aud' => 'https://other.access-ci.org']),
      'aud as a list' => $this->agentToken(['aud' => [self::QA]]),
      'wrong azp' => $this->agentToken(['azp' => 'other-bot']),
      'azp is the confidential client' => $this->agentToken(['azp' => 'access-assistant']),
      'missing azp' => $this->agentToken(['azp' => NULL]),
      'wrong iss' => $this->agentToken(['iss' => 'https://support.access-ci.org']),
      'expired' => $this->agentToken(['iat' => $now - 1000, 'exp' => $now - 100]),
      'no exp' => $this->agentToken(['exp' => NULL]),
      'lifetime over 900' => $this->agentToken(['iat' => $now, 'exp' => $now + 901]),
      'iat in the future' => $this->agentToken(['iat' => $now + 300, 'exp' => $now + 1000]),
      'unknown sub' => $this->agentToken(['sub' => 'nobody@access-ci.org']),
      'bare sub' => $this->agentToken(['sub' => 'jsmith']),
      'garbage' => 'not.a.jwt',
      'scope without agent:read' => $this->agentToken(['scope' => 'agent:write']),
      'no scope' => $this->agentToken(['scope' => NULL]),
      'agent:read as a substring' => $this->agentToken(['scope' => 'agent:readx']),
    };
  }

  public function testBlockedOrDeletedAccountIsRejected(): void {
    $blocked = $this->createAccessUser('blocked', 'blocked@access-ci.org', 0);
    $response = $this->exchange($this->request($this->fields(['subject_token' => $this->agentToken(['sub' => 'blocked@access-ci.org'])])));
    $this->assertError($response, 400, 'invalid_grant');

    $gone = $this->createAccessUser('gone', 'gone@access-ci.org');
    $token = $this->agentToken(['sub' => 'gone@access-ci.org']);
    $gone->delete();
    $this->assertError($this->exchange($this->request($this->fields(['subject_token' => $token]))), 400, 'invalid_grant');
    $this->assertNotNull($blocked);
  }

  /**
   * A subject client not listed in subject_clients cannot be exchanged.
   */
  public function testSubjectClientMustBeListed(): void {
    $clients = self::clients();
    $clients['other-bot'] = [
      'type' => 'browser',
      'audience' => 'https://qa2.access-ci.org',
      'origins' => [['origin' => 'https://sds.access-ci.org', 'scopes' => ['agent:read']]],
    ];
    $this->configure([], self::ISSUER, $clients);
    $token = $this->agentToken(['aud' => 'https://qa2.access-ci.org', 'azp' => 'other-bot']);
    $this->assertError($this->exchange($this->request($this->fields(['subject_token' => $token]))), 400, 'invalid_grant');
  }

  public function testFloodLimitIsPerClientAndSub(): void {
    for ($i = 0; $i < 60; $i++) {
      $this->assertSame(200, $this->exchange()->getStatusCode(), "Request $i");
    }
    $response = $this->exchange();
    $this->assertError($response, 429, 'slow_down');
    $this->assertSame('3600', $response->headers->get('Retry-After'));

    $this->createAccessUser('other', 'other@access-ci.org');
    $other = $this->request($this->fields(['subject_token' => $this->agentToken(['sub' => 'other@access-ci.org'])]));
    $this->assertSame(200, $this->exchange($other)->getStatusCode());
  }

  /**
   * @dataProvider unavailableCases
   */
  public function testSigningUnavailableIsServerError(array $slots, ?string $issuer): void {
    $this->configure($slots, $issuer);
    $this->assertError($this->exchange(), 500, 'server_error');
  }

  public static function unavailableCases(): array {
    return [
      'no xdmod key' => [['xdmod' => FALSE], self::ISSUER],
      'no agent key to verify with' => [['agent' => FALSE], self::ISSUER],
      'no explicit issuer' => [[], NULL],
      'issuer host mismatch' => [[], 'https://dev-accessmatch.pantheonsite.io'],
    ];
  }

}
