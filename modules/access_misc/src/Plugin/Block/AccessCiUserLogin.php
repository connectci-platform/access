<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\access_misc\Plugin\JiraLink;

/**
 * Access CI login button on user login page.
 *
 * @Block(
 *   id = "access_ci_user_login",
 *   admin_label = "Access CI User Login",
 * )
 */
class AccessCiUserLogin extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $current = \Drupal::request()->query->get('current');
    $redirect = \Drupal::request()->query->get('redirect');

    $redirect = $redirect ?: $current;

    return [
      '#type' => 'inline_template',
      '#template' => '<div class="col-lg-6 col-md-8 mx-auto text-center card my-4">
        <div class="card-body">
          <h2>ACCESS ID</h2>

          <a href="/login?redirect={{ current }}" class="button btn btn-primary">Login with ACCESS CI</a>

          <div id="cilogon-auth-login-group">

            <div id="cilogon-auth-login-suffix">
              <a href="https://identity.access-ci.org/">Having trouble logging in?</a>
              | <a href="https://identity.access-ci.org/new-user">Create an account</a>
            </div>

          </div>

        </div>
      </div>',
      '#context' => [
        'current' => $redirect,
      ],
    ];

  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
