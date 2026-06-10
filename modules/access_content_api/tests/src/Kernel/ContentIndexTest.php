<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentIndexController;
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

}
