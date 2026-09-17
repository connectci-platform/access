<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Symfony\Component\Yaml\Yaml;

/**
 * Tests that every events API export display is registered with its hooks.
 *
 * The API versions are Views data_export displays on events_facet, and three
 * hooks in access_events.module have to know about each one:
 *
 * - access_events_search_api_query_alter() applies the domain condition at
 *   QUERY level. Without it the domain restriction happens after the pager has
 *   already limited the rows, so a domain-scoped host asked for 5 events gets
 *   the first 5 site-wide, filters them to its own domain, and returns none.
 * - access_events_search_api_db_query_alter() replaces multi-value JOINs with
 *   EXISTS subqueries so duplicate rows do not inflate the pager count.
 * - access_events_views_pre_build() resolves the relative date filters.
 *
 * All three carry a hard-coded list of display ids. Adding a new API version
 * without adding it to those lists produces an endpoint that looks fine on the
 * main domain and returns nothing on every other one — which is exactly what
 * happened when api/2.4 (data_export_5) was added.
 *
 * This asserts the lists and the displays agree, so the next version cannot be
 * added without either updating them or failing here.
 *
 * @group access_events
 */
class EventsApiExportRegistrationTest extends EventKernelTestBase {

  /**
   * Every data_export display on events_facet is registered with all 3 hooks.
   */
  public function testEveryApiExportDisplayIsRegisteredWithItsHooks(): void {
    // Read the deployed view config from disk rather than the entity storage:
    // this asserts a consistency property of the config and the module code,
    // and installing views plus a search_api index into the kernel fixture to
    // learn a display id would be a lot of machinery for no extra confidence.
    $configFile = DRUPAL_ROOT . '/sites/default/config/default/views.view.events_facet.yml';
    $this->assertFileExists($configFile);
    $view = Yaml::parseFile($configFile);

    $exportDisplays = [];
    foreach ($view['display'] as $id => $display) {
      if (($display['display_plugin'] ?? '') === 'data_export') {
        $exportDisplays[] = $id;
      }
    }
    $this->assertNotEmpty($exportDisplays, 'the view has data_export displays');

    $module = file_get_contents(
      \Drupal::service('extension.list.module')->getPath('access_events') . '/access_events.module'
    );

    // Scope to api/2.3 and 2.4, the displays this handling is built for.
    //
    // The others are deliberately out. api/2.0 and 2.1 (data_export_1, _2)
    // predate it entirely — they ignore the pager and are not domain-filtered
    // at all. api/2.2 (data_export_3) has the same paging defect, but the
    // whitelist applies the where_to_share announcements condition alongside
    // the domain one, and 2.2 has never carried that: adding it would change
    // what the endpoint returns, not just how it pages. Fixing 2.2 needs the
    // two conditions separated first, which is its own piece of work.
    $mustBeRegistered = ['data_export_4', 'data_export_5'];
    foreach (array_intersect($exportDisplays, $mustBeRegistered) as $id) {
      // The two search_api hooks match on the full search id.
      $searchId = 'views_data_export:events_facet__' . $id;
      $this->assertSame(
        2,
        substr_count($module, "'" . $searchId . "'"),
        sprintf(
          'display %s must appear in BOTH search_api hook whitelists — the '
          . 'query_alter one applies the domain condition at query level, and '
          . 'without it this endpoint returns nothing on any domain but the '
          . 'default',
          $id
        )
      );

      // And views_pre_build resolves the relative date filters.
      $this->assertStringContainsString(
        "'" . $id . "'",
        $module,
        sprintf('display %s must be listed in access_events_views_pre_build()', $id)
      );
    }

    // Guard the thing that actually went wrong: a NEW export display added
    // without being registered. If someone adds data_export_6, this fails
    // until they decide which handling it needs.
    $known = ['data_export_1', 'data_export_2', 'data_export_3', 'data_export_4', 'data_export_5'];
    $this->assertSame([], array_diff($exportDisplays, $known),
      'a new events API export display must be registered with the search_api '
      . 'and views_pre_build hooks in access_events.module, or it will return '
      . 'nothing on every domain but the default');
  }

}
