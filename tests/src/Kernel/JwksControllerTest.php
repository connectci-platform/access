<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\Controller\JwksController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access\Fixtures\TestJwtKeyProvider;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Covers the cookie and exchange JWKS documents.
 *
 * @group access
 */
class JwksControllerTest extends KernelTestBase {

  use TokenKeysTestTrait;

  protected static $modules = ['access', 'access_affinitygroup', 'user', 'system', 'field', 'text', 'filter', 'key'];

  /**
   * Keypairs keyed by purpose.
   *
   * @var array
   */
  protected array $keys;

  protected function setUp(): void {
    parent::setUp();
    foreach (['cookie', 'agent', 'xdmod'] as $purpose) {
      $this->keys[$purpose] = $this->generateEs256Keypair();
    }
  }

  protected function controller(?string $cookie = NULL, array $slots = [], array $secrets = []): JwksController {
    $slots += ['agent' => $this->keys['agent']['private'], 'xdmod' => $this->keys['xdmod']['private']];
    return new JwksController(new TestJwtKeyProvider(
      $cookie ?? $this->keys['cookie']['private'],
      array_filter($slots, 'is_string'),
      'https://test.access-ci.org',
      $secrets
    ));
  }

  /**
   * The kids in a JWKS response, asserting it is a well-formed 200.
   */
  protected function kids(JsonResponse $response): array {
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
    $body = json_decode($response->getContent(), TRUE);
    foreach ($body['keys'] as $jwk) {
      $this->assertSame('EC', $jwk['kty']);
      $this->assertSame('P-256', $jwk['crv']);
      $this->assertSame('ES256', $jwk['alg']);
      $this->assertSame('sig', $jwk['use']);
      $this->assertSame(32, strlen(base64_decode(strtr($jwk['x'], '-_', '+/'))));
      $this->assertSame(32, strlen(base64_decode(strtr($jwk['y'], '-_', '+/'))));
    }
    return array_column($body['keys'], 'kid');
  }

  public function testCookieDocumentHoldsOnlyTheCookieKey(): void {
    $this->assertSame(['test-kid'], $this->kids($this->controller()->jwks()));
  }

  public function testExchangeDocumentHoldsAgentAndXdmodKeysOnly(): void {
    $kids = $this->kids($this->controller()->exchangeJwks());
    $this->assertSame([
      $this->kidFor($this->keys['agent']['private']),
      $this->kidFor($this->keys['xdmod']['private']),
    ], $kids);
    $this->assertNotContains($this->kidFor($this->keys['cookie']['private']), $kids);
    $this->assertNotContains('test-kid', $kids);
  }

  public function testExchangeDocumentIncludesVerifyOnlyKeys(): void {
    $next = $this->generateEs256Keypair();
    $kids = $this->kids($this->controller(NULL, [], [
      'access_jwt_exchange_jwks_extra_public_keys' => [$next['public']],
    ])->exchangeJwks());
    $this->assertContains($this->kidFor($next['public']), $kids);
    $this->assertCount(3, $kids);
  }

  /**
   * A verify-only copy of an active key is listed once.
   */
  public function testDuplicateKidsAreListedOnce(): void {
    $kids = $this->kids($this->controller(NULL, [], [
      'access_jwt_exchange_jwks_extra_public_keys' => [$this->keys['agent']['public']],
    ])->exchangeJwks());
    $this->assertCount(2, $kids);
  }

  public function testUnconfiguredSlotIsLeftOut(): void {
    $kids = $this->kids($this->controller(NULL, ['xdmod' => FALSE])->exchangeJwks());
    $this->assertSame([$this->kidFor($this->keys['agent']['private'])], $kids);
  }

  public function testNothingConfiguredIsAnEmptySet(): void {
    $response = $this->controller(NULL, ['agent' => FALSE, 'xdmod' => FALSE])->exchangeJwks();
    $this->assertSame([], $this->kids($response));
  }

  /**
   * A configured key that fails makes the whole document 503.
   *
   * @dataProvider brokenExchangeCases
   */
  public function testBrokenKeyMakesTheExchangeDocument503(array $slots, array $secrets): void {
    $controller = $this->controller(NULL, $slots, $secrets);
    $response = $controller->exchangeJwks();
    $this->assertSame(503, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertStringNotContainsString('"keys"', $response->getContent(), 'No subset is served.');
    // The other document is unaffected.
    $this->assertSame(['test-kid'], $this->kids($controller->jwks()));
  }

  public static function brokenExchangeCases(): array {
    return [
      'bad agent PEM' => [['agent' => 'garbage'], []],
      'bad verify-only key' => [[], ['access_jwt_exchange_jwks_extra_public_keys' => ['garbage']]],
    ];
  }

  public function testSameKeyInTwoSlotsMakesTheExchangeDocument503(): void {
    $response = $this->controller(NULL, ['agent' => $this->keys['xdmod']['private']])->exchangeJwks();
    $this->assertSame(503, $response->getStatusCode());
  }

  public function testBrokenCookieKeyMakesTheCookieDocument503(): void {
    $controller = $this->controller('garbage');
    $response = $controller->jwks();
    $this->assertSame(503, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertCount(2, $this->kids($controller->exchangeJwks()));
  }

}
