<?php

namespace Drupal\ticketing\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Block with the ticketing_block theme.
 *
 * Uses ticketing-block.html.twig which displays choices for type of ticketing,
 * and then goes to the corresponding Jira ticket page.
 * Here we collect the account name and display name of the current
 * user, which is then used by the twig template ticketing-block.html.twig.
 *
 * @Block(
 *   id = "ticketing_block",
 *   admin_label = @Translation("Ticketing choices"),
 * )
 */
class TicketingBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $uid = \Drupal::currentUser()->id();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if (!empty($account)) {
      $account_name = $account->getAccountName();
      $display_name = $account->getDisplayName();
    }
    else {
      $account_name = '';
      $display_name = '';
    }

    return [
      '#theme' => 'ticketing_block',
      '#data' => [
        'account_name' => $account_name,
        'display_name' => $display_name,
      ],
    ];

  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $user = \Drupal::currentUser();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
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
