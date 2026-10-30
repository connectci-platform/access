<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that Redirect's route normalizer 301s to the canonical alias.
 *
 * Core 11.3 dropped the alias preload cache, so the inbound alias lookup made
 * by routing poisoned the outbound lookup and requests for a non-canonical
 * alias were served with a 200. The canonical alias is the one core's
 * AliasRepository::lookupBySystemPath() returns: language-specific ('en')
 * before language-neutral ('und'), then the highest id.
 *
 * @group access_misc
 */
class CanonicalAliasNormalizerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'link',
    'path_alias',
    'path',
    'redirect',
    // Container dependencies of access_misc's services.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('redirect');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'node', 'filter']);

    // access_misc_page_attachments() reads this table on every rendered page;
    // a bare table stands in for the taxonomy field it queries.
    $this->container->get('database')->schema()->createTable('taxonomy_term__field_region_connected_domain', [
      'fields' => [
        'entity_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'field_region_connected_domain_value' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
      ],
    ]);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->config('redirect.settings')
      ->set('route_normalizer_enabled', TRUE)
      ->set('default_status_code', 301)
      ->save();

    // Requests run as the anonymous user, who needs to view content.
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
  }

  /**
   * Creates a published node.
   */
  protected function createNode(): Node {
    $node = Node::create(['type' => 'page', 'title' => 'Test', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Creates an alias for a node.
   */
  protected function createAlias(Node $node, string $alias, string $langcode): void {
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => $alias,
      'langcode' => $langcode,
    ])->save();
  }

  /**
   * Handles a GET request through the HTTP kernel.
   */
  protected function get(string $uri): Response {
    // Redirect only normalizes requests served through index.php.
    $request = Request::create($uri, 'GET', [], [], [], [
      'SCRIPT_NAME' => '/index.php',
      'SCRIPT_FILENAME' => 'index.php',
    ]);
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Creates the node with a canonical and an older non-canonical alias.
   */
  protected function createNodeWithTwoAliases(): Node {
    $node = $this->createNode();
    // The older alias has the lower id and language-neutral langcode, as in
    // production; the canonical one is language-specific.
    $this->createAlias($node, '/knowledge-base', 'und');
    $this->createAlias($node, '/knowledge-base/overview', 'en');
    return $node;
  }

  /**
   * A non-canonical alias redirects to the canonical alias.
   */
  public function testNonCanonicalAliasRedirects(): void {
    $this->createNodeWithTwoAliases();
    $response = $this->get('/knowledge-base');
    $this->assertSame(301, $response->getStatusCode());
    $this->assertStringEndsWith('/knowledge-base/overview', $response->headers->get('Location'));
  }

  /**
   * The canonical alias is served without a redirect.
   */
  public function testCanonicalAliasIsServed(): void {
    $this->createNodeWithTwoAliases();
    $response = $this->get('/knowledge-base/overview');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertFalse($response->headers->has('Location'));
  }

  /**
   * A node with a single alias is served at that alias.
   */
  public function testSingleAliasIsServed(): void {
    $node = $this->createNode();
    $this->createAlias($node, '/only-alias', 'en');
    $response = $this->get('/only-alias');
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * The query string survives the redirect.
   */
  public function testQueryStringIsKept(): void {
    $this->createNodeWithTwoAliases();
    $response = $this->get('/knowledge-base?foo=bar');
    $this->assertSame(301, $response->getStatusCode());
    $location = $response->headers->get('Location');
    $this->assertStringContainsString('/knowledge-base/overview', $location);
    $this->assertStringEndsWith('?foo=bar', $location);
  }

}
