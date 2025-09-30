<?php

namespace Drupal\access_affinitygroup\Plugin\Block;

use Drupal\access_affinitygroup\Plugin\SimpleListsApi;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block for SimpleList user status.
 *
 * @Block(
 *   id = "simple_list_member",
 *   admin_label = "Simple List Member block",
 * )
 */
class SimpleListMember extends BlockBase implements
  ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Container pulled in.
   * @param array $configuration
   *   Configuration added.
   * @param string $plugin_id
   *   Plugin_id added.
   * @param mixed $plugin_definition
   *   Plugin_definition added.
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {

    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Construct object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $simpleListsApi = new SimpleListsApi();
    $msg = "";
    // Load current node.
    $node = \Drupal::routeMatch()->getParameter('node');

    $simple_list_enabled = FALSE;
    if ($node && !$node->get('field_use_ext_email_list')->isEmpty()) {
      $simple_list_enabled = $node->get('field_use_ext_email_list')->value;
    }

    if (!$simple_list_enabled) {
      return [];
    }

    $simple_list_email = $node->get('field_ext_email_list')->value;
    $group_slug = $node->get('field_group_slug')->value;
    // Get current user email.
    $current_user = \Drupal::currentUser();
    $current_user_email = $current_user->getEmail();
    $user_list = $simpleListsApi->getUserListStatus($group_slug, $current_user_email, $msg);
    $user_list = $user_list ? $user_list : 'none';
    $sl_options = [
      'full' => [
        'title' => 'Receive all emails',
        'url' => '/simplelist/full',
      ],
      'daily' => [
        'title' => 'Daily Digest',
        'url' => '/simplelist/daily',
      ],
      'none' => [
        'title' => 'No emails',
        'url' => '/simplelist/none',
      ],
    ];
    $list_default = $sl_options[$user_list];
    $options = '';
    $path = \Drupal::service('path.current')->getPath() ? Xss::filter(\Drupal::service('path.current')->getPath()) : '';
    foreach ($sl_options as $key => $value) {
      if ($key != $user_list) {
        $options .= '<li><a href="' . $value['url'] . '?current=' . $user_list . '&redirect=' . $path . '&slug=' . $group_slug . '">' . $value['title'] . '</a></li>';
      }
    }
    $simple['string'] = [
      '#type' => 'inline_template',
      'lib' => [
        '#attached' => [
          'library' => [
            'access_misc/copyclip',
          ],
        ],
      ],
      '#template' => '<div class="bg-light-teal px-4 pt-4 mb-10 block block-layout-builder block-inline-blockbasic">
        <div class="clearfix text-formatted field field--name-body field--type-text-with-summary field--label-hidden field__item">
          <h3 class="border-bottom pb-2 me-3 mr-3 mt-0">
            {{ block_title }}
          </h3>
          <div class="d-flex flex items-center">
            <input type="text" class="simplelists-email text-sm" value="{{ email }}" readonly />
            <button class="copyclip simplelists-copyclip text-sm ms-4 z-10" onclick="copyclip(\'{{ email }}\', event)">
              <span class="default-message block leading-5 text-dark-teal">
                <i class="bi-link"></i><br>
                {{ copy }}
              </span>
              <span class="copied-message text-dark-teal hidden d-none">
                <i class="bi-check"></i><br>
                Copied!
              </span>
            </button>
          </div>
          <details class="bg-white relative position-relative">
            <summary class="bg-yellow font-bold">
              {{ list_default.title }}
            </summary>
            <div>
              <ul class="list-none">
                {{ options | raw }}
              </ul>
            </div>
          </details>
        </div>
      </div>',
      '#context' => [
        'block_title' => t('Member Email List'),
        'email' => $simple_list_email,
        'copy' => t('Copy'),
        'list_default' => $list_default,
        'options' => $options,
      ],
    ];

    return $simple;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      // Get current user id.
      $current_user = \Drupal::currentUser();
      return Cache::mergeTags(parent::getCacheTags(), ['node:' . $node->id() . ':user:' . $current_user->id()]);
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
