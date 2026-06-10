<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentController;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\domain\Entity\Domain;
use Drupal\filter\Entity\FilterFormat;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the /api/1.0/content endpoint.
 *
 * @group access_content_api
 */
class ContentEndpointTest extends ContentApiKernelTestBase {

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
    'block',
    'block_content',
    'shortcode',
    'shortcode_basic_tags',
  ];

  /**
   * Tests the happy path: a published page on the support domain.
   *
   * Seeds a published page node with body content, assigns it to the support
   * domain, and asserts a 200 response whose JSON body carries the extracted
   * plain text and the documented metadata fields.
   */
  public function testReturnsTextForPublishedBodyNode(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'ACCESS OnDemand',
      'status' => 1,
      'body' => [
        'value' => '<p>Open OnDemand is an easy-to-use web portal.</p>',
        'format' => 'basic_html',
      ],
      'field_domain_access' => [['target_id' => static::SUPPORT_DOMAIN_ID]],
    ]);
    $node->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(1, $data['version']);
    $this->assertSame((int) $node->id(), $data['id']);
    $this->assertSame('ACCESS OnDemand', $data['title']);
    $this->assertSame('page', $data['content_type']);
    $this->assertStringContainsString('Open OnDemand is an easy-to-use web portal.', $data['text']);
    // Markup must be stripped from the extracted text.
    $this->assertStringNotContainsString('<p>', $data['text']);

    // Caching/conditional-GET headers are present.
    $this->assertNotEmpty($response->headers->get('ETag'));
    $this->assertNotEmpty($response->headers->get('Last-Modified'));
  }

  /**
   * Tests that a published node without domain assignment returns 404.
   *
   * Full happy-path (200 + body text) requires domain entity setup; that is
   * covered by smoke tests against the live environment.
   */
  public function testReturns404ForNodeWithoutDomainAccess(): void {
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

    $this->assertEquals(404, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $body);
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

  /**
   * Tests that a node whose bundle has no text view mode returns 404.
   *
   * The view-mode check fires before the domain check, so we do not need to
   * assign field_domain_access (which does not exist on article anyway).
   */
  public function testReturns404ForUnsupportedContentType(): void {
    // Create an article node type with NO text view-mode display.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'An Article',
      'status' => 1,
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
   * Tests that a node assigned only to a non-support domain returns 404.
   *
   * Creates a second domain and a page assigned exclusively to it, confirming
   * the domain filter rejects it.
   */
  public function testReturns404ForOtherDomainNode(): void {
    Domain::create([
      'id' => 'other_domain',
      'hostname' => 'other.example.com',
      'name' => 'Other',
      'scheme' => 'https',
      'status' => 1,
    ])->save();

    $node = $this->createPage([
      'field_domain_access' => [['target_id' => 'other_domain']],
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());
    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests that shortcode tokens in a body are expanded in the API response.
   *
   * Uses the shortcode_basic_tags highlight shortcode (which does a simple span
   * wrap, requiring no Twig template) to verify the shortcode filter pipeline
   * runs end-to-end: the raw [highlight] token must not appear in the output
   * and the wrapped text must be present.
   *
   * Note: The access_shortcodes accordion shortcode depends on access →
   * access_llm → key module, which is not available in kernel tests. The
   * highlight shortcode from shortcode_basic_tags exercises the same filter
   * path without those transitive dependencies.
   */
  public function testShortcodeExpansionInBody(): void {
    // Create a filter format that runs the shortcode filter.
    FilterFormat::create([
      'format' => 'shortcode_html',
      'name' => 'Shortcode HTML',
      'filters' => [
        'shortcode' => [
          'status' => TRUE,
          'weight' => 0,
        ],
      ],
    ])->save();

    $distinctiveText = 'UniqueShortcodeBodyText12345';
    $body = '[highlight]' . $distinctiveText . '[/highlight]';
    $node = $this->createPage([
      'body' => ['value' => $body, 'format' => 'shortcode_html'],
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    // The raw shortcode token must not appear verbatim in the output.
    $this->assertStringNotContainsString('[highlight]', $data['text']);
    // The text content inside the shortcode must appear in the response.
    $this->assertStringContainsString($distinctiveText, $data['text']);
  }

  /**
   * Tests that byPath with a path alias resolves to the correct node.
   *
   * Creates a page, gives it an alias, then confirms byPath returns the same
   * 200 response and node ID as byId.
   */
  public function testPathByQueryResolvesAlias(): void {
    $node = $this->createPage([
      'title' => 'My Aliased Page',
      'body' => ['value' => '<p>Alias content</p>', 'format' => 'basic_html'],
    ]);

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/my-page',
    ])->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );

    $requestById = Request::create('/api/1.0/content/' . $node->id());
    $responseById = $controller->byId($requestById, (int) $node->id());

    $requestByPath = Request::create('/api/1.0/content', 'GET', ['path' => '/my-page']);
    $responseByPath = $controller->byPath($requestByPath);

    $this->assertEquals(200, $responseById->getStatusCode());
    $this->assertEquals(200, $responseByPath->getStatusCode());

    $dataById = json_decode($responseById->getContent(), TRUE);
    $dataByPath = json_decode($responseByPath->getContent(), TRUE);

    $this->assertSame($dataById['id'], $dataByPath['id']);
    $this->assertSame($dataById['text'], $dataByPath['text']);
  }

  /**
   * Tests that a matching If-None-Match header returns a 304 Not Modified.
   *
   * Fetches a node once, captures the ETag, then re-requests with that ETag
   * as If-None-Match, asserting a 304 with no body.
   */
  public function testIfNoneMatchReturns304(): void {
    $node = $this->createPage();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );

    // First request to obtain ETag.
    $request1 = Request::create('/api/1.0/content/' . $node->id());
    $response1 = $controller->byId($request1, (int) $node->id());
    $this->assertEquals(200, $response1->getStatusCode());
    $etag = $response1->headers->get('ETag');
    $this->assertNotEmpty($etag);

    // Second request with If-None-Match.
    $request2 = Request::create('/api/1.0/content/' . $node->id());
    $request2->headers->set('If-None-Match', $etag);
    $response2 = $controller->byId($request2, (int) $node->id());

    $this->assertEquals(304, $response2->getStatusCode());
    $this->assertEmpty($response2->getContent());
  }

  /**
   * Tests that a node body edit is reflected in subsequent API responses.
   *
   * The controller reloads the node on each request, so a body change that is
   * saved to the database must appear in the next response.
   */
  public function testCacheInvalidatesOnNodeSave(): void {
    $node = $this->createPage([
      'body' => ['value' => '<p>Original body text</p>', 'format' => 'basic_html'],
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );

    $request1 = Request::create('/api/1.0/content/' . $node->id());
    $response1 = $controller->byId($request1, (int) $node->id());
    $this->assertEquals(200, $response1->getStatusCode());
    $data1 = json_decode($response1->getContent(), TRUE);
    $this->assertStringContainsString('Original body text', $data1['text']);

    // Update the node.
    $node->set('body', ['value' => '<p>Updated body text</p>', 'format' => 'basic_html']);
    $node->save();

    $request2 = Request::create('/api/1.0/content/' . $node->id());
    $response2 = $controller->byId($request2, (int) $node->id());
    $this->assertEquals(200, $response2->getStatusCode());
    $data2 = json_decode($response2->getContent(), TRUE);
    $this->assertStringContainsString('Updated body text', $data2['text']);
    $this->assertStringNotContainsString('Original body text', $data2['text']);
  }

  /**
   * Tests that multiple Layout Builder components are concatenated in output.
   *
   * Enables Layout Builder on the page default display and creates a node with
   * two body field components containing distinct text, asserting both appear
   * in the API response.
   */
  public function testLayoutBuilderWalkerRendersMultipleComponents(): void {
    // block_content schema is required by layout_builder's
    // InlineBlockEntityOperations which fires on display save.
    $this->installEntitySchema('block_content');

    // Enable Layout Builder with per-node overrides on the default display.
    $display = EntityViewDisplay::load('node.page.default')
      ?: EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'page',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $display->enableLayoutBuilder();
    $display->setOverridable();
    $display->save();

    // Build two body-field components with different content. We use the
    // field_block plugin which renders a field directly from the entity.
    $component1 = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
      'id' => 'field_block:node:page:body',
      'label' => 'Body',
      'label_display' => FALSE,
      'formatter' => [
        'type' => 'text_default',
        'label' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ],
      'context_mapping' => [
        'entity' => 'layout_builder.entity',
        'view_mode' => 'view_mode',
      ],
    ]);
    $component2 = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
      'id' => 'field_block:node:page:title',
      'label' => 'Title',
      'label_display' => FALSE,
      'formatter' => [
        'type' => 'string',
        'label' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ],
      'context_mapping' => [
        'entity' => 'layout_builder.entity',
        'view_mode' => 'view_mode',
      ],
    ]);

    $section = new Section('layout_onecol', [], [$component1, $component2]);

    $node = $this->createPage([
      'title' => 'Layout Builder Title Text',
      'body' => ['value' => '<p>Layout builder body content</p>', 'format' => 'basic_html'],
      'layout_builder__layout' => [$section],
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    // Both components should contribute their text to the output.
    $this->assertStringContainsString('Layout builder body content', $data['text']);
    $this->assertStringContainsString('Layout Builder Title Text', $data['text']);
  }

  /**
   * Tests that denylisted Layout Builder components are excluded from output.
   *
   * Configures a node with one normal component and one component whose plugin
   * ID matches the views_block: denylist prefix, asserting only the normal
   * component's text appears.
   */
  public function testDenylistedComponentsAreSkipped(): void {
    // block_content schema is required by layout_builder's
    // InlineBlockEntityOperations which fires on display save.
    $this->installEntitySchema('block_content');

    // Enable Layout Builder with per-node overrides.
    $display = EntityViewDisplay::load('node.page.default')
      ?: EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'page',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $display->enableLayoutBuilder();
    $display->setOverridable();
    $display->save();

    $normalComponent = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
      'id' => 'field_block:node:page:body',
      'label' => 'Body',
      'label_display' => FALSE,
      'formatter' => [
        'type' => 'text_default',
        'label' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ],
      'context_mapping' => [
        'entity' => 'layout_builder.entity',
        'view_mode' => 'view_mode',
      ],
    ]);

    // A views_block: plugin matches the denylist prefix and must be skipped.
    $denylistedComponent = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
      'id' => 'views_block:frontpage-block_1',
      'label' => 'Views Block',
      'label_display' => FALSE,
      'context_mapping' => [],
    ]);

    $section = new Section('layout_onecol', [], [$normalComponent, $denylistedComponent]);

    $node = $this->createPage([
      'body' => ['value' => '<p>Normal component content</p>', 'format' => 'basic_html'],
      'layout_builder__layout' => [$section],
    ]);

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    // The normal component text must be present.
    $this->assertStringContainsString('Normal component content', $data['text']);
    // The denylist component was skipped; no views block output should appear.
    $this->assertStringNotContainsString('views_block', $data['text']);
  }

}
