<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Drupal\webform\Entity\Webform;

/**
 * Tests that the public resource views never list private KB resources.
 *
 * The real production views (views.view.resources and
 * views.view.resources_entity_reference) are imported from the site config
 * sync directory, so the approved filter and the query alter in
 * access_cilink_views_query_alter() run exactly as in production.
 *
 * @group access_cilink
 */
class ResourcesViewPrivateTest extends KbResourceKernelTestBase {

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
    'taxonomy',
    'webform',
    'flag',
    'domain',
    'page_cache',
    'views',
    'webform_views',
    'better_exposed_filters',
    'access_cilink',
    'access_cilink_test',
  ];

  /**
   * {@inheritdoc}
   *
   * The production views carry settings from contrib display extenders
   * (simple_sitemap, jsonapi_views, views_merge_rows) that are not installed
   * here, so their config would fail strict schema validation.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('view');
    $this->installConfig(['views']);
    // As in production (webform.webform.resource), everyone may view any
    // resource submission. Without this the webform_views query access alter
    // adds a "1 = 0" condition for anonymous and every view comes back empty.
    $webform = Webform::load('resource');
    $access = $webform->getAccessRules();
    $access['view_any']['roles'] = ['anonymous', 'authenticated'];
    $webform->setAccessRules($access)->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    foreach (['resources', 'resources_entity_reference'] as $id) {
      $this->importView($id);
    }
  }

  /**
   * Imports a view from the site config sync directory.
   *
   * Stripped, because the kernel environment cannot satisfy them:
   * - The dependency list, which names config such as menus and fields that
   *   are not installed.
   * - The "raw" contextual filter default argument plugin (used by the
   *   block_2 and page_2 displays), which needs the path_alias module and its
   *   schema. It is swapped for a "fixed" default. None of the displays under
   *   test use it.
   * - The reverse node relationship on field_resources_entity_reference, whose
   *   field storage is not installed here. No field, filter or sort uses it.
   * The base table, displays, handlers and filters (including the approved
   * filter) are kept as shipped.
   */
  protected function importView(string $id): void {
    $storage = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $data = $storage->read('views.view.' . $id);
    $this->assertIsArray($data, "views.view.$id exists in the config sync directory.");
    unset($data['uuid'], $data['dependencies'], $data['_core']);
    foreach ($data['display'] as &$display) {
      unset($display['display_options']['relationships']['reverse__node__field_resources_entity_reference']);
    }
    unset($display);
    array_walk_recursive($data, function (&$value, $key) {
      if ($key === 'default_argument_type' && $value === 'raw') {
        $value = 'fixed';
      }
    });
    View::create($data)->save();
  }

  /**
   * Executes a view display and returns the sorted result sids.
   *
   * @return int[]
   *   Submission IDs.
   */
  protected function sids(string $view_id, string $display): array {
    $view = Views::getView($view_id);
    $this->assertNotNull($view, "View $view_id loads.");
    $view->setDisplay($display);
    $view->execute();
    $sids = array_map(fn($row) => (int) $row->sid, $view->result);
    sort($sids);
    return $sids;
  }

  /**
   * Public and legacy resources are listed, private and unapproved are not.
   *
   * @dataProvider displayProvider
   */
  public function testPrivateResourcesAreNotListed(string $view_id, string $display): void {
    $public = $this->createResource(['title' => 'Public', 'private' => 0]);
    $private = $this->createResource(['title' => 'Private', 'private' => 1]);
    // Saved before the private element existed: no private data row at all.
    $legacy = $this->createResource(['title' => 'Legacy', 'private' => NULL]);
    $unapproved = $this->createResource(['title' => 'Unapproved', 'approved' => 0]);

    $sids = $this->sids($view_id, $display);

    $this->assertNotContains((int) $private->id(), $sids, 'The private resource is not listed.');
    $this->assertContains((int) $public->id(), $sids, 'The public resource is listed.');
    $this->assertContains((int) $legacy->id(), $sids, 'A resource with no private value is listed.');
    $this->assertNotContains((int) $unapproved->id(), $sids, 'The approved filter still applies.');
  }

  /**
   * Data provider.
   *
   * @return array<string, array{string, string}>
   *   View ID and display ID.
   */
  public static function displayProvider(): array {
    return [
      'resources page_1' => ['resources', 'page_1'],
      'resources resource_card' => ['resources', 'resource_card'],
      'resources_entity_reference' => ['resources_entity_reference', 'default'],
    ];
  }

}
