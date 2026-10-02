<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentController;
use Drupal\access_content_api\Controller\ContentIndexController;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
   * The support domain machine name used across the fixtures.
   *
   * Matches the value shipped in access_content_api.settings.yml.
   */
  const SUPPORT_DOMAIN_ID = 'amp_cyberinfrastructure_org';

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
    'block',
    'block_content',
    'options',
    'link',
    'taxonomy',
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
    $this->installEntitySchema('taxonomy_term');
    // Layout Builder's InlineBlockEntityOperations needs this when a display
    // with Layout Builder enabled is saved.
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['system', 'node', 'filter', 'domain', 'domain_access', 'access_content_api']);
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
    $domain = Domain::create([
      'id' => self::SUPPORT_DOMAIN_ID,
      'hostname' => 'support.access-ci.org',
      'name' => 'Support',
      'scheme' => 'https',
      'status' => 1,
    ]);
    $domain->save();

    // Model a real anonymous request so the endpoint's node-access check
    // (which evaluates the anonymous user) resolves as it does in production:
    // the support domain is the active domain, and anonymous has the basic
    // "access content" permission. Without this, every access('view') check
    // would deny and the endpoints would 404 legitimately-public nodes.
    \Drupal::service('domain.negotiator')->setActiveDomain($domain);
    $this->grantAnonymousAccessContent();
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
      'field_domain_access' => [['target_id' => self::SUPPORT_DOMAIN_ID]],
    ];
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Grants the anonymous role the "access content" permission.
   *
   * Production anonymous users have this; kernel tests do not by default. The
   * content API enforces node-access ('view'), which requires it.
   */
  protected function grantAnonymousAccessContent(): void {
    $anonymous = Role::load(Role::ANONYMOUS_ID)
      ?: Role::create(['id' => Role::ANONYMOUS_ID, 'label' => 'Anonymous user']);
    $anonymous->grantPermission('access content');
    $anonymous->save();
  }

  /**
   * Creates a node bundle served by the content API, with a text display.
   *
   * Builds the node type, the fields, a "text" display that carries ONLY the
   * public fields, and a default display with Layout Builder enabled whose
   * default section places BOTH public and private fields as field blocks.
   * That default layout is what makes the "private field is absent" assertions
   * meaningful: if the API walked the default layout, the private values would
   * leak.
   *
   * @param string $bundle
   *   The node bundle machine name.
   * @param array $public_fields
   *   Fields shown in the text display, keyed by field name. Each value is a
   *   field type string or an array with keys: type (string, text_long,
   *   list_string, boolean, link or entity_reference), label, label_display
   *   (inline, above, hidden or visually_hidden; default "inline" for
   *   everything but "body", which defaults to "hidden"), allowed_values
   *   (list_string), target_type (entity_reference; default "user").
   * @param array $private_fields
   *   Fields that only appear in the Layout Builder default layout. Same
   *   format as $public_fields.
   */
  protected function createTextBundle(string $bundle, array $public_fields, array $private_fields = []): void {
    $type = NodeType::create(['type' => $bundle, 'name' => $bundle]);
    $type->save();
    // New bundles do not get Domain Access fields automatically (only the
    // ones that exist at module install time do).
    \Drupal::moduleHandler()->loadInclude('domain_access', 'module');
    domain_access_confirm_fields('node', $bundle);

    $weight = 0;
    $components = [];
    foreach (['public' => $public_fields, 'private' => $private_fields] as $group => $fields) {
      foreach ($fields as $name => $spec) {
        $spec = is_string($spec) ? ['type' => $spec] : $spec;
        if ($name === 'body') {
          node_add_body_field(NodeType::load($bundle));
          $spec += ['label_display' => 'hidden'];
          $field_type = 'text_long';
        }
        else {
          $this->addField($bundle, $name, $spec);
          $field_type = $spec['type'];
        }
        $component = [
          'type' => self::FORMATTERS[$field_type],
          'label' => $spec['label_display'] ?? 'inline',
          'weight' => $weight++,
          'settings' => $field_type === 'entity_reference' ? ['link' => FALSE] : [],
        ];
        $components[$name] = [$group, $component];
      }
    }

    // Load the displays only now: creating fields can auto-create the default
    // display, and saving a second "new" copy would collide with it.
    $text = EntityViewDisplay::load("node.$bundle.text") ?: EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => $bundle,
      'mode' => 'text',
      'status' => TRUE,
    ]);
    $default = EntityViewDisplay::load("node.$bundle.default") ?: EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    foreach ($components as $name => [$group, $component]) {
      if ($group === 'public') {
        $text->setComponent($name, $component);
      }
      $default->setComponent($name, $component);
    }
    $text->save();

    // Enabling Layout Builder converts the default display's components into
    // field_block components of the default section.
    $default->enableLayoutBuilder();
    $default->save();
    \Drupal::service('plugin.manager.block')->clearCachedDefinitions();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Guard: the default layout really does place every field.
    $placed = [];
    foreach (EntityViewDisplay::load("node.$bundle.default")->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $placed[] = $component->getPluginId();
      }
    }
    foreach (array_merge(array_keys($public_fields), array_keys($private_fields)) as $name) {
      $this->assertContains("field_block:node:$bundle:$name", $placed, "Default layout places $name.");
    }
  }

  /**
   * Field type to formatter map used by createTextBundle().
   */
  const FORMATTERS = [
    'string' => 'string',
    'text_long' => 'text_default',
    'list_string' => 'list_default',
    'boolean' => 'boolean',
    'entity_reference' => 'entity_reference_label',
    'link' => 'link',
  ];

  /**
   * Adds a field to a node bundle.
   *
   * @param string $bundle
   *   The bundle.
   * @param string $name
   *   The field name.
   * @param array $spec
   *   See createTextBundle().
   */
  protected function addField(string $bundle, string $name, array $spec): void {
    $type = $spec['type'];
    $storage_settings = [];
    $field_settings = [];
    if ($type === 'list_string') {
      $storage_settings['allowed_values'] = $spec['allowed_values'];
    }
    if ($type === 'entity_reference') {
      $target_type = $spec['target_type'] ?? 'user';
      $storage_settings['target_type'] = $target_type;
      $field_settings['handler'] = 'default:' . $target_type;
      if ($target_type === 'taxonomy_term') {
        $vid = $spec['vocabulary'] ?? 'tags';
        if (!Vocabulary::load($vid)) {
          Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
        }
        $field_settings['handler_settings'] = ['target_bundles' => [$vid => $vid]];
      }
    }
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'cardinality' => $type === 'entity_reference' ? -1 : 1,
        'settings' => $storage_settings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $spec['label'] ?? $name,
      'settings' => $field_settings,
    ])->save();
  }

  /**
   * Creates and saves a taxonomy term, creating its vocabulary if needed.
   *
   * @param string $name
   *   The term name.
   * @param string $vid
   *   The vocabulary machine name.
   */
  protected function createTerm(string $name, string $vid = 'tags'): Term {
    if (!Vocabulary::load($vid)) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }
    $term = Term::create(['vid' => $vid, 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Creates and saves a node of any bundle on the support domain.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $values
   *   Values overriding the defaults.
   */
  protected function createContentNode(string $bundle, array $values = []): Node {
    $node = Node::create($values + [
      'type' => $bundle,
      'title' => ucfirst($bundle) . ' title',
      'status' => 1,
      'field_domain_access' => [['target_id' => self::SUPPORT_DOMAIN_ID]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates the MATCH engagement bundle used by several tests.
   *
   * Public: body, field_qualifications, field_status.
   * Private: field_researcher.
   */
  protected function createMatchBundle(): void {
    $this->createTextBundle('match_engagement', [
      'body' => 'text_long',
      'field_qualifications' => [
        'type' => 'text_long',
        'label' => 'Qualifications',
        'label_display' => 'above',
      ],
      'field_status' => [
        'type' => 'list_string',
        'label' => 'Status',
        'allowed_values' => [
          'in_review' => 'Submitted',
          'declined' => 'Declined',
          'in_progress' => 'In Progress',
          'complete' => 'Complete',
        ],
      ],
    ], [
      'field_researcher' => ['type' => 'string', 'label' => 'Researcher'],
    ]);
  }

  /**
   * Creates the affinity group bundle used by several tests.
   *
   * Public: body, field_ag_goals. Private: field_coordinator,
   * field_ag_private, field_ag_private_users.
   */
  protected function createAffinityGroupBundle(): void {
    $this->createTextBundle('affinity_group', [
      'body' => 'text_long',
      'field_ag_goals' => ['type' => 'text_long', 'label' => 'Goals'],
    ], [
      'field_coordinator' => ['type' => 'string', 'label' => 'Coordinator'],
      'field_ag_private' => ['type' => 'boolean', 'label' => 'Private'],
      'field_ag_private_users' => ['type' => 'entity_reference', 'label' => 'Private users'],
    ]);
  }

  /**
   * Calls the per-id endpoint and returns the response.
   */
  protected function requestById(int|string $id): Response {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(ContentController::class);
    return $controller->byId(Request::create('/api/1.0/content/' . $id), (int) $id);
  }

  /**
   * Calls the by-path endpoint and returns the response.
   */
  protected function requestByPath(string $path): Response {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(ContentController::class);
    return $controller->byPath(Request::create('/api/1.0/content', 'GET', ['path' => $path]));
  }

  /**
   * Calls the index endpoint and returns the response.
   */
  protected function requestIndex(): CacheableResponseInterface {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(ContentIndexController::class);
    return $controller->index();
  }

  /**
   * Decodes a JSON response body.
   */
  protected function decode(Response $response): array {
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Returns the node IDs listed in an index response.
   *
   * @return int[]
   *   The node IDs, parsed from each entry's content_url.
   */
  protected function indexedNids(Response $response): array {
    $nids = [];
    foreach ($this->decode($response)['pages'] as $page) {
      $this->assertMatchesRegularExpression('#/api/1\.0/content/(\d+)$#', $page['content_url']);
      preg_match('#/(\d+)$#', $page['content_url'], $m);
      $nids[] = (int) $m[1];
    }
    return $nids;
  }

  /**
   * Stores a cacheable response in the default bin under its own cache tags.
   *
   * Mirrors what a page cache does with the response, so tests can assert that
   * a later entity save invalidates it.
   *
   * @return string
   *   The cache ID, for isCached().
   */
  protected function cacheResponse(CacheableResponseInterface $response, string $cid): string {
    \Drupal::cache()->set($cid, $response->getContent(), Cache::PERMANENT, $response->getCacheableMetadata()->getCacheTags());
    $this->assertTrue($this->isCached($cid), 'Freshly cached response is valid.');
    return $cid;
  }

  /**
   * Returns TRUE if the cache entry is still valid (not tag-invalidated).
   */
  protected function isCached(string $cid): bool {
    return \Drupal::cache()->get($cid) !== FALSE;
  }

}
