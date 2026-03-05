<?php

namespace Drupal\access_misc\Plugin\views\access;

use Drupal\views\Plugin\views\access\AccessPluginBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Class ViewsCustomAccess.
 *
 * @ingroup views_access_plugins
 *
 * @ViewsAccess(
 *     id = "views_user_registration_custom_access",
 *     title = @Translation("Custom /user Registration Access"),
 *     help = @Translation("Custom Registration Access for registration on the /user page."),
 * )
 */
class ViewsUserRegistrationCustomAccess extends AccessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Custom User Registration Access');
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    $access = FALSE;

    // Get current uri.
    $current_uri = \Drupal::service('path.current')->getPath();
    $url_bits = explode('/', $current_uri);

    $profile_uid = (isset($url_bits[2]) && is_numeric($url_bits[2])) ? $url_bits[2] : 0;

    $current_user_id = $account->id();

    if ($profile_uid == $current_user_id) {
      $access = TRUE;
    }

    if ($account->hasRole('administrator')) {
      $access = TRUE;
    }

    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    $route->setRequirement('_access', 'TRUE');
  }

}
