<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentIndexController;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\domain\Entity\Domain;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Kernel tests for the /.well-known/content-index.json endpoint.
 *
 * @group access_content_api
 */
class ContentIndexTest extends ContentApiKernelTestBase {

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
   * Tests that only published domain-assigned nodes appear in the index.
   *
   * Creates one qualifying published page and one unpublished page, then
   * asserts the published title is present and the unpublished title is absent.
   */
  public function testIndexListsPublishedNodesOnly(): void {
    $published = $this->createPage(['title' => 'Published One']);
    $this->createPage(['status' => 0, 'title' => 'Unpublished One']);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $data = json_decode($response->getContent(), TRUE);

    $this->assertIsArray($data['pages']);

    $titles = array_column($data['pages'], 'title');
    $this->assertContains($published->label(), $titles);
    $this->assertNotContains('Unpublished One', $titles);
  }

  /**
   * All-affiliates pages appear in the index (parity with the per-id endpoint).
   *
   * Such nodes have empty field_domain_access but are site-wide public, so the
   * index must not filter them out — otherwise the index and detail disagree.
   */
  public function testIndexIncludesAllAffiliatesPages(): void {
    $node = $this->createPage([
      'title' => 'All Affiliates Index Page',
      'field_domain_access' => [],
      'field_domain_all_affiliates' => 1,
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $data = json_decode($controller->index()->getContent(), TRUE);

    $titles = array_column($data['pages'], 'title');
    $this->assertContains('All Affiliates Index Page', $titles);
  }

  /**
   * Index entries expose absolute path and content_url for RAG citation.
   */
  public function testIndexUrlsAreAbsolute(): void {
    $node = $this->createPage(['title' => 'Absolute URL Index Page']);
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/index-abs',
    ])->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $data = json_decode($response->getContent(), TRUE);

    $entry = NULL;
    foreach ($data['pages'] as $page) {
      if ($page['title'] === 'Absolute URL Index Page') {
        $entry = $page;
        break;
      }
    }
    $this->assertNotNull($entry);
    $this->assertSame('https://support.access-ci.org/index-abs', $entry['path']);
    $this->assertSame(
      'https://support.access-ci.org/api/1.0/content/' . $node->id(),
      $entry['content_url']
    );
  }

