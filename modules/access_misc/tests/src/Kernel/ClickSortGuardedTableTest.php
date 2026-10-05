<?php

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\access_misc\Plugin\views\style\ClickSortGuardedTable;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that table views ignore ?order= values they do not offer.
 *
 * Bots requesting ?order=<non-sortable field> made core's Table style
 * click-sort on a field with no query alias, producing ORDER BY "unknown"
 * and a 500. The "nothing" (Global: Custom text) field reproduces that: it
 * adds nothing to the query, so core's table would sort on "unknown".
 *
 * access_misc itself is not enabled here (see FlagLinkBuilderDecoratorTest
 * for why), so setUp() runs its style alter hook against the real plugin
 * definitions and primes the discovery cache with the result.
 *
 * @coversDefaultClass \Drupal\access_misc\Plugin\views\style\ClickSortGuardedTable
 * @group access_misc
 */
class ClickSortGuardedTableTest extends ViewsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $testViews = ['test_table'];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp($import_test_views);

    require_once dirname(__DIR__, 3) . '/access_misc.module';
    $manager = $this->container->get('plugin.manager.views.style');
    $definitions = $manager->getDefinitions();
    access_misc_views_plugins_style_alter($definitions);
    $manager->clearCachedDefinitions();
    $this->container->get('cache.discovery')->set('views:style', $definitions);
  }

  /**
   * Executes test_table for a request with the given query string.
   *
   * An excluded "nothing" field is added so a request can target a field
   * that is in the view but has no query alias.
   */
  protected function executeTable(array $query) {
    $view = Views::getView('test_table');
    $view->setRequest(Request::create('/', 'GET', $query));
    $view->setDisplay();
    $view->addHandler('default', 'field', 'views', 'nothing', ['exclude' => TRUE]);
    $view->execute();
    return $view;
  }

  /**
   * The alter hook swaps core's table style for the guarded one.
   */
  public function testTableStyleIsSwapped(): void {
    $view = $this->executeTable([]);
    $this->assertInstanceOf(ClickSortGuardedTable::class, $view->style_plugin);
  }

  /**
   * An order on a field with no query alias falls back to the default sort.
   *
   * @covers ::buildSortPost
   */
  public function testOrderOnUnqueriedFieldIsIgnored(): void {
    $view = $this->executeTable(['order' => 'nothing', 'sort' => 'desc']);

    // test_table's default column is id, with a default order of desc.
    $this->assertSame('id', $view->style_plugin->active);
    $this->assertSame('desc', $view->style_plugin->order);
    $this->assertIdenticalResultset($view, [
      ['id' => 5], ['id' => 4], ['id' => 3], ['id' => 2], ['id' => 1],
    ], ['id' => 'id']);
    // The shared request still carries the original query for other views.
    $this->assertSame('nothing', $view->getRequest()->query->get('order'));
  }

  /**
   * An order on a queried column not marked sortable is ignored.
   *
   * @covers ::buildSortPost
   */
  public function testOrderOnNonSortableColumnIsIgnored(): void {
    $view = $this->executeTable(['order' => 'age', 'sort' => 'asc']);

    $this->assertSame('id', $view->style_plugin->active);
    $this->assertSame('desc', $view->style_plugin->order);
  }

  /**
   * An order on a sortable column still click-sorts.
   *
   * @covers ::buildSortPost
   */
  public function testOrderOnSortableColumnApplies(): void {
    $view = $this->executeTable(['order' => 'name', 'sort' => 'asc']);

    $this->assertSame('name', $view->style_plugin->active);
    $this->assertSame('asc', $view->style_plugin->order);
    // George, John, Meredith, Paul, Ringo.
    $this->assertIdenticalResultset($view, [
      ['id' => 2], ['id' => 1], ['id' => 5], ['id' => 4], ['id' => 3],
    ], ['id' => 'id']);
  }

  /**
   * An order on a field that is not in the view is ignored.
   *
   * @covers ::isClickSortable
   */
  public function testOrderOnUnknownFieldIsIgnored(): void {
    $view = $this->executeTable(['order' => 'webform_submission_value_1']);

    $this->assertSame('id', $view->style_plugin->active);
  }

}
