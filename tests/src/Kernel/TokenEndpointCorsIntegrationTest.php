<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token endpoints' CORS headers, through the full kernel.
 *
 * The site applies a wildcard CORS policy to /api/* through advanced_cors.
 * This sends real requests with that policy enabled, so the subscriber
 * priorities are exercised rather than simulated.
 *
 * @group access
 */
class TokenEndpointCorsIntegrationTest extends KernelTestBase {

  protected static $modules = [
    'access',
    'access_affinitygroup',
    'user',
    'system',
    'field',
    'text',
    'filter',
    'key',
    'path_alias',
    'advanced_cors',
  ];

  const ORIGIN = 'https://support.access-ci.org';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['user']);

    // The portal's "apis" policy.
    \Drupal::entityTypeManager()->getStorage('route_config')->create([
      'id' => 'apis',
      'label' => 'APIs',
      'weight' => 0,
      'patterns' => "/api/*\r\n",
      'allowed_headers' => '*',
      'allowed_methods' => '*',
      'allowed_origins' => '*',
      'exposed_headers' => '',
      'max_age' => '',
      'supports_credentials' => 'true',
    ])->save();

    \Drupal::configFactory()->getEditable('access.token_exchange')->set('clients', [
      'access-qa-bot' => [
        'type' => 'browser',
        'audience' => 'https://qa.access-ci.org',
        'origins' => [['origin' => self::ORIGIN, 'scopes' => ['agent:read']]],
      ],
    ])->save();
  }

  protected function send(string $path, string $method, ?string $origin): Response {
    $server = $origin === NULL ? [] : ['HTTP_ORIGIN' => $origin];
    $request = Request::create('https://support.access-ci.org' . $path, $method, [], [], [], $server);
    return \Drupal::service('http_kernel')->handle($request);
  }

  protected function cors(Response $response): array {
    $out = [];
    foreach ($response->headers->all() as $name => $values) {
      if (str_starts_with($name, 'access-control-')) {
        $out[$name] = $values[0];
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * The site-wide policy really does apply to other /api/* paths.
   *
   * This is the control: without it, the assertions below could pass simply
   * because advanced_cors never ran.
   */
  public function testControlOtherApiPathsGetTheWildcard(): void {
    $response = $this->send('/api/does-not-exist', 'GET', self::ORIGIN);
    $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
  }

  public function testRegisteredOriginIsEchoed(): void {
    // Anonymous, so the endpoint answers login_required with CORS headers.
    $response = $this->send('/api/agent-token', 'POST', self::ORIGIN);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame([
      'access-control-allow-credentials' => 'true',
      'access-control-allow-origin' => self::ORIGIN,
    ], $this->cors($response));
  }

  public function testUnregisteredOriginGetsNoCors(): void {
    $response = $this->send('/api/agent-token', 'POST', 'https://evil.example.org');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame([], $this->cors($response));
  }

  public function testPreflight(): void {
    $response = $this->send('/api/agent-token', 'OPTIONS', self::ORIGIN);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame([
      'access-control-allow-credentials' => 'true',
      'access-control-allow-headers' => 'Content-Type',
      'access-control-allow-methods' => 'POST',
      'access-control-allow-origin' => self::ORIGIN,
      'access-control-max-age' => '600',
    ], $this->cors($response));
  }

  public function testTokenExchangeGetsNoCors(): void {
    $response = $this->send('/api/token-exchange', 'POST', self::ORIGIN);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame([], $this->cors($response));
  }

  /**
   * The exchange is server-to-server: a preflight gets no CORS headers either.
   */
  public function testTokenExchangePreflightGetsNoCors(): void {
    $response = $this->send('/api/token-exchange', 'OPTIONS', self::ORIGIN);
    $this->assertSame([], $this->cors($response));
  }

}
