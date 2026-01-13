<?php

/**
 * @file
 */

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
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

  // Reindex the announcements search API index to pick up the new field.
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
      if ($event_series && strpos($event_series->label(), 'Test Event') === 0) {
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

/**
 * Create new webinars page with custom layout.
 */
function access_misc_deploy_10007() {
  // Create new page node with body content.
  $node = Node::create([
    'type' => 'page',
    'title' => 'Recorded Events and Trainings',
    'body' => [
      'value' => '<p class="prose text-dark-teal text-2xl leading-9 mb-10">
    Watch videos from past events.
</p>',
      'format' => 'full_html',
    ],
    'status' => 1,
    'uid' => 1985,
    'path' => [
      'alias' => '/knowledge-base/recorded-events-and-trainings',
      'pathauto' => 0,
    ],
  ]);
  $node->save();

  // Clear existing layout.
  $node->set('layout_builder__layout', []);

  // Section 1: Single column layout with basic page fields.
  $section1 = new Section('layout_onecol', ['label' => '']);

  $component1_1 = new SectionComponent('80aa3e26-4b2d-448c-ad7f-fe9bc10276ff', 'content', [
    'id' => 'field_block:node:page:field_image',
    'label_display' => '0',
    'context_mapping' => ['entity' => 'layout_builder.entity'],
    'formatter' => [
      'type' => 'image',
      'label' => 'hidden',
      'settings' => [
        'image_link' => '',
        'image_style' => '',
        'image_loading' => ['attribute' => 'lazy'],
      ],
      'third_party_settings' => [],
    ],
  ]);
  $component1_1->setWeight(0);
  $section1->appendComponent($component1_1);

  $component1_2 = new SectionComponent('c0ffbca3-1681-4cad-9785-aeddb3b5c326', 'content', [
    'id' => 'field_block:node:page:body',
    'label_display' => '0',
    'context_mapping' => ['entity' => 'layout_builder.entity'],
    'formatter' => [
      'type' => 'text_default',
      'label' => 'hidden',
      'settings' => [],
      'third_party_settings' => [],
    ],
  ]);
  $component1_2->setWeight(1);
  $section1->appendComponent($component1_2);

  $component1_3 = new SectionComponent('c0324193-630b-4761-b004-dc050343ef9f', 'content', [
    'id' => 'field_block:node:page:comment_node_page',
    'label_display' => '0',
    'context_mapping' => ['entity' => 'layout_builder.entity'],
    'formatter' => [
      'type' => 'comment_default',
      'label' => 'hidden',
      'settings' => [
        'view_mode' => 'default',
        'pager_id' => 0,
      ],
      'third_party_settings' => [],
    ],
  ]);
  $component1_3->setWeight(2);
  $section1->appendComponent($component1_3);

  $component1_4 = new SectionComponent('b739b3bd-c34c-4648-ac1c-3629a02b3a3e', 'content', [
    'id' => 'extra_field_block:node:page:links',
    'label_display' => '0',
    'context_mapping' => ['entity' => 'layout_builder.entity'],
  ]);
  $component1_4->setWeight(3);
  $section1->appendComponent($component1_4);

  $node->get('layout_builder__layout')->appendSection($section1);

  // Section 2: Two column layout with webinar filters and results.
  $section2 = new Section('layout_twocol_section', [
    'label' => 'Webinar section',
    'column_widths' => '25-75',
    'layout_builder_styles_style' => [
      'bg_light_teal_overflow_section' => 0,
      'border_b_2' => 0,
      'border_gray' => 0,
      'mb_10_section' => 0,
      'md_order_1' => 0,
      'md_order_2' => 0,
      'mobile_row_reverse' => 0,
      'mt_4_section' => 0,
      'order_1' => 0,
      'order_2' => 0,
      'pt_20' => 0,
      '_layout__region_second_order_1' => 0,
    ],
    'context_mapping' => [],
  ]);

  $component2_1 = new SectionComponent('683af77f-9202-4529-a31c-7e01241918be', 'second', [
    'id' => 'views_block:events_facet-recorded_webinars',
    'label' => '',
    'label_display' => 0,
    'provider' => 'views',
    'views_label' => '',
    'items_per_page' => 'none',
    'exposed' => [],
    'context_mapping' => [],
  ]);
  $component2_1->setWeight(0);
  $component2_1->set('additional', [
    'layout_builder_styles_style' => [
      'accordion_wrapper' => 0,
      'bg_light_teal' => 0,
      'mb_10' => 0,
      'mb_3' => 0,
      'mb_5' => 0,
      'md_teal_box' => 0,
      'page_title' => 0,
      'pb_4' => 0,
      'pe_3' => 0,
      'pt_4' => 0,
      'tags' => 0,
      '__div_h_full' => 0,
    ],
  ]);
  $section2->appendComponent($component2_1);

  $component2_2 = new SectionComponent('1651dd17-ad1e-4942-8a82-8ccaf4994e20', 'first', [
    'id' => 'views_exposed_filter_block:events_facet-recorded_webinars_block',
    'label' => '',
    'label_display' => 'visible',
    'provider' => 'views',
    'views_label' => '',
    'context_mapping' => [],
  ]);
  $component2_2->setWeight(0);
  $component2_2->set('additional', [
    'layout_builder_styles_style' => [
      'accordion_wrapper' => 0,
      'bg_light_teal' => 0,
      'mb_10' => 0,
      'mb_3' => 0,
      'mb_5' => 0,
      'md_teal_box' => 0,
      'page_title' => 0,
      'pb_4' => 0,
      'pe_3' => 0,
      'pt_4' => 0,
      'tags' => 0,
      '__div_h_full' => 0,
    ],
  ]);
  $section2->appendComponent($component2_2);

  $component2_3 = new SectionComponent('e6c10e81-367a-4945-80ec-67d1c0361023', 'first', [
    'id' => 'facet_block:topic_recorded_webinars_full_block',
    'label' => 'Topic',
    'label_display' => 'visible',
    'provider' => 'facets',
    'context_mapping' => [],
  ]);
  $component2_3->setWeight(2);
  $component2_3->set('additional', [
    'layout_builder_styles_style' => [
      'accordion_wrapper' => 0,
      'bg_light_teal' => 0,
      'mb_10' => 0,
      'mb_3' => 0,
      'mb_5' => 0,
      'md_teal_box' => 0,
      'page_title' => 0,
      'pb_4' => 0,
      'pe_3' => 0,
      'pt_4' => 0,
      'tags' => 0,
      '__div_h_full' => 0,
    ],
  ]);
  $section2->appendComponent($component2_3);

  $component2_5 = new SectionComponent('23c4b052-f4f0-4c5c-9067-8e917ecb61e1', 'first', [
    'id' => 'facet_block:webinars_full_skill_level',
    'label' => 'Skill Level',
    'label_display' => 'visible',
    'provider' => 'facets',
    'context_mapping' => [],
  ]);
  $component2_5->setWeight(4);
  $component2_5->set('additional', [
    'layout_builder_styles_style' => [
      '__div_h_full' => 0,
      'accordion_wrapper' => 0,
      'bg_light_teal' => 0,
      'mb_10' => 0,
      'mb_3' => 0,
      'mb_5' => 0,
      'md_teal_box' => 0,
      'page_title' => 0,
      'pb_4' => 0,
      'pe_3' => 0,
      'pt_4' => 0,
      'tags' => 0,
    ],
  ]);
  $section2->appendComponent($component2_5);

  $component2_4 = new SectionComponent('1f3eeff5-1ceb-4747-be35-1f8a4b1f90ec', 'first', [
    'id' => 'facet_block:webinars_full_affinity_group',
    'label' => 'Affinity Group',
    'label_display' => 'visible',
    'provider' => 'facets',
    'context_mapping' => [],
  ]);
  $component2_4->setWeight(3);
  $component2_4->set('additional', [
    'layout_builder_styles_style' => [
      'accordion_wrapper' => 0,
      'bg_light_teal' => 0,
      'mb_10' => 0,
      'mb_3' => 0,
      'mb_5' => 0,
      'md_teal_box' => 0,
      'page_title' => 0,
      'pb_4' => 0,
      'pe_3' => 0,
      'pt_4' => 0,
      'tags' => 0,
      '__div_h_full' => 0,
    ],
  ]);
  $section2->appendComponent($component2_4);

  $node->get('layout_builder__layout')->appendSection($section2);

  $node->save();
}
