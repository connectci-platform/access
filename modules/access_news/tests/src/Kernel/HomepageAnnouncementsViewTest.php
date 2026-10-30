<?php

declare(strict_types=1);

namespace Drupal\Tests\access_news\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the homepage announcements block (D8-2934).
 *
 * The ACCESS Support homepage shows the three newest announcements from the
 * access_news view's homepage_announcements display. The display is deployed
 * config, so this loads the real YAML and asserts its status, sort, limit and
 * escaping behavior against fixture announcements.
 *
 * @group access_news
 */
class HomepageAnnouncementsViewTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'datetime',
    'options',
    'node',
    'taxonomy',
    'workflows',
    'content_moderation',
    'path_alias',
    'views',
  ];

  /**
   * {@inheritdoc}
   *
   * The deployed view carries keys that Views core does not declare in config
   * schema on displays this test never runs. Strict validation would fail on
   * those pre-existing gaps rather than on anything under test.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);

    NodeType::create(['type' => 'access_news', 'name' => 'Announcement'])->save();
    node_add_body_field(NodeType::load('access_news'));
    FieldStorageConfig::create([
      'field_name' => 'field_published_date',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'date'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_published_date',
      'entity_type' => 'node',
      'bundle' => 'access_news',
      'label' => 'Published date',
    ])->save();

    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'access_news');
    $workflow->save();

    $config = Yaml::parseFile(DRUPAL_ROOT . '/sites/default/config/default/views.view.access_news.yml');
    $this->assertArrayHasKey('homepage_announcements', $config['display']);

    // The domain-access and where-to-share filters read tables that belong to
    // the site's domain_access and field_choose_where_to_share_this fields,
    // which this minimal kernel fixture does not stand up. Standing them up
    // would not change what is under test, which is the status/sort/limit
    // logic and the escaping. Remove just those two handlers; every other
    // filter (bundle, published moderation state), the sort and the pager are
    // exercised exactly as deployed. Handlers for other missing fields on the
    // view's other displays (affinity group, etc.) are never built by this
    // display.
    //
    // Displays other than the one under test are dropped: saving the view
    // computes config dependencies for every display, and the others reference
    // site fields (affinity group, etc.) absent from this fixture.
    $config['display'] = array_intersect_key($config['display'], array_flip(['default', 'homepage_announcements']));
    // The default display's own filters (affinity group taxonomy, etc.) are
    // overridden by this display, so they are cleared for the same reason.
    $config['display']['default']['display_options']['filters'] = [];
    $removed = 0;
    foreach ($config['display'] as $id => $display) {
      foreach (['field_domain_access_target_id', 'field_choose_where_to_share_this_value'] as $filter) {
        if (isset($display['display_options']['filters'][$filter])) {
          unset($config['display'][$id]['display_options']['filters'][$filter]);
          $removed++;
        }
      }
    }
    $this->assertGreaterThanOrEqual(2, $removed);
    \Drupal::service('views.views_data')->clear();
    View::create($config)->save();
  }

  /**
   * Creates an announcement.
   *
   * @param string $title
   *   The title.
   * @param string $date
   *   The published date, Y-m-d.
   * @param string $state
   *   The moderation state.
   * @param string $body
   *   The body HTML.
   */
  protected function createAnnouncement(string $title, string $date, string $state = 'published', string $body = '<p>Body</p>'): Node {
    $node = Node::create([
      'type' => 'access_news',
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_published_date' => $date,
      'moderation_state' => $state,
      'uid' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Executes the display.
   *
   * @return \Drupal\views\ViewExecutable
   *   The executed view.
   */
  protected function runDisplay() {
    $view = Views::getView('access_news');
    $view->setDisplay('homepage_announcements');
    $view->execute();
    return $view;
  }

  /**
   * Renders the display to HTML.
   */
  protected function renderDisplay(): string {
    $build = Views::getView('access_news')->buildRenderable('homepage_announcements');
    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

  /**
   * Shows the three newest published announcements, newest first.
   */
  public function testThreeNewestPublishedNewestFirst(): void {
    // Created out of date order so the sort, not insertion order or nid, is
    // what the assertion sees.
    $this->createAnnouncement('Oldest', '2026-01-05');
    $this->createAnnouncement('Third', '2026-06-15');
    $this->createAnnouncement('Newest', '2026-10-06');
    $this->createAnnouncement('Second', '2026-09-01');
    $this->createAnnouncement('Newest draft', '2026-10-20', 'draft');
    $this->createAnnouncement('Archived', '2026-10-10', 'archived');

    $view = $this->runDisplay();
    $titles = array_map(static fn ($row) => $row->_entity->label(), $view->result);

    $this->assertSame(['Newest', 'Second', 'Third'], $titles);
  }

  /**
   * Fewer than three published announcements renders only those.
   */
  public function testFewerThanThree(): void {
    $this->createAnnouncement('Only', '2026-03-01');
    $this->createAnnouncement('Draft', '2026-04-01', 'draft');

    $view = $this->runDisplay();
    $this->assertCount(1, $view->result);
  }

  /**
   * The row has a date, a linked title and an escaped plain-text excerpt.
   */
  public function testRowMarkupIsOneLinkWithEscapedExcerpt(): void {
    $this->createAnnouncement(
      'Tom & Jerry <b>Day</b>',
      '2026-10-06',
      'published',
      '<p>Hello <strong>bold</strong> &amp; <script>alert(1)</script>world</p>'
    );

    $output = $this->renderDisplay();

    $this->assertStringContainsString('class="homepage-ann"', $output);
    $this->assertStringContainsString('<span class="homepage-ann__date">10/06/26</span><a class="homepage-ann__title" href="', $output);
    $this->assertStringContainsString('<small class="homepage-ann__excerpt">', $output);
    // Tags in the title and body never survive as markup.
    $this->assertStringNotContainsString('<b>', $output);
    $this->assertStringNotContainsString('<strong>', $output);
    $this->assertStringNotContainsString('<script>', $output);
    $this->assertStringContainsString('Tom &amp; Jerry', $output);
    // The excerpt keeps the readable text.
    $this->assertStringContainsString('Hello bold', $output);
    // Only the title links; the date and excerpt sit outside the link.
    $this->assertSame(1, substr_count($output, '<a '), 'the title is the only link');
    $this->assertMatchesRegularExpression('#</a><small class="homepage-ann__excerpt">#', $output);
  }

  /**
   * Entity-encoded tags in the body stay text after the excerpt decoding.
   */
  public function testEncodedTagsInExcerptStayText(): void {
    $this->createAnnouncement(
      'Encoded',
      '2026-10-06',
      'published',
      '<p>&lt;img src=x onerror=alert(1)&gt; &amp;lt;i&amp;gt;x&amp;lt;/i&amp;gt; &quot;q&quot;</p>'
    );

    $output = $this->renderDisplay();

    $this->assertStringNotContainsString('<img', $output);
    $this->assertStringNotContainsString('<i>', $output);
    $this->assertStringContainsString('<small class="homepage-ann__excerpt">', $output);
  }

  /**
   * A long body is cut to about 200 characters with an ellipsis.
   */
  public function testLongExcerptIsTrimmed(): void {
    $this->createAnnouncement('Long', '2026-10-06', 'published', '<p>' . str_repeat('word ', 100) . '</p>');

    $output = $this->renderDisplay();
    preg_match('#<small class="homepage-ann__excerpt">(.*?)</small>#s', $output, $m);
    $this->assertNotEmpty($m);
    $excerpt = html_entity_decode($m[1]);
    $this->assertStringEndsWith('…', $excerpt);
    $this->assertLessThanOrEqual(201, mb_strlen($excerpt));
  }

}
