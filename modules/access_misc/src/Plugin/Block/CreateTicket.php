<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\access_misc\Plugin\JiraLink;

/**
 * Provides a 'Create Ticket Button' Block.
 *
 * @Block(
 *   id = "create_ticket_button",
 *   admin_label = "Create Ticket button",
 * )
 */
class CreateTicket extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $link = new JiraLink('https://access-ci.atlassian.net/servicedesk/customer/portal/2/group/3/create/17', $this->t('Create Ticket'));
    return $link->getLink();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $user = \Drupal::currentUser(); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if ($user) {
      return Cache::mergeTags(parent::getCacheTags(), ['user:' . $user->id()]);
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

}
