<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the unpaged Researcher Stories listing (D8-3098).
 *
 * The tool_case_study view at /tools/researcher-stories used a mini pager of
 * ten rows. Stories that reference several ACCESS resources produce one row
 * per resource, and access_misc_views_pre_render() removes the duplicates only
 * after the query, so page 1 showed about six stories and the rest were hidden
 * behind "Next page". The view now has no pager.
 *
 * access_misc is not enabled here (its dependency chain is large and
 * unrelated), so the module file is loaded directly and its pre_render hook
 * is called by hand, as on a real render.
 *
 * @group access_misc
 */
class ResearcherStoriesListingTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'views',
  ];

  /**
   * The researcher every story points at.
   */
  private int $researcherUid;

  /**
   * Resource nodes that stories reference.
   *
   * @var int[]
   */
  private array $resourceNids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);

    // The two fields the view joins through as relationships. The access
    // resource field is multi-value, which is what creates duplicate rows.
    $this->importSiteConfig([
      'node.type.tools_case_study',
      'field.storage.node.field_tcs_researcher_name',
      'field.field.node.tools_case_study.field_tcs_researcher_name',
      'field.storage.node.field_tcs_access_resource',
      'field.field.node.tools_case_study.field_tcs_access_resource',
    ]);
    $this->importViewConfigRaw('views.view.tool_case_study');

    require_once dirname(__DIR__, 3) . '/access_misc.module';

    // Burn uid 1, whose superuser access would bypass node access checks.
    $this->createUser();
    $this->researcherUid = (int) $this->createUser()->id();
    $this->setCurrentUser($this->createUser(['access content']));

    $this->createBareContentType('access_active_resources_from_cid');
    foreach (['Resource A', 'Resource B'] as $title) {
      $resource = Node::create([
        'type' => 'access_active_resources_from_cid',
        'title' => $title,
      ]);
      $resource->save();
      $this->resourceNids[] = (int) $resource->id();
    }
  }

  /**
   * More than ten published stories all list once, unpublished ones never.
   */
  public function testListsEveryPublishedStoryOnce(): void {
    $published = [];
    // Twelve stories, every one linked to both resources: 24 rows before
    // de-duplication, so any leftover row limit would cut stories off.
    for ($i = 1; $i <= 12; $i++) {
      $published[] = $this->makeStory("Story $i", $this->resourceNids);
    }
    $unpublished = $this->makeStory('Draft story', [$this->resourceNids[0]], NodeInterface::NOT_PUBLISHED);

    $view = $this->executeListing();

    $nids = $this->resultNids($view);
    $this->assertCount(12, $nids, 'Each published story is listed exactly once.');
    $this->assertEqualsCanonicalizing($published, $nids);
    $this->assertNotContains($unpublished, $nids);

    $this->assertSame('none', $view->pager->getPluginId());
    $this->assertFalse($view->pager->usePager());
    $this->assertSame(0, (int) $view->pager->getItemsPerPage());
  }

  /**
   * Old ?page=N links load the full listing instead of an empty page.
   */
  public function testOldPageParameterShowsFullListing(): void {
    $published = [];
    for ($i = 1; $i <= 12; $i++) {
      $published[] = $this->makeStory("Story $i", [$this->resourceNids[$i % 2]]);
    }

    foreach (['1', '5'] as $page) {
      $nids = $this->resultNids($this->executeListing(['page' => $page]));
      $this->assertEqualsCanonicalizing($published, $nids, "?page=$page lists every story.");
    }
  }

  /**
   * Stories keep the newest-first sort.
   */
  public function testNewestStoryFirst(): void {
    $older = $this->makeStory('Older', [], NodeInterface::PUBLISHED, 1700000000);
    $newer = $this->makeStory('Newer', [], NodeInterface::PUBLISHED, 1800000000);

    $this->assertSame([$newer, $older], $this->resultNids($this->executeListing()));
  }

  /**
   * Runs page_1 as a render would, including access_misc's de-duplication.
   *
   * @param array<string, string> $query
   *   Query string parameters on the request.
   */
  private function executeListing(array $query = []): ViewExecutable {
    // Pagers read ?page from the request stack (pager.parameters), not from
    // the view's own request, so push the request there too.
    $request = Request::create('/tools/researcher-stories', 'GET', $query);
    $request->setSession($this->container->get('session'));
    $this->container->get('request_stack')->push($request);

    $view = Views::getView('tool_case_study');
    $this->assertNotNull($view);
    $view->setRequest($request);
    $view->setDisplay('page_1');
    $view->execute();
    access_misc_views_pre_render($view);
    return $view;
  }

  /**
   * The story nids in result order.
   *
   * @return int[]
   *   Node IDs.
   */
  private function resultNids(ViewExecutable $view): array {
    return array_values(array_map(fn($row) => (int) $row->nid, $view->result));
  }

  /**
   * Creates a researcher story.
   *
   * @param string $title
   *   Node title.
   * @param int[] $resourceNids
   *   ACCESS resources the story references.
   * @param int $status
   *   Publishing status.
   * @param int|null $created
   *   Created timestamp, when the sort order matters.
   *
   * @return int
   *   The new node ID.
   */
  private function makeStory(string $title, array $resourceNids, int $status = NodeInterface::PUBLISHED, ?int $created = NULL): int {
    $values = [
      'type' => 'tools_case_study',
      'title' => $title,
      'status' => $status,
      'field_tcs_researcher_name' => $this->researcherUid,
      'field_tcs_access_resource' => $resourceNids,
    ];
    if ($created !== NULL) {
      $values['created'] = $created;
    }
    $story = Node::create($values);
    $story->save();
    return (int) $story->id();
  }

  /**
   * Creates a bare content type.
   */
  private function createBareContentType(string $type): void {
    $this->container->get('entity_type.manager')
      ->getStorage('node_type')
      ->create(['type' => $type, 'name' => $type])
      ->save();
  }

  /**
   * Imports config entities from the site's sync directory.
   *
   * Module dependencies and third-party settings for modules not enabled
   * here are dropped so the config schema checker does not trip.
   *
   * @param string[] $names
   *   Config object names.
   */
  private function importSiteConfig(array $names): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $configManager = $this->container->get('config.manager');
    $moduleHandler = $this->container->get('module_handler');
    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing site config: $name");
      }
      foreach (array_keys($data['third_party_settings'] ?? []) as $module) {
        if (!$moduleHandler->moduleExists($module)) {
          unset($data['third_party_settings'][$module]);
        }
      }
      if (isset($data['dependencies']['module'])) {
        $data['dependencies']['module'] = array_values(array_filter(
          $data['dependencies']['module'],
          fn($module) => $moduleHandler->moduleExists($module),
        ));
      }
      // cider_resource is a selection plugin from a module not enabled here.
      if (($data['settings']['handler'] ?? NULL) === 'cider_resource') {
        $data['settings']['handler'] = 'default:node';
      }
      $entityTypeId = $configManager->getEntityTypeIdByName($name);
      $this->container->get('entity_type.manager')
        ->getStorage($entityTypeId)
        ->createFromStorageRecord($data)
        ->save();
    }
  }

  /**
   * Writes a view straight to config storage.
   *
   * The view names formatters and field storages this test does not install
   * (images, SVG, taxonomy fields). Saving it as an entity would fail
   * dependency and schema checks; written raw, it loads and executes, with
   * those fields as broken handlers that add nothing to the query.
   */
  private function importViewConfigRaw(string $name): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $data = $source->read($name);
    if ($data === FALSE) {
      throw new \RuntimeException("Missing site config: $name");
    }
    $this->container->get('config.storage')->write($name, $data);
  }

}
