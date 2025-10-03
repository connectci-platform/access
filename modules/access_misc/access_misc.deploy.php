<?php

/**
 * @file
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;
use Drupal\webform\WebformSubmissionForm;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\search_api\Entity\Index;

/**
 * My drush deploy hook example.
 */
function access_misc_deploy_10000_people() {
  // ADD YOUR CUSTOM CODE HERE.
  \Drupal::messenger()->addMessage('Deploy hook triggered');
  // Delete nodes 66 and 67.
  $nids = [66, 67];
  foreach ($nids as $nid) {
    $node = Node::load($nid);
    if ($node) {
      $node->delete();
    }
  }
  // Redirect people/list to /people.
  Redirect::create([
    'redirect_source' => 'people/list',
    'redirect_redirect' => 'internal:/people',
    'status_code' => 301,
  ])->save();
  Redirect::create([
    'redirect_source' => 'people/card',
    'redirect_redirect' => 'internal:/people',
    'status_code' => 301,
  ])->save();
  MenuLinkContent::create([
    'title' => 'People',
    'link' => ['uri' => 'internal:/people'],
    'menu_name' => 'main',
    'parent' => 'menu_link_content:e5bcc37b-3e8c-4c85-a184-3715cd37c5ba',
    'weight' => -49,
  ])->save();
  MenuLinkContent::create([
    'title' => 'People',
    'link' => ['uri' => 'internal:/people'],
    'menu_name' => 'cc-main-menu',
    'parent' => 'menu_link_content:8e7960bf-7b98-45cd-945b-badcbf0b06d6',
    'weight' => -49,
  ])->save();

  $program_id = [
    'northeast-cyberteam' => 308,
    'kentucky-cyberteam' => 322,
    'careers-cyberteam' => 323,
    'campus-champions' => 572,
    'great-plains-cyberteam' => 311,
    'rmacc-cyberteam' => 314,
    'trecis-cyberteam' => 326,
    'sweeter-cyberteam' => 324,
    'mines-cyberteam' => 325,
  ];

  foreach ($program_id as $domain => $tid) {
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
    $term->set('field_region_connected_domain', $domain);
    $term->save();
  }
}

/**
 * Add default regions/domains to KB Resources.
 */
function access_misc_deploy_10001() {

  // Region taxonomy term ids.
  $new_term_ids = [
    780,
    572,
    323,
    835,
    311,
    322,
    308,
  ];

  $entity_type_manager = \Drupal::service('entity_type.manager');

  // Load all "resource" webform submissions.
  $query = $entity_type_manager->getStorage('webform_submission')->getQuery();
  $ids = $query
    ->condition('webform_id', 'resource')
    ->accessCheck(FALSE)
    ->execute();

  if (!empty($ids)) {
    $submissions = WebformSubmission::loadMultiple($ids);

    foreach ($submissions as $submission) {
      $submission->setElementData('domain', $new_term_ids);
      WebformSubmissionForm::submitWebformSubmission($submission);

      \Drupal::logger('custom_update')->info("Updated submission ID {$submission->id()} with new domain terms.");
    }
  }
  else {
    \Drupal::logger('custom_update')->warning("No resource webform submissions found.");
  }

}

/**
 * Rebuild permissions.
 */
function access_misc_deploy_10002() {
  node_access_rebuild(TRUE);
  node_access_rebuild(TRUE);
}

/**
 * Run cron.
 */
function access_misc_deploy_10003() {
  $index = Index::load('affinity_groups');
  $index->status();
  $index->reindex();

  $cron = Drupal::service('cron');
  $cron->run();
}

/**
 * Resave ag node with private set to 0.
 */
function access_misc_deploy_10004() {
  // Load node entities that are affinity_group.
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'affinity_group')
    ->accessCheck(FALSE)
    ->sort('title');
  $nids = $query->execute();

  // Foreach resave node.
  foreach ($nids as $nid) {
    $node = Node::load($nid);
    $node->set('field_ag_private', 0);
    $node->save();
  }

  // Reindex ag.
  $index = Index::load('affinity_groups');
  $index->status();
  $index->reindex();

  // $cron = Drupal::service('cron');
  // $cron->run();
}

/**
 * Where to share sql update.
 */
function access_misc_share_this($table, $bundle, $id, $revision_id, $delta, $where_to_share) {
  \Drupal::database()->insert($table)
    ->fields([
      'bundle' => $bundle,
      'deleted' => 0,
      'entity_id' => $id,
      'revision_id' => $revision_id,
      'langcode' => 'en',
      'delta' => $delta,
      'field_choose_where_to_share_this_value' => $where_to_share,
    ])
    ->execute();
}

