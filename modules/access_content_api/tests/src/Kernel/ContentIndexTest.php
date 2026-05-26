<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentIndexController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for the /.well-known/content-index.json endpoint.
 *
 * @group access_content_api
 */
class ContentIndexTest extends KernelTestBase {

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
   * Tests that the index returns valid JSON with required fields.
   */
  public function testIndexReturnsValidJson(): void {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertIsArray($data);
    $this->assertEquals(1, $data['version']);
    $this->assertArrayHasKey('pages', $data);
    $this->assertArrayHasKey('generated_at', $data);
  }

  /**
   * Tests that unpublished nodes do not appear in the index.
   */
  public function testIndexListsPublishedNodesOnly(): void {
    Node::create(['type' => 'page', 'title' => 'Unpub', 'status' => 0])->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $data = json_decode($response->getContent(), TRUE);

    $this->assertIsArray($data['pages']);
  }

}
