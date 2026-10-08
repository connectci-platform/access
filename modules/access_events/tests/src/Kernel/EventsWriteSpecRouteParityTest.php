<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that the events write OpenAPI spec matches the real write routes.
 *
 * The original documentation gap arose because the spec and
 * EventCrudApiController drifted apart with nothing to notice. This test
 * compares the spec served by the `access_events_write` generator against the
 * routes declared in access_events.routing.yml, so drift in either direction
 * (an undocumented route, or a documented route that does not exist) fails CI.
 *
 * @group access_events
 */
class EventsWriteSpecRouteParityTest extends KernelTestBase {

  /**
   * HTTP method keys that can appear under an OpenAPI path item.
   */
  private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'openapi',
    'access',
    'access_affinitygroup',
    'field',
    'text',
    'filter',
    'key',
  ];

  /**
   * The parsed specification as served by the generator.
   *
   * @var array
   */
  private array $spec;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $stack = \Drupal::requestStack();
    if (!$stack->getCurrentRequest()) {
      $stack->push(Request::create('https://support.access-ci.org/'));
    }
    $generator = \Drupal::service('plugin.manager.openapi.generator')
      ->createInstance('access_events_write');
    $this->spec = $generator->getSpecification();
  }

  /**
   * The spec documents exactly the routes the controller registers.
   */
  public function testSpecPathsMatchRegisteredRoutes(): void {
    $this->assertIsArray($this->spec);
    $this->assertArrayHasKey('paths', $this->spec);
    $this->assertSame(
      '2.3.0',
      $this->spec['info']['version'] ?? NULL,
      'the spec was loaded from the YAML file, not the generator fallback'
    );

    $base_path = '';
    foreach ($this->spec['servers'] ?? [] as $server) {
      if (str_starts_with($server['url'], 'https://support.access-ci.org')) {
        $base_path = (string) parse_url($server['url'], PHP_URL_PATH);
      }
    }
    $this->assertSame('/api/2.3', $base_path, 'production server base path');

    $documented = [];
    foreach ($this->spec['paths'] as $path => $item) {
      foreach (self::HTTP_METHODS as $method) {
        if (isset($item[$method])) {
          $documented[] = strtoupper($method) . ' ' . $base_path . $path;
        }
      }
    }

    $registered = [];
    $module_path = \Drupal::service('extension.list.module')->getPath('access_events');
    $routes = Yaml::parseFile(DRUPAL_ROOT . '/' . $module_path . '/access_events.routing.yml');
    foreach ($routes as $route) {
      $controller = $route['defaults']['_controller'] ?? '';
      if (!str_starts_with($controller, '\Drupal\access_events\Controller\EventCrudApiController::')) {
        continue;
      }
      foreach ($route['methods'] as $method) {
        $registered[] = strtoupper($method) . ' ' . $route['path'];
      }
    }

    sort($documented);
    sort($registered);
    $this->assertCount(9, $registered, 'the route filter found the nine write routes');
    $this->assertSame(
      $registered,
      $documented,
      'spec operations must equal EventCrudApiController routes (expected = routing.yml, actual = spec)'
    );
  }

  /**
   * Create and update bodies document the timezone and in-person fields.
   *
   * @dataProvider bodyProvider
   */
  public function testCreateAndUpdateBodiesDocumentTimezoneFields(string $path, string $method): void {
    $operation = $this->spec['paths'][$path][$method] ?? NULL;
    $this->assertNotNull($operation, "$method $path is documented");
    $schema = $operation['requestBody']['content']['application/json']['schema'] ?? NULL;
    $this->assertNotNull($schema, "$method $path documents a JSON request body");

    $properties = $this->schemaProperties($schema);
    $this->assertArrayHasKey('field_event_timezone', $properties);
    $this->assertArrayHasKey('field_event_in_person', $properties);
  }

  /**
   * Data provider for the request bodies that accept event fields.
   */
  public static function bodyProvider(): array {
    return [
      'create' => ['/events', 'post'],
      'update' => ['/event-series/{eventseries}', 'patch'],
    ];
  }

  /**
   * Every operation requires the acting-user header and a CSRF token.
   */
  public function testEveryOperationRequiresActingUserAndCsrf(): void {
    $schemes = $this->spec['components']['securitySchemes'] ?? [];
    $count = 0;
    foreach ($this->spec['paths'] as $path => $item) {
      foreach (self::HTTP_METHODS as $method) {
        if (!isset($item[$method])) {
          continue;
        }
        $count++;
        $security = $item[$method]['security'] ?? $this->spec['security'] ?? [];
        $satisfied = FALSE;
        foreach ($security as $requirement) {
          $names = [];
          foreach (array_keys($requirement) as $scheme_key) {
            $scheme = $schemes[$scheme_key] ?? [];
            if (($scheme['type'] ?? '') === 'apiKey' && ($scheme['in'] ?? '') === 'header') {
              $names[] = $scheme['name'] ?? '';
            }
          }
          if (in_array('X-Acting-User', $names, TRUE) && in_array('X-CSRF-Token', $names, TRUE)) {
            $satisfied = TRUE;
          }
        }
        $this->assertTrue($satisfied, strtoupper($method) . " $path requires X-Acting-User and X-CSRF-Token");
      }
    }
    $this->assertGreaterThan(0, $count, 'at least one operation was checked');
  }

  /**
   * Collects a schema's properties, following $ref and allOf.
   */
  private function schemaProperties(array $schema): array {
    if (isset($schema['$ref'])) {
      $schema = $this->resolveRef($schema['$ref']);
    }
    $properties = $schema['properties'] ?? [];
    foreach ($schema['allOf'] ?? [] as $member) {
      $properties += $this->schemaProperties($member);
    }
    return $properties;
  }

  /**
   * Resolves a local '#/components/schemas/X' style reference.
   */
  private function resolveRef(string $ref): array {
    $this->assertStringStartsWith('#/', $ref, 'only local references are supported');
    $node = $this->spec;
    foreach (explode('/', substr($ref, 2)) as $segment) {
      $this->assertArrayHasKey($segment, $node, "unresolvable reference $ref");
      $node = $node[$segment];
    }
    return $node;
  }

}
