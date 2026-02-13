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
          $eiid[] = [
            'id' => $ei,
            'attached_to' => 'event',
          ];
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
            $eiid[] = [
              'id' => $ei,
              'attached_to' => 'ag',
            ];
          }
        }
      }
    }
    $event_list = [];
    if (!empty($eiid)) {
      foreach ($eiid as $ei) {
        $eid = $ei['id'];
        $type = $ei['attached_to'];
        $event = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($eid);

        $eventseries = $event->getEventSeries();

        // Check if event is set to share on Affinity Group page.
        if ($type == 'event') {
          $where = $eventseries->get('field_choose_where_to_share_this')->getValue();
          $show_on_ag_page = FALSE;
          foreach ($where as $w) {
            if ($w['value'] == 'on_your_affinity_group_page') {
              $show_on_ag_page = TRUE;
            }
          }
        } else {
          // Event added directly to Affinity Group, so show it.
          $show_on_ag_page = TRUE;
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
            '#url' => Url::fromUri('internal:/events/' . $eid),
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
          $event_list[$eid] = [
            'date' => $event_date,
            'title' => $link_name,
          ];
        }
        // Sort events by date.
        usort($event_list, fn($a, $b) => $a['date'] <=> $b['date']);
      }
    }
    $output = '<div class="bg-md-teal mb-10 not-prose"><div class="p-4">';
    $output .= '<h2 class="text-white-er text-xl font-semibold mt-0 mb-3">Upcoming Events</h2>';
    $affinity_group_tax = '';
    $affinity_group_title = '';
    if ($node) {
      $affinity_group_tax = $node->get('field_affinity_group')->getValue()[0]['target_id'];
      // Get the affinity group title for use in facet URLs.
      $affinity_group_title = $node->getTitle();
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
        $output .= '<a class="text-sm uppercase text-white-er hover--text-light-teal no-underline hover--underline" href="/events?f[0]=affinity_group:' . urlencode($affinity_group_title) . '">See more events</a><br />';
      }
    }
    else {
      $output .= '<div class="text-white-er my-2">No upcoming events.</div>';
    }
    $output .= '<a class="text-sm uppercase text-white-er hover--text-light-teal no-underline hover--underline" href="/events/past?f[0]=affinity_group:' . urlencode($affinity_group_title) . '">See past events</a>';
    $output .= '</div></div>';

    // Display Announcements that have been assigned to the Affinity Group
    // and Announcements added as entity references to the Affinity Group.

    /**
    * Adding a default for layout page.
    */
    $nid = $node ? $node->id() : 291;

    // Build combined announcement list from both sources.
    $announcement_nids = [];

    // Get announcements added as entity references to the Affinity Group.
    if ($node && $node->hasField('field_affinity_announcements')) {
      $ag_announcements = $node->get('field_affinity_announcements')->getValue();
      foreach ($ag_announcements as $announcement) {
        $announcement_nids[$announcement['target_id']] = $announcement['target_id'];
      }
    }

    // Get announcements from the view (those that reference this affinity group).
    $announcement_view = Views::getView('access_news');
    $announcement_view->setDisplay('block_2');
    $announcement_view->setArguments([$nid]);
    $announcement_view->execute();

    // Extract nids from view results.
    foreach ($announcement_view->result as $row) {
      if (isset($row->nid)) {
        $announcement_nids[$row->nid] = $row->nid;
      }
    }

    // Build the announcement output.
    $output .= '<div class="bg-md-teal mb-10"><div class="p-4">';
    $output .= '<h2 class="border-bottom pb-2 text-xl text-white-er font-semibold mt-0">Announcements</h2>';

    if (!empty($announcement_nids)) {
      // Load and render announcements.
      $announcements = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($announcement_nids);

      // Sort by published date descending.
      uasort($announcements, function ($a, $b) {
        $date_a = $a->get('field_published_date')->value ?? $a->getCreatedTime();
        $date_b = $b->get('field_published_date')->value ?? $b->getCreatedTime();
        return $date_b <=> $date_a;
      });

      $count = 0;
      foreach ($announcements as $announcement) {
        // Only show published announcements.
        if (!$announcement->isPublished()) {
          continue;
        }
        $count++;
        if ($count > 4) {
          break;
        }
        $pub_date = $announcement->get('field_published_date')->value;
        $formatted_date = $pub_date ? date('m/d/y', strtotime($pub_date)) : '';
        $title = $announcement->getTitle();
        $url = $announcement->toUrl()->toString();

        $output .= '<div class="mb-3 not-prose">';
        $output .= '<div class="text-white-er leading-5 font-medium"><span class="text-white-er">' . $formatted_date . '</span><br /></div>';
        $output .= '<div><a href="' . $url . '" class="block text-white-er hover--text-light-teal font-medium leading-5 no-underline hover--underline" title="' . htmlspecialchars($title) . '">' . htmlspecialchars($title) . '</a></div>';
        $output .= '</div>';
      }

      if (count($announcement_nids) > 4) {
        $output .= '<a class="text-sm uppercase text-white-er hover--text-light-teal no-underline hover--underline" href="/announcements?field_affinity_group_target_id=' . $affinity_group_tax . '">See More</a>';
      }
    }
    else {
      $output .= '<div class="text-white-er my-2">No announcements for this group.</div>';
    }
    $output .= '</div></div>';
    $domain = \Drupal::service('access_misc.sitetools')->getDomain();
    if ($domain == 'open-ondemand') {
      $output = str_replace('class="card mt-4 p-3"', '', $output);
    }

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
    $tags = parent::getCacheTags();
    // Add node_list tag to invalidate when any node (including announcements) changes.
    $tags = Cache::mergeTags($tags, ['node_list:access_news']);
    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      $tags = Cache::mergeTags($tags, ['node:' . $node->id()]);
    }
    return $tags;
  }

  /**
   *
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
