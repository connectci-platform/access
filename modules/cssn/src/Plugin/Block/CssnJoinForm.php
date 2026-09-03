<?php

namespace Drupal\cssn\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Join CSSN webform in block.
 *
 * @Block(
 *   id = "cssn_join_form",
 *   admin_label = "Display webform to join CSSN",
 * )
 *
 * @phpstan-consistent-constructor
 */
class CssnJoinForm extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a CssnJoinForm block.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   The instantiated block plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The block render array.
   */
  public function build() {
    $join_login = '';
    $join_webform = '';
    if ($this->currentUser->isAnonymous()) {
      // Link to the login page, returning to the join form afterwards.
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
      /** @var \Drupal\webform\WebformInterface $webform */
      $webform = $this->entityTypeManager->getStorage('webform')->load('join_the_cssn_network');
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
    return Cache::mergeTags(parent::getCacheTags(), ['user:' . $this->currentUser->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

}
