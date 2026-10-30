<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the homepage events block (D8-2934).
 *
 * The ACCESS Support homepage shows the next seven upcoming events from the
 * recurring_events_event_instances view's homepage_events display. The display
 * is deployed config, so this loads the real YAML and asserts its date, status,
 * sort and limit behavior against fixture events.
 *
 * @group access_events
 */
class HomepageEventsViewTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'path_alias'];

  /**
   * {@inheritdoc}
   *
   * The deployed view carries keys that Views core and jsonapi_views do not
   * declare in config schema (sort granularity, use_tokens, the jsonapi_views
   * display extender) on displays this test never runs. Strict validation
   * would fail on those pre-existing gaps rather than on anything under test.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = Yaml::parseFile(DRUPAL_ROOT . '/sites/default/config/default/views.view.recurring_events_event_instances.yml');
    $this->assertArrayHasKey('homepage_events', $config['display']);

    // The domain-access and where-to-share filters read tables that belong to
    // the site's configured fields (domain_access on eventinstance and
    // field_choose_where_to_share_this on eventseries). Neither exists in this
    // minimal kernel fixture, and standing up domain + field config would not
    // change what is under test here, which is the date/status/sort/limit
    // logic. Remove just those two handlers; every other filter, the sorts and
    // the pager are exercised exactly as deployed.
    // They are also stripped from the view's other displays, because saving the
    // view validates every display against config schema.
    $removed = 0;
    foreach ($config['display'] as $id => $display) {
      foreach (['domain_access_target_id', 'field_choose_where_to_share_this_value'] as $filter) {
        if (isset($display['display_options']['filters'][$filter])) {
          unset($config['display'][$id]['display_options']['filters'][$filter]);
          $removed++;
        }
      }
    }
    $this->assertGreaterThanOrEqual(2, $removed);
    // Likewise the tags relationship, which needs the site's tags field on
    // eventseries; no field on this display uses it.
    foreach ($config['display'] as $id => $display) {
      unset($config['display'][$id]['display_options']['relationships']['field_tags']);
    }
    // Views data was built before the eventinstance/eventseries entity types
    // and their computed inherited fields existed; rebuild it.
    \Drupal::service('views.views_data')->clear();
    View::create($config)->save();
  }

  /**
   * Creates a published series with one published instance.
   *
   * @param string $title
   *   The series title.
   * @param string $start
   *   Start, as a UTC datetime string.
   * @param bool $published
   *   Whether the instance is published.
   */
  protected function createInstanceAt(string $title, string $start, bool $published = TRUE): EventInstance {
    $end = date('Y-m-d\TH:i:s', strtotime($start . ' UTC') + 3600);
    $series = EventSeries::create([
      'title' => $title,
      'body' => 'Body.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $series->save();
    $this->publishModerated($series);

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => $start, 'end_value' => $end],
    ]);
    $instance->save();
    if ($published) {
      $this->publishModerated($instance);
    }
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, (int) $series->id());
    return $instance;
  }

  /**
   * Runs the display and returns the result titles in order.
   *
   * @return string[]
   *   The event series titles.
   */
  protected function runDisplay(): array {
    $view = Views::getView('recurring_events_event_instances');
    $view->setDisplay('homepage_events');
    $view->execute();
    $titles = [];
    foreach ($view->result as $row) {
      $titles[] = $row->_entity->get('eventseries_id')->entity->label();
    }
    return $titles;
  }

  /**
   * Shows at most 7 future events, soonest first, and no past events.
   */
  public function testSevenSoonestFutureEventsOnly(): void {
    // Create the future events out of order so the sort, not insertion order,
    // is what the assertion sees.
    foreach ([5, 9, 2, 8, 3, 11, 4, 7, 6] as $days) {
      $this->createInstanceAt('Future ' . $days, date('Y-m-d\TH:i:s', strtotime("+$days days")));
    }
    $this->createInstanceAt('Past 1', date('Y-m-d\TH:i:s', strtotime('-3 days')));
    $this->createInstanceAt('Past 2', date('Y-m-d\TH:i:s', strtotime('-30 days')));

    $titles = $this->runDisplay();

    $this->assertSame(
      ['Future 2', 'Future 3', 'Future 4', 'Future 5', 'Future 6', 'Future 7', 'Future 8'],
      $titles,
      'exactly the seven soonest future events, soonest first, no past events'
    );
  }

  /**
   * Unpublished instances never appear.
   */
  public function testUnpublishedInstanceExcluded(): void {
    $this->createInstanceAt('Draft', date('Y-m-d\TH:i:s', strtotime('+1 day')), FALSE);
    $this->createInstanceAt('Live', date('Y-m-d\TH:i:s', strtotime('+2 days')));

    $this->assertSame(['Live'], $this->runDisplay());
  }

  /**
   * Fewer than seven upcoming events renders only those.
   */
  public function testFewerThanSevenEvents(): void {
    $this->createInstanceAt('B', date('Y-m-d\TH:i:s', strtotime('+4 days')));
    $this->createInstanceAt('A', date('Y-m-d\TH:i:s', strtotime('+3 days')));

    $this->assertSame(['A', 'B'], $this->runDisplay());
  }

}