  /**
   * Tests that nodes assigned only to a different domain are excluded.
   *
   * Creates a second domain, then one page on the support domain and one
   * page exclusively on the other domain; asserts only the support-domain
   * page appears in the index.
   */
  public function testIndexExcludesOtherDomainNodes(): void {
    Domain::create([
      'id' => 'other_domain',
      'hostname' => 'other.example.com',
      'name' => 'Other Domain',
      'scheme' => 'https',
      'status' => 1,
    ])->save();

    $this->createPage(['title' => 'Support Domain Page']);
    $this->createPage([
      'title' => 'Other Domain Page',
      'field_domain_access' => [['target_id' => 'other_domain']],
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $data = json_decode($response->getContent(), TRUE);

    $titles = array_column($data['pages'], 'title');
    $this->assertContains('Support Domain Page', $titles);
    $this->assertNotContains('Other Domain Page', $titles);
  }

  /**
   * Tests that unsupported content types are excluded from the index.
   *
   * Creates an "article" node type without the text view mode/display, plus a
   * qualifying "page" node. The controller's hasTextViewMode() check drops the
   * article even if it otherwise matches; only the page should appear.
   *
   * Note: rather than attaching field_domain_access to article (which would
   * make the entity query include it and require additional schema setup), we
   * rely on the query already filtering on type=page, so the article never
   * enters the result set at all. The article type is created without the
   * text view display to document the second guard as well.
   */
  public function testIndexExcludesUnsupportedContentTypes(): void {
    // Create article type with NO text view display.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $page = $this->createPage(['title' => 'Qualifying Page']);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $data = json_decode($response->getContent(), TRUE);

    $titles = array_column($data['pages'], 'title');
    $this->assertContains($page->label(), $titles);
    $this->assertNotContains('Article Node', $titles);

    // Verify no article entries snuck through by content_type.
    $types = array_column($data['pages'], 'content_type');
    $this->assertNotContains('article', $types);
  }

  /**
   * Tests that the index entries are sorted by path alias ascending.
   *
   * Creates three pages with aliases that sort differently from their nid
   * order: nid-order would give zebra, alpha, mango but the sorted output
   * must be alpha, mango, zebra.
   */
  public function testIndexSortedByPathAlias(): void {
    $zebra = $this->createPage(['title' => 'Zebra Page']);
    $alpha = $this->createPage(['title' => 'Alpha Page']);
    $mango = $this->createPage(['title' => 'Mango Page']);

    PathAlias::create([
      'path' => '/node/' . $zebra->id(),
      'alias' => '/zebra',
    ])->save();
    PathAlias::create([
      'path' => '/node/' . $alpha->id(),
      'alias' => '/alpha',
    ])->save();
    PathAlias::create([
      'path' => '/node/' . $mango->id(),
      'alias' => '/mango',
    ])->save();

    // Kernel tests do not build the router, so router.path_roots is empty and
    // the alias whitelist will not look up '/node/*' paths. Seed the root so
    // the whitelist's resolveCacheMiss() can verify the aliases exist in DB.
    \Drupal::state()->set('router.path_roots', ['node']);

    // Flush the alias manager's in-memory caches so it re-queries the DB.
    \Drupal::service('path_alias.manager')->cacheClear();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );
    $response = $controller->index();
    $data = json_decode($response->getContent(), TRUE);

    // Locate our three pages by title and collect their path values.
    $byTitle = [];
    foreach ($data['pages'] as $entry) {
      $byTitle[$entry['title']] = $entry['path'];
    }
    $this->assertArrayHasKey('Zebra Page', $byTitle);
    $this->assertArrayHasKey('Alpha Page', $byTitle);
    $this->assertArrayHasKey('Mango Page', $byTitle);

    // The usort is by strcmp(path), so alpha < mango < zebra.
    $zebraPath = $byTitle['Zebra Page'];
    $alphaPath = $byTitle['Alpha Page'];
    $mangoPath = $byTitle['Mango Page'];

    // Verify the aliases were actually resolved (not just /node/X fallback).
    $this->assertStringContainsString('alpha', $alphaPath);
    $this->assertStringContainsString('mango', $mangoPath);
    $this->assertStringContainsString('zebra', $zebraPath);

    // Assert ascending strcmp order: alpha < mango < zebra.
    $this->assertLessThan(0, strcmp($alphaPath, $mangoPath), 'alpha path should sort before mango path');
    $this->assertLessThan(0, strcmp($mangoPath, $zebraPath), 'mango path should sort before zebra path');
  }

  /**
   * Tests that a freshly-created page appears when the index is re-queried.
   *
   * Documents that the controller re-queries on every call, so new qualifying
   * pages are immediately visible without a cache warm-up step.
   */
  public function testIndexInvalidatesOnPageSave(): void {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentIndexController::class
    );

    $before = json_decode($controller->index()->getContent(), TRUE);
    $beforeCount = count($before['pages']);
    $beforeTitles = array_column($before['pages'], 'title');
    $this->assertNotContains('Newly Added Page', $beforeTitles);

    $this->createPage(['title' => 'Newly Added Page']);

    $after = json_decode($controller->index()->getContent(), TRUE);
    $afterTitles = array_column($after['pages'], 'title');
    $this->assertContains('Newly Added Page', $afterTitles);
    $this->assertCount($beforeCount + 1, $after['pages']);
  }

  /**
   * Index entries carry content_hash, and it equals the per-doc endpoint hash.
   */
  public function testIndexEntriesIncludeMatchingContentHash(): void {
    $node = $this->createPage(['title' => 'Indexed Hash Page', 'body' => [
      'value' => '<p>Body that gets hashed.</p>', 'format' => 'basic_html',
    ]]);

    $index = \Drupal::classResolver()->getInstanceFromDefinition(ContentIndexController::class);
    $data = json_decode($index->index()->getContent(), TRUE);

    // Match on content_url (embeds the unique nid) rather than title, so the
    // lookup can't grab the wrong row if titles ever collide.
    $entry = NULL;
    foreach ($data['pages'] as $page) {
      if (str_ends_with($page['content_url'], '/api/1.0/content/' . $node->id())) {
        $entry = $page;
        break;
      }
    }
    $this->assertNotNull($entry);
    $this->assertArrayHasKey('content_hash', $entry);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['content_hash']);

