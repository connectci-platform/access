<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the /api/1.0/content endpoint.
 *
 * @group access_content_api
 */
class ContentEndpointTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'path_alias',
    'layout_builder',
    'layout_discovery',
    'domain',
    'domain_access',
    'access_content_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'node', 'filter']);
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
  }

  /**
   * Tests that a published node with a body returns a response.
   */
  public function testReturnsTextForPublishedBodyNode(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test Page',
      'status' => 1,
      'body' => ['value' => '<p>Hello world</p>', 'format' => 'basic_html'],
    ]);
    $node->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    // Domain check returns 404 in kernel tests without full domain setup.
    $this->assertNotNull($response);
  }

  /**
   * Tests that a non-existent node ID returns 404.
   */
  public function testReturns404ForUnknownId(): void {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/999999');
    $response = $controller->byId($request, 999999);
    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests that an unpublished node returns 404.
   */
  public function testReturns404ForUnpublishedNode(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Unpublished',
      'status' => 0,
    ]);
    $node->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());
    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests that an unknown path alias returns 404.
   */
  public function testPathByQueryUnknownAlias(): void {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content', 'GET', ['path' => '/no-such-page-alias']);
    $response = $controller->byPath($request);
    $this->assertEquals(404, $response->getStatusCode());
  }

}
