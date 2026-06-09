<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentController;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\domain\Entity\Domain;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Shared kernel-test base for access_content_api tests.
 *
 * Installs the full baseline needed by both ContentEndpointTest and
 * ContentIndexTest: entity schemas, domain + domain_access config, a
 * "page" node type with body and field_domain_access, a basic_html filter
 * format, the "text" view mode/display, and the support domain entity.
 */
abstract class ContentApiKernelTestBase extends KernelTestBase {

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
    $this->installConfig(['system', 'node', 'filter', 'domain', 'domain_access']);
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();

    // Add the body field to the page bundle.
    node_add_body_field(NodeType::load('page'));

    // Domain Access fields are created by the module's install hook, which
    // kernel tests do not run automatically.
    \Drupal::moduleHandler()->loadInclude('domain_access', 'install');
    domain_access_install();

    // A permissive text format so body HTML passes through to the extractor
    // unescaped (no filters enabled).
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
    ])->save();

    // The text view mode + display the API renders nodes against. The module's
    // optional config installs the view mode on enable, but the display config
    // is skipped because the body field does not yet exist at that point.
    if (!EntityViewMode::load('node.text')) {
      EntityViewMode::create([
        'id' => 'node.text',
        'targetEntityType' => 'node',
        'label' => 'Text',
      ])->save();
    }
    $display = EntityViewDisplay::load('node.page.text') ?: EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'text',
      'status' => TRUE,
    ]);
    $display->setComponent('body', ['type' => 'text_default', 'label' => 'hidden']);
    $display->save();

    // The support domain the controller filters on.
    Domain::create([
      'id' => ContentController::SUPPORT_DOMAIN_ID,
      'hostname' => 'amp.cyberinfrastructure.org',
      'name' => 'Support',
      'scheme' => 'https',
      'status' => 1,
    ])->save();
  }

  /**
   * Creates and saves a "page" node, returning the saved entity.
   *
   * @param array $overrides
   *   Values that override the defaults.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved node.
   */
  protected function createPage(array $overrides = []): Node {
    $values = $overrides + [
      'type' => 'page',
      'title' => 'Test Page',
      'status' => 1,
      'body' => ['value' => '<p>Hello world</p>', 'format' => 'basic_html'],
      'field_domain_access' => [['target_id' => ContentController::SUPPORT_DOMAIN_ID]],
    ];
    $node = Node::create($values);
    $node->save();
    return $node;
  }

}
