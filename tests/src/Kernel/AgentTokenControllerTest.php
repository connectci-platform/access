<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\Controller\AgentTokenController;
use Drupal\access\EventSubscriber\TokenEndpointCorsSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Covers POST /api/agent-token, the browser grant.
 *
 * @group access
 */
class AgentTokenControllerTest extends TokenEndpointTestBase {

  protected function controller(bool $seamless = FALSE): AgentTokenController {
    $args = [
      $this->registry,
      $this->issuer,
      $this->currentUser,
      \Drupal::service('access.access_id_resolver'),
      \Drupal::flood(),
      $this->logger,
      \Drupal::state(),
      \Drupal::moduleHandler(),
    ];
    if (!$seamless) {
      return new AgentTokenController(...$args);
    }
    return new class(...$args) extends AgentTokenController {

      protected function seamlessRequiresSsoCookie(): bool {
        return TRUE;
      }

    };
  }

  protected function request(?string $origin = 'https://support.access-ci.org', string $method = 'POST', array $headers = [], array $cookies = []): Request {
    $server = [];
    if ($origin !== NULL) {
      $server['HTTP_ORIGIN'] = $origin;
    }
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    return Request::create('https://' . self::HOST . '/api/agent-token', $method, [], $cookies, [], $server);
  }

  protected function mint(?Request $request = NULL, bool $seamless = FALSE): Response {
    return $this->controller($seamless)->mint($request ?? $this->request());
  }

