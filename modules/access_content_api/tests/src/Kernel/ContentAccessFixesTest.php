<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentController;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\path_alias\Entity\PathAlias;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the all-affiliates and cache-context review fixes.
 *
 * Node-access-grant enforcement (finding 1) is covered separately in
 * ContentNodeAccessTest, which enables node_access_test; that module alters
 * grants globally and would deny the otherwise-public nodes used here.
 *
 * @group access_content_api
 */
class ContentAccessFixesTest extends ContentApiKernelTestBase {

  /**
   * Finding 6: a page flagged "all affiliates" is served by the API.
   *
   * All-affiliates pages are site-wide public (domain_site grant), so the API
   * must treat them as in-scope even without an explicit support-domain tag.
   */
  public function testAllAffiliatesPageIsServed(): void {
    $node = $this->createPage([
      'field_domain_access' => [],
      'field_domain_all_affiliates' => 1,
    ]);
    // Grants must be written for the domain_site grant to take effect.
    node_access_rebuild();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * The per-id response exposes a content_hash derived from the text.
   *
   * Lets a RAG ingester skip re-embedding pages whose text is unchanged.
   */
  public function testContentHashReflectsText(): void {
    $node = $this->createPage(['body' => ['value' => '<p>Hash me</p>', 'format' => 'basic_html']]);
    node_access_rebuild();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $data = json_decode($controller->byId($request, (int) $node->id())->getContent(), TRUE);

    $this->assertArrayHasKey('content_hash', $data);
    $this->assertSame(hash('sha256', $data['text']), $data['content_hash']);
  }

  /**
   * The per-id response exposes an absolute path URL for RAG citation.
   */
  public function testContentPathIsAbsoluteUrl(): void {
    $node = $this->createPage();
    node_access_rebuild();
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/absolute-url-page',
    ])->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(
      'https://support.access-ci.org/absolute-url-page',
      $data['path']
    );
  }

  /**
   * Finding 3: byPath responses must vary by the ?path query argument.
   *
   * The response carries a cache context for the path query arg, so the
   * Dynamic Page Cache cannot serve one path's content for another.
   */
  public function testByPathVariesCacheByPathQueryArg(): void {
    $node = $this->createPage();
    node_access_rebuild();
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/cache-context-page',
    ])->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content', 'GET', ['path' => '/cache-context-page']);
    $response = $controller->byPath($request);

    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $contexts = $response->getCacheableMetadata()->getCacheContexts();
    $this->assertContains('url.query_args:path', $contexts);
  }

}
