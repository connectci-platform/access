<?php

namespace Drupal\access_affinitygroup\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Provides a button to contact affinity group.
 *
 * @Block(
 *   id = "affinity_contact_group",
 *   admin_label = "Affinity Contact Group",
 * )
 */
class AffinityContactGroup extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = \Drupal::routeMatch()->getParameter('node'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $current_user = \Drupal::currentUser(); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $roles = $current_user->getRoles();
    // Adding a default for layout page.
    $nid = $node ? $node->id() : 291;
    $contact = [
      ['#markup' => ''],
    ];

    // Check if user is administrator or coordinator of this affinity group.
    $has_access = FALSE;
    if (in_array('administrator', $roles)) {
      $has_access = TRUE;
    }
    elseif ($node) {
      $coordinators = $node->get('field_coordinator')->getValue();
      foreach ($coordinators as $coordinator) {
        if ($coordinator['target_id'] == $current_user->id()) {
          $has_access = TRUE;
          break;
        }
      }
    }

    if ($has_access) {
      $contact['string'] = [
        '#type' => 'inline_template',
        '#template' => '<a class="btn btn-outline-dark cursor-default mx-0 my-2" href="/form/affinity-group-contact?nid={{ nid }}">{{ contact_text }}</a>',
        '#context' => [
          'contact_text' => $this->t('Email Affinity Group'),
          'nid' => $nid,
        ],
      ];
    }

    return $contact;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if ($node = \Drupal::routeMatch()->getParameter('node')) {// phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      return Cache::mergeTags(parent::getCacheTags(), ['node:' . $node->id()]);
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

}
