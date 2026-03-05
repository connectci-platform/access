<?php

namespace Drupal\ccmnet\Plugin\Block;

use Drupal\Core\Url;
use Drupal\Core\Block\BlockBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a 'UserRegisterRedirect' Block.
 *
 * @Block(
 *   id = "user_register_redirect_block",
 *   admin_label = @Translation("User Register Redirect Block"),
 * )
 */
class UserRegisterRedirectBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $current_user = \Drupal::currentUser();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if ($current_user->isAuthenticated()) {
      $url = Url::fromRoute('<front>');
      $response = new RedirectResponse($url->toString());
      $response->send();
    }

    return [];
  }

  /**
   * No caching.
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
