<?php

namespace Drupal\ccmnet\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides a 'MentorshipPerson' Block.
 *
 * @Block(
 *   id = "mentorship_person_block",
 *   admin_label = @Translation("Mentorship Person")
 * )
 *
 * @phpstan-consistent-constructor
 */
class MentorshipPersonBlock extends BlockBase implements
  ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
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
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Container pulled in.
   * @param array<string, mixed> $configuration
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
   * @param array<string, mixed> $configuration
   *   Configuration array.
   * @param string $plugin_id
   *   Plugin id string.
   * @param mixed $plugin_definition
   *   Plugin Definition mixed.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
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
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function build() {

    // Note: title from layout builder block placement used here.
    $isMentor = $this->configuration['label'] == 'Mentor' ? TRUE : FALSE;
    $personFieldName = $isMentor ? 'field_mentor' : 'field_mentee';

    $node_param = $this->routeMatch->getParameter('node');
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Need this for using layout builder.
    if (empty($node_param) || empty($node_param->id())) {
      return [
        '#markup' => $this->t('No node.'),
      ];
    }
    $node = $node_storage->load($node_param->id());

    $userName = '';
    $userImage = '';
    $institution = '';
    $personA = $node->get($personFieldName)->getValue();

    if (empty($personA) || empty($personA[0])) {
      return [];
    }
    else {
      $title = $isMentor ? 'Mentor' : 'Mentee';
      $title .= isset($personA[1]) ? 's' : '';
      $display = "<h2>$title</h2>";
      foreach ($personA as $person) {
        $personId = $person['target_id'];
        // Load user from user id mentee.
        $user = $this->entityTypeManager->getStorage('user')->load($personId);
        if (!$user) {
          continue;
        }

        // Get user profile picure image.
        $userImage = $user->get('user_picture');
        $file = $userImage->entity;

        if ($file instanceof FileInterface) {
          $userImage = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
          $alt = $user->getDisplayName() . ' profile picture';
        }
        else {
          $userImage = '/themes/nect-theme/img/user-picture.svg';
          $alt = 'Default profile picture';
        }
        $userImage = '<img alt="' . $alt . '" src="' . $userImage . '" />';

        // Show access organization if set and not "Other"; otherwise,
        // use institution field.
        $orgArray = $user->get('field_access_organization')->getValue();
        $institution = '';

        if (!empty($orgArray) && !empty($orgArray[0])) {
          $nodeId = $orgArray[0]['target_id'];
          if (!empty($nodeId)) {
            $orgNode = $this->entityTypeManager->getStorage('node')->load($nodeId);
            if ($orgNode) {
              $orgTitle = $orgNode->getTitle();
              // If organization is "Other", use the institution
              // field instead.
              if ($orgTitle === 'Other') {
                $institution = $user->get('field_institution')->value;
              }
              else {
                $institution = $orgTitle;
              }
            }
          }
        }

        // Fallback to institution field if no organization or if
        // organization loading failed.
        if (empty($institution)) {
          $institution = $user->get('field_institution')->value;
        }
        $userName = $user->getDisplayName();
        $userUrl = "/community-persona/$personId";

        $display .= '<div class="d-flex justify-content-start mentorship-person mb-3">' .
          '<div class="mentorship-person-picture p-0">' . $userImage . '</div>' .
          '<div class="col d-flex  flex-column justify-content-start">' .
          '<div><strong><a href="' . $userUrl . '">' . $userName . '</a></strong></div><div>' . $institution . '</div></div></div>';
      }

    }

    return ['#markup' => Markup::create($display)];
  }

}
