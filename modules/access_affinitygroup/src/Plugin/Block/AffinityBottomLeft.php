<?php

namespace Drupal\access_affinitygroup\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\views\Views;

/**
 * Provides a button to contact affinity group.
 *
 * @todo rename this since it is on the right side now.
 *
 * @Block(
 *   id = "affinity_bottom_left",
 *   admin_label = "Affinity Group right section",
 * )
 */
class AffinityBottomLeft extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $output = '';
    $node = \Drupal::routeMatch()->getParameter('node');
    // Default for Layout Builder.
    $nid = $node ? $node->id() : 219;

    // Combine events added to the Affinity Group as entity references
    // with events that have reference the Affinity Group taxonomy term.
    // First get events that reference the Affinity Group taxonomy term.
    $query = \Drupal::entityQuery('eventseries')
      ->condition('status', 1)
      ->condition('field_affinity_group_node', $nid, '=')
      ->accessCheck(TRUE)
      ->sort('created', 'DESC');
    $esid = $query->execute();
    foreach ($esid as $es) {
      $eiids = [];
      $eiids[] = $this->getEventInstances($es);
      foreach ($eiids as $e) {
        foreach ($e as $ei) {
          $eiid[] = [$ei];
        }
      }
    }
    // Now get events added to the Affinity Group as entity references.
    if ($node) {
      $field_event = $node->get('field_affinity_events')->getValue();
      foreach ($field_event as $event) {
        $eiids = [];
        $eiids[] = $this->getEventInstances($event['target_id']);
        foreach ($eiids as $e) {
          foreach ($e as $ei) {
            $eiid[] = [$ei];
          }
        }
      }
    }
    $event_list = [];
    if (!empty($eiid)) {
      foreach ($eiid as $ei) {
        $ei = reset($ei);
        $event = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($ei);

        $eventseries = $event->getEventSeries();

        // Check if event is set to share on Affinity Group page.
        $where = $eventseries->get('field_choose_where_to_share_this')->getValue();
        $show_on_ag_page = FALSE;
        foreach ($where as $w) {
          if ($w['value'] == 'on_your_affinity_group_page') {
            $show_on_ag_page = TRUE;
          }
        }

        $event_status = $event->get('status')->getValue()[0]['value'];
        $event_date = $event->get('date')->getValue()[0]['value'];
        // Setup date in same format as today's date so I can get future events.
        $start_date = date_create($event_date);
        $edate = date_format($start_date, "Y-m-d");
        $date_now = date("Y-m-d");
        if ($event_status && $date_now <= $edate && $show_on_ag_page) {
          $series = $event->getEventSeries();
          $series_title = $series->get('title')->getValue()[0]['value'];
          $link = [
            '#type' => 'link',
            '#title' => $series_title,
            '#url' => Url::fromUri('internal:/events/' . $ei),
            '#attributes' => [
              'class' => [
                'block',
                'text-white-er',
                'hover--text-light-teal',
                'no-underline',
                'hover--underline',
              ],
            ],
          ];
          $link_name = \Drupal::service('renderer')->render($link)->__toString();
          $event_list[$ei] = [
            'date' => $event_date,
            'title' => $link_name,
          ];
        }
        // Sort events by date.
        usort($event_list, fn($a, $b) => $a['date'] <=> $b['date']);
      }
    }
    $output = '<div class="bg-md-teal p-4 mb-10 not-prose">';
    $output .= '<h2 class="text-white-er text-xl font-semibold mt-0 mb-3">Upcoming Events</h2>';
    $affinity_group_tax = '';
    if ($node) {
      $affinity_group_tax = $node->get('field_affinity_group')->getValue()[0]['target_id'];
    }
    if (!empty($event_list)) {
      $n = 0;
      foreach ($event_list as $e) {
        $n++;
        if ($n > 8) {
          break;
        }
        // Incoming time is UTC, so convert to local timezone.
        $start_date = new \DateTime($e['date'], new \DateTimeZone("UTC"));
        $start_date = $start_date->setTimeZone(new \DateTimeZone(date_default_timezone_get()));
        $edate = date_format($start_date, "n/d/Y g:i A T");
        $output .= '<div class="mb-3 text-white-er font-medium leading-5">' . $edate . '<br/>' . $e['title'] . '</div>';
      }
      if (count($event_list) > 8) {
        $output .= '<a class="text-sm uppercase text-white-er hover--text-light-teal no-underline hover--underline" href="/events-trainings?field_affinity_group_target_id_1=' . $affinity_group_tax . '">See more events</a><br />';
      }
    }
    else {
      $output .= '<div class="text-white-er my-2">No upcoming events.</div>';
    }
    $output .= '<a class="text-sm uppercase text-white-er hover--text-light-teal no-underline hover--underline" href="/past-events?field_affinity_group_target_id=' . $affinity_group_tax . '">See past events</a>';
    $output .= '</div>';

    // Display Announcements that have been assigned to the Affinity Group
    // and Announcements added as entity references to the Affinity Group.
    // @todo add announcements added to the Affinity Group as entity references.

    /**
    * Adding a default for layout page.
    */
    $nid = $node ? $node->id() : 291;

    /**
    * Load Announcement view.
    */
    $announcement_view = Views::getView('access_news');
    $announcement_view->setDisplay('block_2');
    $announcement_view->setArguments([$nid]);
    $announcement_view->execute();
    $announcement_list = $announcement_view->render();
    $output .= '<div class="bg-md-teal p-4 mb-10">';
    $output .= \Drupal::service('renderer')->render($announcement_list);
    if ($announcement_list['#rows']) {
      $announcment_count = count($announcement_list['#rows'][0]['#rows']);
      if ($announcment_count > 4) {
        $output .= '<a class="text-sm uppercase text-white-er hover--text-light-teal no-underline hover--underline" href="/announcements?field_affinity_group_target_id=' . $affinity_group_tax . '">See More</a>';
      }
    }
    $output .= '</div>';

    return [
      ['#markup' => $output],
    ];
  }

  /**
   * Helper method to load event instances.
   */
  private function getEventInstances($esid) {
    $query = \Drupal::entityQuery('eventinstance')
      ->condition('status', 1)
      ->condition('eventseries_id', $esid, '=')
      ->accessCheck(TRUE)
      ->sort('date', 'DESC');
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      return Cache::mergeTags(parent::getCacheTags(), ['node:' . $node->id()]);
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   *
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