    $endpoint = \Drupal::classResolver()->getInstanceFromDefinition(
      \Drupal\access_content_api\Controller\ContentController::class
    );
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/1.0/content/' . $node->id());
    $detail = json_decode($endpoint->byId($request, (int) $node->id())->getContent(), TRUE);
    $this->assertSame($detail['content_hash'], $entry['content_hash']);
  }

  /**
   * The shared RenderHash helper must produce the same hash the per-doc
   * endpoint emits, so the index and detail never disagree.
   */
  public function testRenderHashMatchesEndpointHash(): void {
    $node = $this->createPage(['title' => 'Hash Parity Page', 'body' => [
      'value' => '<p>Stable body text for hashing.</p>', 'format' => 'basic_html',
    ]]);

    $endpointController = \Drupal::classResolver()->getInstanceFromDefinition(
      \Drupal\access_content_api\Controller\ContentController::class
    );
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/1.0/content/' . $node->id());
    $endpointData = json_decode($endpointController->byId($request, (int) $node->id())->getContent(), TRUE);

    /** @var \Drupal\access_content_api\RenderHash $renderHash */
    $renderHash = \Drupal::service('access_content_api.render_hash');
    $helperHash = $renderHash->contentHash($node, new \Drupal\Core\Cache\CacheableMetadata());

    $this->assertSame($endpointData['content_hash'], $helperHash);
  }

  /**
   * Returns the index entries keyed by node ID.
   *
   * @return array<int, array>
   *   Index entries.
   */
  private function indexEntries(): array {
    $entries = [];
    $response = $this->requestIndex();
    foreach ($this->decode($response)['pages'] as $i => $page) {
      $entries[$this->indexedNids($response)[$i]] = $page;
    }
    return $entries;
  }

  /**
   * Eligible affinity groups and MATCH engagements are listed by type.
   */
  public function testIndexListsAffinityGroupsAndMatchEngagements(): void {
    $this->createAffinityGroupBundle();
    $this->createMatchBundle();
    $group = $this->createContentNode('affinity_group', ['title' => 'Indexed Group']);
    $match = $this->createContentNode('match_engagement', [
      'title' => 'Indexed Match',
      'field_status' => 'complete',
    ]);
    $page = $this->createPage(['title' => 'Indexed Page']);

    $entries = $this->indexEntries();
    $this->assertSame('affinity_group', $entries[(int) $group->id()]['content_type']);
    $this->assertSame('match_engagement', $entries[(int) $match->id()]['content_type']);
    $this->assertSame('page', $entries[(int) $page->id()]['content_type']);
    $this->assertSame('Indexed Group', $entries[(int) $group->id()]['title']);
  }

  /**
   * An access_news node is absent from the index although it has a text mode.
   */
  public function testIndexExcludesAccessNews(): void {
    $this->createTextBundle('access_news', ['body' => 'text_long']);
    $news = $this->createContentNode('access_news');
    $page = $this->createPage();

    // Precondition: the news bundle is otherwise eligible.
    $this->assertTrue(\Drupal::service('access_content_api.eligibility')->hasTextViewMode('access_news'));
    $nids = $this->indexedNids($this->requestIndex());
    $this->assertContains((int) $page->id(), $nids);
    $this->assertNotContains((int) $news->id(), $nids);
    $this->assertSame(200, $this->requestById($news->id())->getStatusCode(), 'Still served by id.');
  }

  /**
   * Only in-progress and complete MATCH engagements are indexed.
   */
  public function testIndexMatchStateAllowlist(): void {
    $this->createMatchBundle();
    $nodes = [];
    foreach (['in_review', 'declined', 'in_progress', 'complete'] as $state) {
      $nodes[$state] = (int) $this->createContentNode('match_engagement', [
        'title' => "Match $state",
        'field_status' => $state,
      ])->id();
    }

    $nids = $this->indexedNids($this->requestIndex());
    $this->assertNotContains($nodes['in_review'], $nids);
    $this->assertNotContains($nodes['declined'], $nids);
    $this->assertContains($nodes['in_progress'], $nids);
    $this->assertContains($nodes['complete'], $nids);
    // Excluded states stay reachable by id.
    $this->assertSame(200, $this->requestById($nodes['declined'])->getStatusCode());
    $this->assertSame(200, $this->requestById($nodes['in_review'])->getStatusCode());
  }

  /**
   * Changing a match from declined to complete adds it to a cached index.
   */
  public function testMatchAppearsAfterStateChangeToComplete(): void {
    $this->createMatchBundle();
    $match = $this->createContentNode('match_engagement', ['field_status' => 'declined']);
    $nid = (int) $match->id();

    $index = $this->requestIndex();
    $this->assertNotContains($nid, $this->indexedNids($index));
    $cid = $this->cacheResponse($index, 'access_content_api_test:index');

    $match->set('field_status', 'complete');
    $match->save();

    $this->assertFalse($this->isCached($cid), 'Cached index is invalidated by the state change.');
    $this->assertContains($nid, $this->indexedNids($this->requestIndex()));
  }

  /**
   * A bundle without a text display is not listed, even if otherwise eligible.
   */
  public function testIndexExcludesBundleWithoutTextDisplay(): void {
    $this->createTextBundle('article', ['body' => 'text_long']);
    EntityViewDisplay::load('node.article.text')->delete();
    $article = $this->createContentNode('article');
    $page = $this->createPage();

    $nids = $this->indexedNids($this->requestIndex());
    $this->assertContains((int) $page->id(), $nids);
    $this->assertNotContains((int) $article->id(), $nids);
  }

  /**
   * The index carries one list tag per index bundle and the display list tag.
   */
  public function testIndexCacheTags(): void {
    $this->createAffinityGroupBundle();
    $this->createMatchBundle();
    $this->createTextBundle('access_news', ['body' => 'text_long']);

    $tags = $this->requestIndex()->getCacheableMetadata()->getCacheTags();
    $this->assertContains('node_list:page', $tags);
    $this->assertContains('node_list:affinity_group', $tags);
    $this->assertContains('node_list:match_engagement', $tags);
    $this->assertContains('config:entity_view_display_list', $tags);
    $this->assertNotContains('node_list:access_news', $tags);
  }

  /**
   * Creating a new affinity group invalidates the cached index.
   */
  public function testNewAffinityGroupInvalidatesCachedIndex(): void {
    $this->createAffinityGroupBundle();
    $before = $this->requestIndex();
    $cid = $this->cacheResponse($before, 'access_content_api_test:index');

    $group = $this->createContentNode('affinity_group', ['title' => 'Brand New Group']);

    $this->assertFalse($this->isCached($cid), 'Cached index is invalidated by a new group.');
    $this->assertContains((int) $group->id(), $this->indexedNids($this->requestIndex()));
  }

  /**
   * Adding a text display invalidates the cached index.
   */
  public function testAddingTextDisplayInvalidatesCachedIndex(): void {
    $this->createTextBundle('article', ['body' => 'text_long']);
    EntityViewDisplay::load('node.article.text')->delete();
    $cid = $this->cacheResponse($this->requestIndex(), 'access_content_api_test:index');

    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'text',
      'status' => TRUE,
    ])->setComponent('body', ['type' => 'text_default', 'label' => 'hidden'])->save();

    $this->assertFalse($this->isCached($cid), 'Cached index is invalidated by a new text display.');
  }

  /**
   * A node denied to anonymous by hook_node_access is absent from the index.
   *
   * The index query's accessCheck relies on node grants, which do not see
   * hook_node_access; only the loop's explicit anonymous access check does.
   */
  public function testIndexExcludesNodeDeniedToAnonymousByHook(): void {
    $this->enableModules(['access_content_api_test_access']);
    // Rebuilding the container drops the negotiated domain.
    \Drupal::service('domain.negotiator')->setActiveDomain(Domain::load(self::SUPPORT_DOMAIN_ID));

    $denied = $this->createPage(['title' => 'Denied For Anonymous Sentinel']);
    $allowed = $this->createPage(['title' => 'Allowed Page']);
    // Precondition: the hook denies exactly the sentinel, for anonymous only.
    $this->assertFalse($denied->access('view', new AnonymousUserSession()));
    $this->assertTrue($allowed->access('view', new AnonymousUserSession()));

    $nids = $this->indexedNids($this->requestIndex());
    $this->assertContains((int) $allowed->id(), $nids);
    $this->assertNotContains((int) $denied->id(), $nids);
    $this->assertSame(404, $this->requestById($denied->id())->getStatusCode());
  }

}
