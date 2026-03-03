<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Displays users who flagged "interested" on a project.
 *
 * @Block(
 *   id = "interested_users_block",
 *   admin_label = "Interested Users",
 * )
 */
class InterestedUsersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new InterestedUsersBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\flag\FlagServiceInterface $flag_service
   *   The flag service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    FlagServiceInterface $flag_service,
    AccountProxyInterface $current_user,
    RequestStack $request_stack,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->flagService = $flag_service;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
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
      $container->get('flag'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Extract submission ID from /project/{sid}.
    $request = $this->requestStack->getCurrentRequest();
    $path = $request->getPathInfo();
    if (!preg_match('#^/project/(\d+)$#', $path, $matches)) {
      return [];
    }
    $sid = $matches[1];

    // Load and validate the webform submission.
    $submission = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->load($sid);
    if (!$submission || $submission->getWebform()->id() !== 'project') {
      return [];
    }

    // Only show during recruiting phase.
    $status = $submission->getData()['status'] ?? '';
    if ($status !== 'Recruiting') {
      return [];
    }

    // Access check: owner or pascience_manager.
    $uid = $this->currentUser->id();
    $is_owner = ((int) $uid === (int) $submission->getOwnerId());
    $is_manager = $this->currentUser->hasRole('pascience_manager');
    $is_admin = $this->currentUser->hasRole('administrator');
    if (!$is_owner && !$is_manager && !$is_admin) {
      return [];
    }

    // Get interested users via Flag module.
    $flag = $this->flagService->getFlagById('interested_in_project');
    if (!$flag) {
      return [];
    }
    $users = $this->flagService->getFlaggingUsers($submission, $flag);
    if (empty($users)) {
      return [];
    }

    // Build list of user links.
    $items = [];
    foreach ($users as $user) {
      $first = $user->get('field_user_first_name')->value;
      $last = $user->get('field_user_last_name')->value;
      $name = trim($first . ' ' . $last);
      if (empty($name)) {
        $name = $user->getDisplayName();
      }
      $item = '<a href="/community-persona/' . $user->id() . '">' . htmlspecialchars($name) . '</a>';

      // Add CV/Resume link if the user has one uploaded.
      if (!$user->get('field_cv_resume')->isEmpty()) {
        $file = $user->get('field_cv_resume')->entity;
        if ($file) {
          $cv_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
          $item .= ' <a href="' . htmlspecialchars($cv_url) . '" target="_blank" class="badge bg-secondary ms-1">CV</a>';
        }
      }

      $items[] = $item;
    }

    $list = '<ul class="list-unstyled mb-0">';
    foreach ($items as $item) {
      $list .= '<li class="my-1">' . $item . '</li>';
    }
    $list .= '</ul>';

    return [
      '#type' => 'inline_template',
      '#template' => '<div class="card my-3"><div class="card-body"><h3>Interested People</h3>' . $list . '</div></div>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
