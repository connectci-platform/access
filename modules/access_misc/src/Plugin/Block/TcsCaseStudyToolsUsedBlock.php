<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays access tools used on a tools_case_study node.
 *
 * @Block(
 *   id = "tcs_case_study_tools_used_block",
 *   admin_label = @Translation("TCS Case Study Tools Used"),
 *   category = @Translation("Custom"),
 * )
 */
class TcsCaseStudyToolsUsedBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new TcsCaseStudyToolsUsedBlock.
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

    if ($node->get('field_tcs_access_tools_used')->isEmpty()) {
      return [];
    }

    $tools = [];
    foreach ($node->get('field_tcs_access_tools_used') as $item) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = $item->entity;
      if (!$term) {
        continue;
      }

      $logo_url = NULL;
      if ($term->hasField('field_tool_logo') && !$term->get('field_tool_logo')->isEmpty()) {
        $file = $term->get('field_tool_logo')->entity;
        if ($file) {
          $logo_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
      }

      $link_url = NULL;
      if ($term->hasField('field_tsc_link_to_tool') && !$term->get('field_tsc_link_to_tool')->isEmpty()) {
        $link_url = $term->get('field_tsc_link_to_tool')->uri;
      }

      $tools[] = [
        'name' => $term->getName(),
        'logo_url' => $logo_url,
        'link_url' => $link_url,
      ];
    }

    if (empty($tools)) {
      return [];
    }

    return [
      '#theme' => 'tcs_case_study_tools_used_block',
      '#tools' => $tools,
      '#cache' => [
        'tags' => $node->getCacheTags(),
        'contexts' => ['route'],
      ],
    ];
  }

}
