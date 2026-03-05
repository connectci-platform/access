<?php

namespace Drupal\access_match_engagement\Plugin\Block;

use Drupal\node\NodeInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Cache\Cache;

/**
 * Access Match Node Block.
 *
 * @Block(
 *   id = "match_node_block",
 *   admin_label = @Translation("Access Match Node Block")
 * )
 */
class MatchNodeBlock extends BlockBase implements
  ContainerFactoryPluginInterface {

  /**
   * Invoke renderer.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityInterface;

  /**
   * Invoke renderer.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Invoke renderer.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routMatchInterface;

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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array $configuration
   *   Configuration array.
   * @param string $plugin_id
   *   Plugin id string.
   * @param mixed $plugin_definition
   *   Plugin Definition mixed.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_interface
   *   Invokes renderer.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match_interface
   *   Route match interface.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   File url generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_interface,
    RouteMatchInterface $route_match_interface,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityInterface = $entity_interface;
    $this->routMatchInterface = $route_match_interface;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $thisNode = $this->routMatchInterface->getParameter('node');
    if ($thisNode instanceof NodeInterface) {
      $nid = $thisNode->id();
      $node = $this->entityInterface->getStorage('node')->load($nid);
      $status = $node->get('field_status')->getValue();
      if ($status) {
        $status = $status[0]['value'];
        $is_recruiting = strcasecmp($status, 'recruiting') == 0 ? TRUE : FALSE;
      }
      $type = $node->get('field_node_type')->getValue();
      $type = $type[0]['value'];
      $nid = $node->id();
      $interested_users = $node->get('field_match_interested_users')->getValue();
      // Lookup user names from uid.
      $interested_users = $this->getInterestedUsers($interested_users);
      $status = $node->get('field_status')->getValue() ?? $node->get('field_status')->getValue()[0]['value'];
      $interested_button = '';
      if ($is_recruiting) {
        $interested_list = $node->get('field_match_interested_users')->getValue();
        $user = \Drupal::currentUser()->id();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        if (array_search($user, array_column($interested_list, 'target_id')) !== FALSE) {
          $uninterested_text = $this->t("I'm no longer Interested");
          $interested_button = "<a class='btn btn-primary' href='/node/$nid/interested'>$uninterested_text</a>";
        }
        else {
          $interested_text = $this->t("I'm Interested");
          $interested_button = $is_recruiting ? "<a class='btn btn-primary' href='/node/$nid/interested'>$interested_text</a>" : '';
        }
      }
      $match_node_block['string'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="">
          <div class="mb-5">
           {{ interested_button | raw }}
          </div>
          {% if interested_users %}
            <div>
              <h3>Interested People</h3>
                <ul>
                {% for interested_user in interested_users %}
                  <li>{{ interested_user }}</li>
                {% endfor %}
                </ul>
            </div>
        {% endif %}',
        '#context' => [
          'interested_button' => $interested_button,
          'interested_users' => $interested_users,
        ],
      ];
      return $match_node_block;
    }
    else {
      return [
        '#markup' => $this->t('Match Node Block - not a match node'),
      ];

    }
  }

  /**
   * Get interested users.
   */
  public function getInterestedUsers($interested_users) {
    // Only show interested users to match_sc, match_pm, and admin.
    $accepted_roles = ['administrator', 'match_sc', 'match_pm'];
    $current_user = \Drupal::currentUser();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $roles = $current_user->getRoles();
    if (empty(in_array($accepted_roles, $roles))) {
      return [];
    }

    $interested_users = array_column($interested_users, 'target_id');
    $users = $this->entityInterface->getStorage('user')->loadMultiple($interested_users);
    $user_names = [];
    foreach ($users as $user) {
      $user_names[] = $user->get('field_user_first_name')->value . ' ' . $user->get('field_user_last_name')->value;
    }
    return $user_names;
  }

  /**
   * Set cache tag by node id.
   */
  public function getCacheTags() {
    // With this when your node change your block will rebuild.
    if ($node = $this->routMatchInterface->getParameter('node')) {
      // If there is node add its cachetag.
      return Cache::mergeTags(parent::getCacheTags(), ['node:' . $node->id()]);
    }
    else {
      // Return default tags instead.
      return parent::getCacheTags();
    }
  }

  /**
   * Return cache contexts.
   */
  public function getCacheContexts() {
    // If you depends on \Drupal::routeMatch()
    // you must set context of this block with 'route' context tag.
    // Every new route this block will rebuild.
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