  protected function assertError(Response $response, int $status, string $error): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame($error, $this->body($response)['error']);
    $this->assertArrayHasKey('error_description', $this->body($response));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
  }

  protected function assertCors(Response $response, string $origin): void {
    $this->assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
    $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    $this->assertContains('Origin', $response->getVary());
  }

  protected function assertNoCors(Response $response): void {
    foreach (array_keys($response->headers->all()) as $name) {
      $this->assertStringStartsNotWith('access-control-', $name);
    }
  }

  public function testSignedInUserGetsAnAgentToken(): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $response = $this->mint();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertCors($response, 'https://support.access-ci.org');
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $body = $this->body($response);
    $this->assertSame('Bearer', $body['token_type']);
    $this->assertSame(900, $body['expires_in']);

    [$header, $claims] = $this->parts($body['access_token']);
    $this->assertSame([
      'typ' => 'at+jwt',
      'alg' => 'ES256',
      'kid' => $this->kidFor($this->keys['agent']['private']),
    ], $header);
    $this->assertSame(['iss', 'sub', 'aud', 'azp', 'scope', 'iat', 'exp', 'jti'], array_keys($claims));
    $this->assertSame(self::ISSUER, $claims['iss']);
    $this->assertSame('jsmith@access-ci.org', $claims['sub']);
    $this->assertSame(self::QA, $claims['aud']);
    $this->assertSame('access-qa-bot', $claims['azp']);
    $this->assertSame('agent:read agent:write', $claims['scope']);
    $this->assertSame(900, $claims['exp'] - $claims['iat']);
    $this->assertArrayNotHasKey('nbf', $claims);
    $this->assertSame(16, strlen(base64_decode(strtr($claims['jti'], '-_', '+/'))));

    // Verifies with the agent key, and with no other key.
    $this->assertSame('jsmith@access-ci.org', $this->verifyWith('agent', $body['access_token'])->sub);
    foreach (['cookie', 'xdmod'] as $other) {
      try {
        $this->verifyWith($other, $body['access_token']);
        $this->fail("The agent token verified with the $other key.");
      }
      catch (\Exception $e) {
        $this->assertStringContainsString('Signature verification failed', $e->getMessage());
      }
    }

    $this->assertNotLogged($body['access_token']);
    $this->assertStringContainsString('jti ' . $claims['jti'], $this->logger->getRecords()[0]['message']);
  }

  public function testScopeIsPerOrigin(): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $response = $this->mint($this->request('https://ara.access-ci.org'));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertCors($response, 'https://ara.access-ci.org');
    [, $claims] = $this->parts($this->body($response)['access_token']);
    $this->assertSame('agent:read', $claims['scope']);
  }

  /**
   * @dataProvider badOriginCases
   */
  public function testBadOriginIsRefusedWithoutCors(?string $origin): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $response = $this->mint($this->request($origin));
    $this->assertError($response, 403, 'invalid_origin');
    $this->assertNoCors($response);
  }

  public static function badOriginCases(): array {
    return [
      'missing' => [NULL],
      'null' => ['null'],
      'unregistered' => ['https://evil.example.org'],
      'lookalike' => ['https://support.access-ci.org.evil.example.org'],
      'http' => ['http://support.access-ci.org'],
      'trailing slash' => ['https://support.access-ci.org/'],
    ];
  }

  public function testCrossSiteFetchIsRefused(): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $response = $this->mint($this->request('https://support.access-ci.org', 'POST', ['Sec-Fetch-Site' => 'cross-site']));
    $this->assertError($response, 403, 'invalid_origin');
    $this->assertCors($response, 'https://support.access-ci.org');

    $ok = $this->mint($this->request('https://allocations.access-ci.org', 'POST', ['Sec-Fetch-Site' => 'same-site']));
    $this->assertSame(200, $ok->getStatusCode());
  }

  public function testPreflight(): void {
    $response = $this->controller()->preflight($this->request('https://allocations.access-ci.org', 'OPTIONS'));
    $this->assertSame(204, $response->getStatusCode());
    $this->assertCors($response, 'https://allocations.access-ci.org');
    $this->assertSame('POST', $response->headers->get('Access-Control-Allow-Methods'));
    $this->assertSame('Content-Type', $response->headers->get('Access-Control-Allow-Headers'));
    $this->assertSame('600', $response->headers->get('Access-Control-Max-Age'));

    $refused = $this->controller()->preflight($this->request('https://evil.example.org', 'OPTIONS'));
    $this->assertSame(403, $refused->getStatusCode());
    $this->assertNoCors($refused);
  }

  /**
   * OPTIONS reaching the controller itself also answers the preflight.
   */
  public function testOptionsThroughMint(): void {
    $this->assertSame(204, $this->mint($this->request('https://support.access-ci.org', 'OPTIONS'))->getStatusCode());
  }

  public function testOtherMethodsAre405(): void {
    foreach (['GET', 'PUT', 'DELETE'] as $method) {
      $response = $this->mint($this->request('https://support.access-ci.org', $method));
      $this->assertError($response, 405, 'invalid_request');
      $this->assertCors($response, 'https://support.access-ci.org');
    }
  }

  /**
   * Every session state that is not a usable sign-in gets login_required.
   */
  public function testLoginRequired(): void {
    // Anonymous, even carrying the SSO cookie: JSON, never a redirect.
    $request = $this->request('https://support.access-ci.org', 'POST', [], ['SESSaccesscisso' => '1']);
    $response = $this->mint($request);
    $this->assertError($response, 401, 'login_required');
    $this->assertCors($response, 'https://support.access-ci.org');

    // No ACCESS ID authmap row.
    $this->signIn($this->createAccessUser('noauthmap', NULL));
    $this->assertError($this->mint(), 401, 'login_required');

    // An opaque CILogon sub is not an ACCESS ID.
    $this->signIn($this->createAccessUser('opaque', 'http://cilogon.org/serverA/users/1'));
    $this->assertError($this->mint(), 401, 'login_required');

    // Blocked account with a live session.
    $this->signIn($this->createAccessUser('blocked', 'blocked@access-ci.org', 0));
    $this->assertError($this->mint(), 401, 'login_required');
  }

  public function testSeamlessRequiresTheSsoCookie(): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $this->assertError($this->mint(NULL, TRUE), 401, 'login_required');

    $with_cookie = $this->request('https://support.access-ci.org', 'POST', [], ['SESSaccesscisso' => '1']);
    $this->assertSame(200, $this->mint($with_cookie, TRUE)->getStatusCode());
  }

  /**
   * @dataProvider unavailableCases
   */
  public function testSigningUnavailableIsServerError(array $slots, ?string $issuer): void {
    $this->configure($slots, $issuer);
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $response = $this->mint();
    $this->assertError($response, 500, 'server_error');
    $this->assertCors($response, 'https://support.access-ci.org');
  }

  public static function unavailableCases(): array {
    return [
      'no agent key' => [['agent' => FALSE], self::ISSUER],
      'broken agent key' => [['agent' => 'garbage'], self::ISSUER],
      'no explicit issuer' => [[], NULL],
      'issuer host mismatch (cloned multidev)' => [[], 'https://dev-accessmatch.pantheonsite.io'],
    ];
  }

  public function testFloodLimitIsPerUser(): void {
    $user = $this->createAccessUser('jsmith', 'jsmith@access-ci.org');
    $this->signIn($user);
    for ($i = 0; $i < 120; $i++) {
      $this->assertSame(200, $this->mint()->getStatusCode(), "Request $i");
    }
    $response = $this->mint();
    $this->assertError($response, 429, 'slow_down');
    $this->assertSame('3600', $response->headers->get('Retry-After'));
    $this->assertCors($response, 'https://support.access-ci.org');

    // Another user, same IP, is unaffected.
    $this->signIn($this->createAccessUser('other', 'other@access-ci.org'));
    $this->assertSame(200, $this->mint()->getStatusCode());
  }

  /**
   * Refused requests do not count against the user's limit.
   */
  public function testRefusedRequestsAreNotCounted(): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    for ($i = 0; $i < 130; $i++) {
      $this->mint($this->request('https://evil.example.org'));
    }
    $this->assertSame(200, $this->mint()->getStatusCode());
  }

  /**
   * A request the server cannot sign for does not spend the user's allowance.
   *
   * For example a request reaching the endpoint on another domain's hostname,
   * which fails the issuer host guard.
   */
  public function testServerErrorsAreNotCounted(): void {
    $user = $this->createAccessUser('jsmith', 'jsmith@access-ci.org');
    $this->configure([], 'https://dev-accessmatch.pantheonsite.io');
    $this->signIn($user);
    for ($i = 0; $i < 130; $i++) {
      $this->assertSame(500, $this->mint()->getStatusCode());
    }
    $this->configure();
    $this->signIn($user);
    $this->assertSame(200, $this->mint()->getStatusCode());
  }

  public function testRegistryValidationStopsBadScopes(): void {
    $clients = self::clients();
    $clients['access-qa-bot']['origins'][0]['scopes'] = ['agent:read', 'xdmod:read'];
    $this->configure([], self::ISSUER, $clients);
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    // The whole client fails closed, so its origins are unregistered.
    $this->assertError($this->mint(), 403, 'invalid_origin');
  }

  /**
   * The container wiring: routes, services and the preflight subscriber.
   */
  public function testWiring(): void {
    $router = \Drupal::service('router.route_provider');
    $this->assertSame('/api/agent-token', $router->getRouteByName('access.agent_token')->getPath());
    $this->assertSame('/api/token-exchange', $router->getRouteByName('access.token_exchange')->getPath());
    $this->assertSame('/.well-known/exchange-jwks.json', $router->getRouteByName('access.exchange_jwks')->getPath());
    foreach (['access.agent_token', 'access.token_exchange', 'access.jwks', 'access.exchange_jwks'] as $name) {
      $this->assertTrue($router->getRouteByName($name)->getOption('no_cache'), $name);
    }
    $this->assertInstanceOf(AgentTokenController::class, \Drupal::service('access.agent_token_controller'));
    $this->assertInstanceOf(\Drupal\access\Controller\TokenExchangeController::class, \Drupal::service('access.token_exchange_controller'));

    // The preflight subscriber must run before core's OPTIONS handler.
    $this->assertGreaterThan(
      \Drupal\Core\EventSubscriber\OptionsRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][0][1],
      TokenEndpointCorsSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][0][1]
    );
  }

  public function testPreflightSubscriber(): void {
    $subscriber = new TokenEndpointCorsSubscriber($this->controller());
    $event = new RequestEvent(\Drupal::service('http_kernel'), $this->request('https://qa.access-ci.org', 'OPTIONS'), HttpKernelInterface::MAIN_REQUEST);
    $subscriber->onRequest($event);
    $this->assertSame(204, $event->getResponse()->getStatusCode());
    $this->assertSame('https://qa.access-ci.org', $event->getResponse()->headers->get('Access-Control-Allow-Origin'));

    foreach ([['/api/agent-token', 'POST'], ['/api/other', 'OPTIONS']] as [$path, $method]) {
      $request = Request::create('https://' . self::HOST . $path, $method, [], [], [], ['HTTP_ORIGIN' => 'https://qa.access-ci.org']);
      $event = new RequestEvent(\Drupal::service('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
      $subscriber->onRequest($event);
      $this->assertNull($event->getResponse(), "$method $path is left alone.");
    }
  }

  /**
   * A site-wide /api/* CORS policy cannot change the endpoint's headers.
   *
   * @dataProvider corsGuardCases
   */
  public function testSiteWideCorsCannotOverrideTheEndpoint(string $path, ?string $origin, array $expected): void {
    $this->signIn($this->createAccessUser('jsmith', 'jsmith@access-ci.org'));
    $request = Request::create('https://' . self::HOST . $path, 'POST', [], [], [], $origin ? ['HTTP_ORIGIN' => $origin] : []);
    $response = $path === '/api/agent-token' ? $this->controller()->mint($request) : new Response('{}', 400);
    $event = new ResponseEvent(\Drupal::service('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $subscriber = new TokenEndpointCorsSubscriber($this->controller());
    $subscriber->recordCors($event);
    // What the advanced_cors "apis" route config does to every /api/* response.
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Access-Control-Allow-Methods', '*');
    $response->headers->set('Access-Control-Allow-Headers', '*');
    $subscriber->restoreCors($event);

    $cors = array_filter($response->headers->all(), fn($n) => str_starts_with($n, 'access-control-'), ARRAY_FILTER_USE_KEY);
    $this->assertSame($expected, array_map(fn($v) => $v[0], $cors));
  }

  public static function corsGuardCases(): array {
    $echo = [
      'access-control-allow-origin' => 'https://support.access-ci.org',
      'access-control-allow-credentials' => 'true',
    ];
    return [
      'registered origin keeps its echo' => ['/api/agent-token', 'https://support.access-ci.org', $echo],
      'unregistered origin gets none' => ['/api/agent-token', 'https://evil.example.org', []],
      'token exchange gets none' => ['/api/token-exchange', 'https://support.access-ci.org', []],
    ];
  }

}
