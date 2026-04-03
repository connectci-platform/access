<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Institutional login button on user login page.
 *
 * @Block(
 *   id = "institutional_user_login",
 *   admin_label = "Institutional User Login",
 * )
 */
class InstitutionalUserLogin extends BlockBase {

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
          <h2>Institutional Login</h2>
          <p>Use your university or organization credentials.</p>

          <a href="/login?redirect={{ current }}" class="button btn btn-outline-primary">Login with CILogon</a>

          <div class="mt-2">
            <small><a href="https://www.cilogon.org/faq">What is CILogon?</a></small>
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
