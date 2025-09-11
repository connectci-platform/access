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
 * Update Resource webform submissions.
 */
function access_misc_deploy_10005() {
  $connection = \Drupal::database();

  $ann_query = \Drupal::entityQuery('node')
    ->condition('type', 'access_news')
    ->accessCheck(TRUE);
  $announcements = $ann_query->execute();

  $ann_ag_query = \Drupal::entityQuery('node')
    ->condition('type', 'access_news')
    ->condition('field_affinity_group', NULL, 'IS NOT NULL')
    ->accessCheck(TRUE);
  $announcements_ag = $ann_ag_query->execute();

  foreach ($announcements as $nid) {
    $revision_id = $connection->select('node', 'n')
      ->fields('n', ['vid'])
      ->condition('n.nid', $nid)
      ->orderBy('vid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $connection->insert('node_revision__field_choose_where_to_share_this')
      ->fields([
        'bundle' => 'access_news',
        'deleted' => 0,
        'entity_id' => $nid,
        'revision_id' => $revision_id,
        'langcode' => 'en',
        'delta' => 0,
        'field_choose_where_to_share_this_value' => 'on_the_announcements_page',
      ])
      ->execute();

    $connection->insert('node__field_choose_where_to_share_this')
      ->fields([
        'bundle' => 'access_news',
        'deleted' => 0,
        'entity_id' => $nid,
        'revision_id' => $revision_id,
        'langcode' => 'en',
        'delta' => 0,
        'field_choose_where_to_share_this_value' => 'on_the_announcements_page',
      ])
      ->execute();

    $connection->insert('node_revision__field_choose_where_to_share_this')
      ->fields([
        'bundle' => 'access_news',
        'deleted' => 0,
        'entity_id' => $nid,
        'revision_id' => $revision_id,
        'langcode' => 'en',
        'delta' => 1,
        'field_choose_where_to_share_this_value' => 'in_the_access_support_bi_weekly_digest',
      ])
      ->execute();

    $connection->insert('node__field_choose_where_to_share_this')
      ->fields([
        'bundle' => 'access_news',
        'deleted' => 0,
        'entity_id' => $nid,
        'revision_id' => $revision_id,
        'langcode' => 'en',
        'delta' => 1,
        'field_choose_where_to_share_this_value' => 'in_the_access_support_bi_weekly_digest',
      ])
      ->execute();


    if (in_array($nid, $announcements_ag)) {
      $connection->insert('node_revision__field_choose_where_to_share_this')
        ->fields([
          'bundle' => 'access_news',
          'deleted' => 0,
          'entity_id' => $nid,
          'revision_id' => $revision_id,
          'langcode' => 'en',
          'delta' => 2,
          'field_choose_where_to_share_this_value' => 'on_your_affinity_group_page',
        ])
      ->execute();

      $connection->insert('node__field_choose_where_to_share_this')
        ->fields([
          'bundle' => 'access_news',
          'deleted' => 0,
          'entity_id' => $nid,
          'revision_id' => $revision_id,
          'langcode' => 'en',
          'delta' => 2,
          'field_choose_where_to_share_this_value' => 'on_your_affinity_group_page',
        ])
      ->execute();
    }

  }

  drupal_flush_all_caches();

  // Reindex the announcements search API index to pick up domain access changes
  $events_index = Index::load('announcements');
  if ($events_index) {
    $events_index->reindex();

    // Process the indexing immediately
    $indexed_count = $events_index->indexItems();

    return t('Announcments search index has been reindexed. Processed @count items to pick up new field.', [
      '@count' => $indexed_count,
    ]);
  } else {
    return t('Warning: Announcments search index not found. Manual reindexing may be required.');
  }
}
