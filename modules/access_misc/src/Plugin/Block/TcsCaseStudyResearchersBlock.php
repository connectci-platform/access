<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays researchers listed on a tools_case_study node.
 *
 * @Block(
 *   id = "tcs_case_study_researchers_block",
 *   admin_label = @Translation("TCS Case Study Researchers"),
 *   category = @Translation("Custom"),
 * )
 */
class TcsCaseStudyResearchersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new TcsCaseStudyResearchersBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
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
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    if (!$node || $node->bundle() !== 'tools_case_study') {
      return [];
    }

    if ($node->get('field_tcs_researcher_name')->isEmpty()) {
      return [];
    }

    $researchers = [];
    $cache_tags = $node->getCacheTags();
    $delta = 0;
    foreach ($node->get('field_tcs_researcher_name') as $item) {
      /** @var \Drupal\user\UserInterface $user */
      $user = $item->entity;
      if (!$user) {
        $delta++;
        continue;
      }

      // Vary on the user so that hiding a profile, or changing a name or
      // picture, invalidates any page this block was rendered on.
      $cache_tags = Cache::mergeTags($cache_tags, $user->getCacheTags());

      $photo_url = NULL;
      if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
        $file = $user->get('user_picture')->entity;
        if ($file) {
          $photo_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
      }

      $role = NULL;
      if ($node->hasField('field_tcs_role') && !$node->get('field_tcs_role')->isEmpty()) {
        $role_item = $node->get('field_tcs_role')->get($delta)
          ?? $node->get('field_tcs_role')->get(0);
        if ($role_item) {
          $role = $role_item->value;
        }
      }

      $institution = NULL;
      if ($node->hasField('field_tcs_institution') && !$node->get('field_tcs_institution')->isEmpty()) {
        $institution_item = $node->get('field_tcs_institution')->get($delta)
          ?? $node->get('field_tcs_institution')->get(0);
        if ($institution_item) {
          $institution = $institution_item->value;
        }
      }

      // Only link to the community profile when the user has not hidden it
      // and has at least one program (region) assigned.
      $hidden = $user->hasField('field_hide_community_profile')
        && (bool) $user->get('field_hide_community_profile')->value === TRUE;
      $has_region = $user->hasField('field_region')
        && !$user->get('field_region')->isEmpty();

      $researchers[] = [
        'uid' => $user->id(),
        'name' => $user->getDisplayName(),
        'photo_url' => $photo_url,
        'role' => $role,
        'institution' => $institution,
        'has_profile' => !$hidden && $has_region,
      ];
      $delta++;
    }

    if (empty($researchers)) {
      return [];
    }

    return [
      '#theme' => 'tcs_case_study_researchers_block',
      '#researchers' => $researchers,
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['route'],
      ],
    ];
  }

}
