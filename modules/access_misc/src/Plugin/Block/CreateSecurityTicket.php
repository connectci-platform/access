<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\access_misc\Plugin\JiraLink;

/**
 * Provides a 'Create Ticket Button' Block.
 *
 * @Block(
 *   id = "create_security_ticket_button",
 *   admin_label = "Create Security Ticket button",
 * )
 */
class CreateSecurityTicket extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $link = new JiraLink('https://access-ci.atlassian.net/servicedesk/customer/portal/3/create/26', $this->t('Create a Security Ticket'));
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
