<?php

namespace Drupal\cssn\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\access_misc\Plugin\Util\RolesLabelLookup;
use Drupal\cssn\Plugin\Util\EndUrl;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Community Persona' Block.
 *
 * @Block(
 *   id = "cssn_block",
 *   admin_label = "Community persona block",
 * )
 *
 * @phpstan-consistent-constructor
 */
class PersonaBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Constructs a PersonaBlock.
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
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
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
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The block render array.
   */
  public function build() {
    // Get last item in url.
    $end_url = new EndUrl();
    $url_end = $end_url->getUrlEnd();
    $public = TRUE;
    $should_user_load = FALSE;
    $user = NULL;
    $user_storage = $this->entityTypeManager->getStorage('user');
    if ($url_end == 'community-persona') {
      $public = FALSE;
      $should_user_load = TRUE;
    }
    if (is_numeric($url_end)) {
      $user = $user_storage->load($url_end);
      if ($user !== NULL && count($user->get('field_region')->getValue()) > 0) {
        if ($user->hasField('field_hide_community_profile') &&
            (bool) $user->get('field_hide_community_profile')->value === TRUE) {
          $should_user_load = FALSE;
        }
        else {
          $should_user_load = TRUE;
        }
      }
    }
    if ($should_user_load) {
      $user = $public ? $user : $this->currentUser;
      /** @var \Drupal\user\UserInterface $user_entity */
      $user_entity = $user_storage->load($user->id());
      $user_image = $user_entity->get('user_picture');
      $first_name = $user_entity->get('field_user_first_name')->value;
      $last_name = $user_entity->get('field_user_last_name')->value;
      $pronouns = $user_entity->get('field_user_preferred_pronouns')->value;

      if ($user_image->entity !== NULL) {
        /** @var \Drupal\file\FileInterface $user_image_file */
        $user_image_file = $user_image->entity;
        $user_image = $this->fileUrlGenerator->generateAbsoluteString($user_image_file->getFileUri());
        $user_image = '<img src="' . $user_image . '" alt="" class="img-fluid mb-3 border border-black" />';
      }
      else {
        $user_image = '<svg version="1.1" class="mb-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
           viewBox="0 0 448 448" style="enable-background:new 0 0 448 448;" xml:space="preserve">
            <style type="text/css">
              .st0{fill:#ECF9F8;}
              .st1{fill:#B7CDD1;}
            </style>
            <rect class="st0" width="448" height="448"/>
            <path class="st1" d="M291,158v14.5c0,40-32.4,72.5-72.5,72.5s-72.5-32.4-72.5-72.5V158c0-5,0.5-9.8,1.4-14.5h27.5
              c27,0,50.6-14.8,63-36.7c10.6,13.5,27.1,22.2,45.6,22.2h1.2C288.8,137.9,291,147.7,291,158z M102.6,158v14.5
              c0,64,51.9,115.9,115.9,115.9s115.9-51.9,115.9-115.9V158c0-64-51.9-115.9-115.9-115.9S102.6,94,102.6,158z"/>
            <path class="st1" d="M151.2,306.3c-71.9,7.8-128.2,68.1-130,141.7h405.6c-1.8-73.6-58.1-133.8-130.1-141.6
              c-4.8-0.5-9.5,1.7-12.4,5.6l-48.8,65c-5.8,7.7-17.4,7.7-23.2,0l-48.8-65l0.1-0.1C160.7,308,156,305.9,151.2,306.3z"/>
            </svg>
            ';
      }
      // Link to the user profile edit form, returning to this page.
      $edit_url = Url::fromUri('internal:/user/' . $user->id() . '/edit?destination=community-persona');
      $edit_link = Link::fromTextAndUrl('Edit Persona', $edit_url);
      $edit_link = $edit_link->toRenderable();
      $edit_link['#attributes'] = [
        'class' => [
          'btn',
          'btn-primary',
          'btn-sm',
          'w-100',
          'w-full',
        ],
      ];
      $edit_link = $public ? "" : $edit_link;

      // Show the access organization when set, and fall back to the
      // institution field when the organization is "Other" (3695) or unset.
      $org_array = $user_entity->get('field_access_organization')->getValue();
      $institution = $user_entity->get('field_institution')->value;

      if (!empty($org_array) && !empty($org_array[0])) {
        $node_id = $org_array[0]['target_id'];
        // Organization "Other" (node ID 3695) keeps the institution field.
        if ($node_id != 3695 && !empty($node_id)) {
          $org_node = $this->entityTypeManager->getStorage('node')->load($node_id);
          if ($org_node) {
            $institution = $org_node->label();
          }
        }
      }

      $roles = $user_entity->getRoles();
      $is_student = array_search('student', $roles) !== FALSE;
      $academic_status = $is_student
        ? $user_entity->get('field_academic_status')->value : '';

      $academic_terms_map = $user_entity->get('field_academic_status')->getSettings()['allowed_values'];
      // If $academic_status is not empty, map it to the label.
      if (!empty($academic_status)) {
        $academic_status = $academic_terms_map[$academic_status];
      }
      else {
        $academic_status = '';
      }
      // Don't display these roles.
      $roles_not_to_include = [
        'authenticated',
        'administrator',
        'Masquerade',
        'exportpeople',
        'site_developer',
        'ccmnet',
      ];
      foreach ($roles_not_to_include as $role) {
        $key = array_search($role, $roles);
        if ($key !== FALSE) {
          unset($roles[$key]);
        }
      }
      $role = new RolesLabelLookup($roles);
      $roles = $role->getRoleLabelsString();
      $regions = $user_entity->get('field_region')->getValue();
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms = [];
      foreach ($regions as $region) {
        $region_tid = $region['target_id'];
        $region_term = $term_storage->load($region_tid);
        $terms[$region_tid] = $region_term ? $region_term->label() : '';
      }
      if (!$public) {
        $cssn_role_url = Url::fromUri('internal:/form/edit-your-cssn-roles?destination=community-persona');
        $cssn_role_link = Link::fromTextAndUrl(Markup::create('<i class="text-dark bi-pencil-square" aria-hidden="true"></i> Edit Roles'), $cssn_role_url);
        $cssn_role_renderable = $cssn_role_link->toRenderable();
        $cssn_role = $cssn_role_renderable;
        $cssn_role['#attributes']['class'] = ['text-dark'];
      }
      else {
        $cssn_role = "";
      }

      $user_badges = $this->taxBadges($user_entity, 'field_user_badges');

      $ood_badges = $this->taxBadges($user_entity, 'field_open_ondemand_badges');

      // Programs.
      $program = implode(', ', $terms);
      // If $terms contains 'ACCESS CSSN', then the user is a CSSN member.
      $cssn_member = in_array('ACCESS CSSN', $terms) ? TRUE : FALSE;
      $cssn_indicator = "";
      if ($cssn_member) {
        $cssn_indicator = "<span class='text-primary'><i class='bi-square-fill text-orange' aria-hidden='true'></i></span>";
        $cssn = "CSSN Member";
      }
      elseif ($public) {
        $cssn_indicator = "<span class='text-secondary'><i class='bi-square-fill' aria-hidden='true'></i></span>";
        $cssn = "Not a CSSN Member";
      }
      else {
        $cssn_url = Url::fromUri('https://support.access-ci.org/community/cssn#join-cssn');
        $cssn_link = Link::fromTextAndUrl('Join the CSSN', $cssn_url);
        $cssn_renderable = $cssn_link->toRenderable();
        $cssn = $cssn_renderable;
        $cssn['#attributes']['class'] = ['btn', 'btn-primary', 'btn-sm', 'py-1', 'px-2'];
      }
      $cssn_more_url = Url::fromUri('https://support.access-ci.org/community/cssn');
      $cssn_more_link = Link::fromTextAndUrl(Markup::create('<i class="text-dark bi-info-circle text-md-teal" aria-hidden="true"></i> info'), $cssn_more_url);
      $cssn_more_renderable = $cssn_more_link->toRenderable();
      $cssn_more = $cssn_more_renderable;
      $cssn_more['#attributes']['class'] = [
        'text-dark',
        'text-md-teal',
        'no-underline',
      ];
      $cssn_more['#attributes']['aria-label'] = $this->t('Information about CSSN');

      // Get the user's email address.
      $user_id = $user->id();
      // Show the email button on public profiles.
      $send_email = $public ? "<a href='/user/$user_id/contact?destination=community-persona/$user_id' class='w-100 btn btn-primary btn-sm py-1 px-2'><i class='bi-envelope' aria-hidden='true'></i> Send Email</a>" : "";

      // Get Job title.
      $job_title = $user_entity->get('field_current_occupation')->value;

      $askci = $user_entity->get('field_askci_username')->value;
      $github = $user_entity->get('field_github_username')->value;
      $ood_discourse = $user_entity->get('field_discourse_openondemand_org')->value;

      $persona_block['string'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="persona prose">
                          {{ user_image | raw }}

                          {% if public %}
                            <h1 class="mt-0 mb-4 text-3xl font-bold">
                              {{ first_name }} {{ last_name }}
                            </h1>
                          {% else %}
                            <h2 {% if pronouns == "DONOTDISPLAY" %}class="m-0" {% endif %}>
                              {{ first_name }} {{ last_name }}
                            </h2>
                          {% endif %}

                          {% if pronouns == "DONOTDISPLAY" %}
                            <div><strong>Pronouns:</strong> {{ pronouns }}</div>
                          {% endif %}
                          <div class="institution text-md-teal text-lg font-bold">{{ institution }}</div>
                          {% if job_title %}
                            <div class="mb-3"><i>{{ job_title }}</i></div>
                          {% endif %}
                          {% if academic_status and academic_status != "I am not in an academic program but interested in shifting focus to research computing facilitation"  %}
                            <div class="academic-status mb-3">{{ academic_status }}</div>
                          {% endif %}
                          {% if cssn != "Not a CSSN Member" %}
                            <div class="d-flex justify-content-between flex justify-between">
                              <p>{{ cssn_indicator | raw }} <strong>{{ cssn }}</strong></p>
                              <div>{{ cssn_more }}</div>
                            </div>
                          {% endif %}
                          {% if user_badges %}
                            {{ user_badges | raw }}
                          {% endif %}
                          {% if ood_badges %}
                            <h2 class="h4 text-lg font-bold leading-5 mt-3">{{ ood_badges_title }}</h2>
                            {{ ood_badges | raw }}
                          {% endif %}

                          <div>
                            {% if askci or discourse_ood or github %}
                              <div class="mb-3 py-3">
                                <h2 class="h4 text-lg font-bold leading-5 mt-0">{{ profile_text }}</h2>

                                {% if askci %}
                                  <a href="https://ask.cyberinfrastructure.org/u/{{ askci }}" class="d-flex flex mt-1 text-decoration-none no-underline" target="_blank" rel="noopener noreferrer">
                                    <img src="/modules/custom/access/modules/cssn/images/askci.svg" alt="ask.ci" width="20" height="20" class="me-1 mr-2" />
                                    <span><strong>@{{ askci }}</strong></span>
                                  </a>
                                {% endif %}

                                {% if discourse_ood %}
                                  <a href="https://discourse.openondemand.org/u/{{ discourse_ood }}" class="d-flex flex mt-1 text-decoration-none no-underline" target="_blank" rel="noopener noreferrer">
                                    <img src="/modules/custom/access/modules/cssn/images/ood.svg" alt="discourse.openondemand.org" width="20" height="20" class="me-1 mr-2" />
                                    <span><strong>@{{ discourse_ood }}</strong></span>
                                  </a>
                                {% endif %}

                                {% if github %}
                                  <div class="d-flex flex">
                                  <a href="https://github.com/{{ github }}" class="d-flex flex mt-1 text-decoration-none no-underline" target="_blank" rel="noopener noreferrer">
                                    <img src="/modules/custom/access/modules/cssn/images/github.svg" alt="GitHub logo" width="20" height="20" class="me-1 mr-2" />
                                    <span><strong>@{{ github }}</strong></span>
                                  </a>
                                {% endif %}

                              </div>
                            {% endif %}

                            <div class="d-flex justify-content-between flex justify-between mb-3 py-3">
                              {% if roles %}
                                <div><h2 class="h4 text-lg font-bold leading-5 mt-0">{{ role_text }}:</h2>{{ roles | raw }}</div>
                              {% endif %}
                              {% if cssn_role %}
                                <div>{{ cssn_role }}</div>
                              {% endif %}
                            </div>
                            {% if program %}
                              <div class="mb-3"><h2 class="h4 text-lg font-bold leading-5 mt-0">{{ program_text }}:</h2>{{ program }}</div>
                            {% endif %}
                            <div class="w-100">
                             {{ send_email | raw }}
                            {{ edit_link | raw }}
                            </div>
                          </div>
                        </div>',
        '#context' => [
          'user_image' => $user_image,
          'edit_link' => $edit_link,
          'first_name' => $first_name,
          'last_name' => $last_name,
          'pronouns' => $pronouns,
          'institution' => $institution,
          'job_title' => $job_title,
          'academic_status' => $academic_status,
          'cssn' => $cssn,
          'cssn_indicator' => $cssn_indicator,
          'cssn_more' => $cssn_more,
          'user_badges' => $user_badges,
          'ood_badges_title' => $this->t('Open OnDemand Badges'),
          'ood_badges' => $ood_badges,
          'profile_text' => $this->t('Profiles'),
          'askci' => $askci,
          'github' => $github,
          'discourse_ood' => $ood_discourse,
          'roles' => $roles,
          'role_text' => $this->t('Roles'),
          'cssn_role' => $cssn_role,
          'program' => $program,
          'program_text' => $this->t('Programs'),
          'send_email' => $send_email,
          'public' => $public,
        ],
      ];
      return $persona_block;
    }
    else {
      return [];
    }
  }

  /**
   * Builds the markup for the badges referenced by a user field.
   *
   * @param \Drupal\user\UserInterface $user_entity
   *   The user whose badges are rendered.
   * @param string $field
   *   The name of the badge reference field.
   *
   * @return string
   *   The rendered badge list, or an empty string when there are no badges.
   */
  private function taxBadges($user_entity, string $field): string {
    // Badges.
    $badges = $user_entity->get($field)->getValue();

    if (empty($badges)) {
      return "";
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $user_badges = '<ul class="flex flex-wrap p-0 m-0">';
    foreach ($badges as $badge) {
      $term = $term_storage->load($badge['target_id']);
      /** @var \Drupal\file\FileInterface|null $badge_file */
      $badge_file = $term && !$term->get('field_badge')->isEmpty()
        ? $term->get('field_badge')->entity : NULL;
      if ($badge_file) {
        $name = $term->get('name')->value;
        $badge_value = $term->get('field_badge')->getValue();
        $image_alt = $badge_value[0]['alt'] ?? '';
        $image = $this->fileUrlGenerator->generateAbsoluteString($badge_file->getFileUri());
        if ($image) {
          if ($name) {
            $user_badges .= "<li class='badge mt-0 ms-0 p-0' data-placement='top' data-toggle='tooltip' title='$name'>";
          }
          else {
            $user_badges .= "<li>";
          }

          $user_badges .= "<img src='$image' alt='$image_alt' title='$name' class='mt-0 me-2 mb-2' width='55' height='55' />";

          $user_badges .= "</li>";
        }
      }
    }
    $user_badges .= '</ul>';

    return $user_badges;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
