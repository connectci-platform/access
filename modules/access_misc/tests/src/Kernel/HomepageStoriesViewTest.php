<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the homepage researcher stories block (D8-2934).
 *
 * The ACCESS Support homepage shows the three newest researcher stories from
 * the tool_case_study view's homepage_stories display. The display is deployed
 * config, so this loads the real YAML and asserts its status, sort, limit,
 * tag, initials and escaping behavior against fixture stories.
 *
 * @group access_misc
 */
class HomepageStoriesViewTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'node',
    'taxonomy',
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
   * A running counter for fixture user IDs.
   */
  protected int $uidCounter = 10;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['filter', 'node', 'image']);

    // The thumbnail style ships with the image module's config; the landscape
    // style is site config, stood up here with no effects.
    \Drupal::entityTypeManager()->getStorage('image_style')->create([
      'name' => 'landscape',
      'label' => 'landscape',
      'effects' => [],
    ])->save();

    // Anonymous (uid 0) and user 1 must exist before real users are created.
    User::create(['uid' => 0, 'name' => ''])->save();
    User::create(['uid' => 1, 'name' => 'admin'])->save();

    // The display keeps the 'access content' permission check rather than
    // disabling access, so render as a user who holds it.
    $role = Role::create(['id' => 'viewer', 'label' => 'Viewer']);
    $role->grantPermission('access content')->save();
    $viewer = User::create(['uid' => 2, 'name' => 'viewer', 'roles' => ['viewer']]);
    $viewer->save();
    \Drupal::currentUser()->setAccount($viewer);

    NodeType::create(['type' => 'tools_case_study', 'name' => 'Researcher story'])->save();
    Vocabulary::create(['vid' => 'tcs_access_tools_used', 'name' => 'Tools used'])->save();

    $this->addField('node', 'tools_case_study', 'field_tcs_access_tools_used', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->addField('node', 'tools_case_study', 'field_tcs_researcher_name', 'entity_reference', ['target_type' => 'user'], 1);
    $this->addField('node', 'tools_case_study', 'field_tcs_role', 'string');
    $this->addField('node', 'tools_case_study', 'field_tcs_institution', 'string');
    $this->addField('node', 'tools_case_study', 'field_tcs_main_image', 'image', [], 1);
    $this->addField('user', 'user', 'user_picture', 'image', [], 1);

    $config = Yaml::parseFile(DRUPAL_ROOT . '/sites/default/config/default/views.view.tool_case_study.yml');
    $this->assertArrayHasKey('homepage_stories', $config['display']);

    // Keep only the default and homepage_stories displays: saving the view
    // computes config dependencies for every display, and page_1 references
    // site fields (body, software, field of science, ...) absent from this
    // fixture. The default display's own fields are never rendered by
    // homepage_stories, which overrides them, so they are cleared for the same
    // reason, as is the unused field_tcs_access_resource relationship (the
    // display overrides relationships and keeps only the researcher). The
    // default display's status/bundle filters and created sort, which the
    // display inherits, are left exactly as deployed. Every field and
    // relationship the display itself uses is stood up above, images included.
    $config['display'] = array_intersect_key($config['display'], array_flip(['default', 'homepage_stories']));
    $config['display']['default']['display_options']['fields'] = [];
    unset($config['display']['default']['display_options']['relationships']['field_tcs_access_resource']);
    $this->assertArrayHasKey('field_tcs_researcher_name', $config['display']['default']['display_options']['relationships']);
    \Drupal::service('views.views_data')->clear();
    View::create($config)->save();
  }

  /**
   * Creates a field storage and instance.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array<string, mixed> $settings
   *   The field storage settings.
   * @param int $cardinality
   *   The field cardinality.
   */
  protected function addField(string $entity_type, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
    $instance_settings = [];
    if ($type === 'entity_reference' && ($settings['target_type'] ?? '') === 'taxonomy_term') {
      $instance_settings = ['handler_settings' => ['target_bundles' => ['tcs_access_tools_used' => 'tcs_access_tools_used']]];
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $name,
      'settings' => $instance_settings,
    ])->save();
  }

  /**
   * Creates a researcher user.
   */
  protected function createResearcher(string $name, bool $picture = FALSE): User {
    $values = ['uid' => $this->uidCounter++, 'name' => $name, 'status' => 1];
    if ($picture) {
      $file = File::create(['uri' => 'public://' . $values['uid'] . '.png', 'filename' => $values['uid'] . '.png']);
      $file->setPermanent();
      $file->save();
      $values['user_picture'] = ['target_id' => $file->id(), 'alt' => 'Portrait', 'width' => 10, 'height' => 10];
    }
    $user = User::create($values);
    $user->save();
    return $user;
  }

  /**
   * Creates a story.
   *
   * @param string $title
   *   The title.
   * @param int $created
   *   The created timestamp.
   * @param bool $published
   *   Whether it is published.
   * @param string[] $tools
   *   Tool term names.
   * @param \Drupal\user\Entity\User|null $researcher
   *   The researcher.
   */
  protected function createStory(string $title, int $created, bool $published = TRUE, array $tools = ['OnDemand'], ?User $researcher = NULL): Node {
    $term_ids = [];
    foreach ($tools as $tool) {
      $term = Term::create(['vid' => 'tcs_access_tools_used', 'name' => $tool]);
      $term->save();
      $term_ids[] = $term->id();
    }
    $file = File::create(['uri' => 'public://story-' . $created . '.png', 'filename' => 'story.png']);
    $file->setPermanent();
    $file->save();
    $node = Node::create([
      'type' => 'tools_case_study',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'created' => $created,
      'uid' => 1,
      'field_tcs_access_tools_used' => $term_ids,
      'field_tcs_researcher_name' => $researcher ? $researcher->id() : NULL,
      'field_tcs_role' => 'Professor',
      'field_tcs_institution' => 'Example University',
      'field_tcs_main_image' => ['target_id' => $file->id(), 'alt' => 'Story image', 'width' => 10, 'height' => 10],
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
    $view = Views::getView('tool_case_study');
    $view->setDisplay('homepage_stories');
    $view->execute();
    return $view;
  }

  /**
   * Renders the display to HTML.
   */
  protected function renderDisplay(): string {
    $build = Views::getView('tool_case_study')->buildRenderable('homepage_stories');
    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

  /**
   * Shows the three newest published stories, newest first.
   */
  public function testThreeNewestPublishedNewestFirst(): void {
    $person = $this->createResearcher('Ada Lovelace');
    // Created out of order so the sort, not insertion order or nid, is what
    // the assertion sees.
    $this->createStory('Oldest', 1000, TRUE, ['A'], $person);
    $this->createStory('Third', 3000, TRUE, ['A'], $person);
    $this->createStory('Newest', 5000, TRUE, ['A'], $person);
    $this->createStory('Second', 4000, TRUE, ['A'], $person);
    $this->createStory('Newest unpublished', 9000, FALSE, ['A'], $person);

    $view = $this->runDisplay();
    $titles = array_map(static fn ($row) => $row->_entity->label(), $view->result);
    $this->assertSame(['Newest', 'Second', 'Third'], $titles);

    $output = $this->renderDisplay();
    $this->assertSame(3, substr_count($output, 'class="homepage-story"'));
    $this->assertLessThan(strpos($output, 'Second'), strpos($output, 'Newest'));
    // The card link sits directly in the row, with no heading wrapper.
    $this->assertStringNotContainsString('<h2', $output);
  }

  /**
   * Fewer than three published stories renders only those.
   */
  public function testFewerThanThree(): void {
    $this->createStory('Only', 1000);
    $this->createStory('Draft', 2000, FALSE);

    $view = $this->runDisplay();
    $this->assertCount(1, $view->result);
    $this->assertSame(1, substr_count($this->renderDisplay(), 'class="homepage-story"'));
  }

  /**
   * A story with several tools shows only the first as its tag.
   */
  public function testTagShowsOnlyFirstTool(): void {
    $this->createStory('Multi', 1000, TRUE, ['First tool', 'Second tool']);

    $output = $this->renderDisplay();
    $this->assertStringContainsString('<span class="homepage-story__tag">First tool</span>', $output);
    $this->assertStringNotContainsString('Second tool', $output);
  }

  /**
   * Without a picture the researcher gets two-letter initials.
   */
  public function testInitialsFallback(): void {
    $this->createStory('Initials', 1000, TRUE, ['A'], $this->createResearcher('Gaya P. Yadav'));
    $this->createStory('Single', 500, TRUE, ['A'], $this->createResearcher('cher'));

    $output = $this->renderDisplay();
    $this->assertStringContainsString('homepage-story__avatar homepage-story__avatar--initials', $output);
    $this->assertMatchesRegularExpression('#--initials" aria-hidden="true">GY</span>#', $output);
    $this->assertMatchesRegularExpression('#--initials" aria-hidden="true">C</span>#', $output);
    $this->assertStringContainsString('<b class="homepage-story__name">Gaya P. Yadav</b>', $output);
    $this->assertStringContainsString('<span class="homepage-story__role">Professor, Example University</span>', $output);
  }

  /**
   * A researcher with a picture shows it instead of initials.
   */
  public function testPictureReplacesInitials(): void {
    $this->createStory('Pictured', 1000, TRUE, ['A'], $this->createResearcher('Gaya P. Yadav', TRUE));

    $output = $this->renderDisplay();
    $this->assertMatchesRegularExpression('#<span class="homepage-story__avatar">\s*<img#', $output);
    $this->assertStringNotContainsString('--initials', $output);
  }

  /**
   * Markup in user-supplied values never survives, and only the card links.
   */
  public function testEscaping(): void {
    $this->createStory('Tom & Jerry <b>Day</b><script>alert(1)</script>', 1000, TRUE, ['A'], $this->createResearcher('Gaya P. Yadav'));
    $this->createStory('Second', 900, TRUE, ['A'], $this->createResearcher('Ada Lovelace'));

    $output = $this->renderDisplay();
    $this->assertStringNotContainsString('<b>Day', $output);
    $this->assertStringNotContainsString('<script', $output);
    $this->assertStringContainsString('Tom &amp; Jerry', $output);
    $this->assertSame(2, substr_count($output, '<a '), 'each card has exactly one link');
  }

}