/**
 * Update where to choose field announcements.
 */
function access_misc_deploy_10005() {
  $ann_query = \Drupal::entityQuery('node')
    ->condition('type', 'access_news')
    ->accessCheck(FALSE);
  $announcements = $ann_query->execute();

  $ann_ag_query = \Drupal::entityQuery('node')
    ->condition('type', 'access_news')
    ->condition('field_affinity_group', NULL, 'IS NOT NULL')
    ->accessCheck(FALSE);
  $announcements_ag = $ann_ag_query->execute();

  foreach ($announcements as $nid) {
    // Skip test announcements created by amp_dev module.
    $node = \Drupal\node\Entity\Node::load($nid);
    if ($node && strpos($node->getTitle(), 'Test Announcement') === 0) {
      continue;
    }

    $revision_id = \Drupal::database()->select('node', 'n')
      ->fields('n', ['vid'])
      ->condition('n.nid', $nid)
      ->orderBy('vid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    access_misc_share_this('node_revision__field_choose_where_to_share_this', 'access_news', $nid, $revision_id, 0, 'on_the_announcements_page');
    access_misc_share_this('node__field_choose_where_to_share_this', 'access_news', $nid, $revision_id, 0, 'on_the_announcements_page');

    access_misc_share_this('node_revision__field_choose_where_to_share_this', 'access_news', $nid, $revision_id, 1, 'in_the_access_support_bi_weekly_digest');
    access_misc_share_this('node__field_choose_where_to_share_this', 'access_news', $nid, $revision_id, 1, 'in_the_access_support_bi_weekly_digest');

    if (in_array($nid, $announcements_ag)) {
      access_misc_share_this('node_revision__field_choose_where_to_share_this', 'access_news', $nid, $revision_id, 2, 'on_your_affinity_group_page');
      access_misc_share_this('node__field_choose_where_to_share_this', 'access_news', $nid, $revision_id, 2, 'on_your_affinity_group_page');
    }

  }

  // Reindex the announcements search API index to pick up domain access changes
  $ann_index = Index::load('announcements');
  if ($ann_index) {
    $ann_index->reindex();

    // Process the indexing immediately
    $indexed_count = $ann_index->indexItems();

    return t('Announcements search index has been reindexed. Processed @count items to pick up new field.', [
      '@count' => $indexed_count,
    ]);
  } else {
    return t('Warning: Announcements search index not found. Manual reindexing may be required.');
  }
}

/**
 * Update where to choose field events.
 */
function access_misc_deploy_10006() {
  $share_table = \Drupal::database()->select('eventseries__field_choose_where_to_share_this', 's')
    ->fields('s', ['entity_id'])
    ->execute()
    ->fetchField();
  if ($share_table === FALSE) {
    $series_query = \Drupal::entityQuery('eventseries')
      ->accessCheck(FALSE);
    $series = $series_query->execute();

    $series_ag_query = \Drupal::entityQuery('eventseries')
      ->condition('field_affinity_group_node', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);
    $series_ag = $series_ag_query->execute();

    foreach ($series as $sid) {
      // Skip test events created by amp_dev module.
      $event_series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($sid);
      if ($event_series && strpos($event_series->getTitle(), 'Test Event') === 0) {
        continue;
      }

      $table = 'eventseries__field_choose_where_to_share_this';
      $bundle = 'default';

      $series_revision_id = \Drupal::database()->select('eventseries', 's')
        ->fields('s', ['vid'])
        ->condition('s.id', $sid)
        ->orderBy('vid', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      // Old Do not share checkbox.
      $event_no_listing = \Drupal::database()->select('eventseries__field_event_no_listing', 'nl')
        ->fields('nl', ['field_event_no_listing_value'])
        ->condition('nl.entity_id', $sid)
        ->execute()
        ->fetchField();

      // Do not share if old checkbox is checked.
      if ($event_no_listing != 1 || $event_no_listing === FALSE) {
        access_misc_share_this($table, $bundle, $sid, $series_revision_id, 0, 'on_the_announcements_page');

        access_misc_share_this($table, $bundle, $sid, $series_revision_id, 1, 'in_the_access_support_bi_weekly_digest');

        if (in_array($sid, $series_ag)) {
          access_misc_share_this($table, $bundle, $sid, $series_revision_id, 2, 'on_your_affinity_group_page');
        }

      }

    }
  }
}
