<?php

namespace Drupal\cssn\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;

/**
 * Provides Join CSSN webform in block.
 *
 * @Block(
 *   id = "cssn_join_form",
 *   admin_label = "Display webform to join CSSN",
 * )
 */
class CssnJoinForm extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $join_login = '';
    $join_webform = '';
    $user = \Drupal::currentUser();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if ($user->isAnonymous()) {
      // Create drupal 9 link to /user/login?redirect=/cssn#join-cssn with text
      // Login to join CSSN, with classes 'btn' and 'btn-primary'.
      $join_login = [
        '#type' => 'link',
        '#title' => 'Login to join CSSN',
        '#url' => Url::fromRoute('misc.login', [], ['query' => ['destination' => '/cssn#join-cssn']]),
        '#attributes' => [
          'class' => ['md--mt-16', 'btn', 'btn-primary'],
        ],
      ];
    }
    else {
      $webform = \Drupal::entityTypeManager()->getStorage('webform')->load('join_the_cssn_network');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $join_webform = $webform->getSubmissionForm();
    }
    $join_img = [
      '#theme' => 'image',
      '#uri' => 'public://cssn/join-cssn.svg',
      '#alt' => 'Join the CSSN Network',
      '#attributes' => [
        'class' => ['hidden md--block'],
      ],
    ];
    $join_img_mobile = [
      '#theme' => 'image',
      '#uri' => 'public://cssn/join-cssn-mobile.svg',
      '#alt' => 'Join the CSSN Network',
      '#attributes' => [
        'class' => ['block md--hidden'],
      ],
    ];

    $block['string'] = [
      '#type' => 'inline_template',
      '#template' => '<div id="join-cssn" class="items-center bg-md-teal grid grid-cols-1 md--grid-cols-2 gap-5 p-10">
        <div>
          {{ join_img }} {{ join_img_mobile }}
        </div>
        <div class="md--px-10 [&>*]--text-white [&_.form-checkboxes]--flex-col">
          {{ join_login }} {{ join_webform }}
        </div>
      </div>',
      '#context' => [
        'join_webform' => $join_webform,
        'join_login' => $join_login,
        'join_img' => $join_img,
        'join_img_mobile' => $join_img_mobile,
      ],
    ];
    return $block;
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
