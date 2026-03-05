<?php

namespace Drupal\ccmnet\Plugin\Block;

use Drupal\node\NodeInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Cache\Cache;

/**
 * Access Mentorship Node Block.
 *
 * @Block(
 *   id = "mentorship_node_block",
 *   admin_label = @Translation("Access Mentorship Node Block")
 * )
 */
class MentorshipNodeBlock extends BlockBase implements
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
      $state = $node->get('field_me_state')->getValue();
      $is_recruiting = FALSE;
      if ($state) {
        $ttm = \Drupal::entityTypeManager()->getStorage('taxonomy_term');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        $lookup = $ttm->loadByProperties([
          'name' => 'Recruiting',
          'vid' => 'state',
        ]);
        $state_tid = array_keys($lookup)[0];
        $state = $state[0]['target_id'];
        $is_recruiting = strcasecmp($state, $state_tid) == 0 ? TRUE : FALSE;
      }
      if (!$is_recruiting) {
        $lookup = $ttm->loadByProperties([
          'name' => 'In Progress and Recruiting',
          'vid' => 'state',
        ]);
        $state_tid = array_keys($lookup)[0];
        $is_recruiting = strcasecmp($state, $state_tid) == 0 ? TRUE : FALSE;
      }
      if (!$is_recruiting) {
        return [];
      }

      $nid = $node->id();
      $looking_for = $node->get('field_me_looking_for')->getValue();

      // Button to contact the originating mentor/mentee.
      if ($looking_for[0]['value'] == 'mentor') {
        $seeker = $node->get('field_mentee')->getValue();
      }
      else {
        $seeker = $node->get('field_mentor')->getValue();
      }
      if (!empty($seeker)) {
        $seeker = $seeker[0]['target_id'];
        $current_path = \Drupal::service('path.current')->getPath();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        $path_alias = \Drupal::service('path_alias.manager')->getAliasByPath($current_path);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        $question_button = "<a class='btn btn-rounded  bg-ccmnet-lightblue text-white' href='/user/$seeker/contact?destination=$path_alias'>I have a question</a>";
      }
      else {
        $question_button = '';
      }

      $is_owner = $node->getOwnerId() == \Drupal::currentUser()->id();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $interested_users = $node->get('field_match_interested_users')->getValue();
      // Lookup user names from uid.
      $interested_users = $this->getInterestedUsers($interested_users, $is_owner);
      $interested_button = '';

      $interested_list = $node->get('field_match_interested_users')->getValue();
      $user = \Drupal::currentUser()->id();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      if (array_search($user, array_column($interested_list, 'target_id')) !== FALSE) {
        $uninterested_text = $this->t("I'm no longer Interested");
        $interested_button = "<a class='btn btn-rounded bg-red text-white' href='/node/$nid/interested'>$uninterested_text</a>";
      }
      else {
        $interested_text = $this->t("I'm Interested");
        $interested_button = "<a class='btn btn-rounded bg-red text-white' href='/node/$nid/interested'>$interested_text</a>";
      }

      $recruitee_attrib = $node->get('field_me_preferred_attributes')->getValue();
      $recruitee_attrib = isset($recruitee_attrib[0]) ? $recruitee_attrib[0]['value'] : '';

      $section_header = "";

      if (!empty($recruitee_attrib)) {
        $section_header = '';
        $looking_span = '<span class="pt-1 pl-2">' . $looking_for[0]['value'] . ' preferred attributes: </span>';
        $img = '<img src="/modules/custom/access/modules/ccmnet/images/asterisk.png" alt="asterisk" />';
        $section_header = '<div class="d-flex align-items-center text-uppercase">' . $img . $looking_span . '</div>';
      }

      $match_node_block['string'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="mentorship_attrib_section">
            {{ section_header | raw }}
            <div>
              {{recruitee_attributes | raw}}
            </div>
          <div>
            {{ interested_button | raw }}
            {{ question_button | raw }}
          </div>
          {% if interested_users %}
            <div>
              <h3>Interested People</h3>
                <ul>
                {% for interested_user in interested_users %}
                  <li><a href="/community-persona/{{interested_user.target_id}}" >{{interested_user.name}}</a></li>
                {% endfor %}
                </ul>
            </div>
          {% endif %}
          </div>',
        '#context' => [
          'section_header' => $section_header,
          'recruitee_attributes' => $recruitee_attrib,
          'interested_button' => $interested_button,
          'interested_users' => $interested_users,
          'question_button' => $question_button,
        ],
      ];
      return $match_node_block;
    }
    else {
      return [
        '#markup' => $this->t('Mentorship Node Block - not a mentorship node'),
      ];
    }
  }

  /**
   * Get interested users.
   */
  public function getInterestedUsers($interested_users, $is_owner) {
    if (!$is_owner) {
      // Only show interested users to ccmnet_pm, and admin roles.
      $accepted_roles = ['administrator', 'ccmnet_pm'];
      $current_user = \Drupal::currentUser();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $roles = $current_user->getRoles();
      $hide = TRUE;
      foreach ($accepted_roles as $role) {
        if (in_array($role, $roles)) {
          $hide = FALSE;
          break;
        }
      }
      if ($hide) {
        return [];
      }
    }

    $interested_users = array_column($interested_users, 'target_id');
    $users = $this->entityInterface->getStorage('user')->loadMultiple($interested_users);
    $user_names = [];
    foreach ($users as $user) {
      $one_user = [
        'target_id' => $user->id(),
        'name' => $user->get('field_user_first_name')->value . ' ' . $user->get('field_user_last_name')->value,
      ];
      $user_names[] = $one_user;
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
