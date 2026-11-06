<?php

namespace Drupal\access_affinitygroup\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Displays Resources for Affinity Group in layout.
 *
 * @Block(
 *   id = "resources_for_affinity_group",
 *   admin_label = "Resources for Affinity Group view",
 * )
 */
class ResourcesForAffinityGroup extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $cache = CacheableMetadata::createFromObject($this);
    $node = \Drupal::routeMatch()->getParameter('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'affinity_group') {
      $build = [];
      $cache->applyTo($build);
      return $build;
    }
    // Visibility depends on the node's privacy and coordinators.
    $cache->addCacheableDependency($node);
    $cache->addCacheTags(['webform_submission_list']);

    // Load field_resources_entity_reference field.
    $field_resources_entity_reference = $node->get('field_resources_entity_reference')->getValue();
    // Create empty string in case the following if statement is not true.
    $rendered = '';

    // Get field field_affinity_group_category.
    $affinity_group_values = $node->get('field_affinity_group')->getValue();
    $webform_submissions = [];
    if (!empty($affinity_group_values[0]['target_id'])) {
      $affinity_group_tag = $affinity_group_values[0]['target_id'];
      $webform_submissions = \Drupal::database()->select('webform_submission_data', 'wsd')
        ->fields('wsd', ['sid'])
        ->condition('name', 'affinity_group')
        ->condition('value', $affinity_group_tag)
        ->execute()
        ->fetchCol();
    }

    foreach ($webform_submissions as $ws) {
      $field_resources_entity_reference[] = [
        'target_id' => $ws,
      ];
    }

    // A resource can be both referenced and tagged; list it once.
    $seen = [];
    foreach ($field_resources_entity_reference as $key => $value) {
      if (isset($seen[$value['target_id']])) {
        unset($field_resources_entity_reference[$key]);
        continue;
      }
      $seen[$value['target_id']] = TRUE;
    }
    $field_resources_entity_reference = array_values($field_resources_entity_reference);

    // Only administrators and this group's coordinators may allow resources.
    $user = \Drupal::currentUser();
    $roles = $user->getRoles();
    $coordinator_ids = array_map('intval', array_column($node->get('field_coordinator')->getValue(), 'target_id'));
    $is_coordinator = $user->isAuthenticated() && in_array((int) $user->id(), $coordinator_ids, TRUE);
    $can_allow = in_array('administrator', $roles) || $is_coordinator;
    $can_see_pending = $can_allow || in_array('match_pm', $roles);

    if (array_key_exists(0, $field_resources_entity_reference)) {
      $rendered = '<h2 class="text-white-er text-xl font-semibold border-bottom pb-2 bg-dark-teal py-2 px-4">Knowledge Base Resources</h2>';
      $header = [
        [
          'data' => 'Title',
          'class' => [
            'border-x-0',
            'border-b',
            'border-t-0',
            'border-gray',
            'border-solid',
          ],
        ],
        [
          'data' => 'Tags',
          'class' => [
            'border-x-0',
            'border-b',
            'border-t-0',
            'border-gray',
            'border-solid',
          ],
        ],
        [
          'data' => 'Skill Level',
          'class' => [
            'border-x-0',
            'border-b',
            'border-t-0',
            'border-gray',
            'border-solid',
          ],
        ],
      ];

      $rows = [];
      $private = $node->get('field_ag_private')->getValue();

      foreach ($field_resources_entity_reference as $value) {
        $needs_approval = '';

        $webform_submission = WebformSubmission::load($value['target_id']);
        if (!$webform_submission) {
          // Webform submissions are sanitized in dev enviroments.
          continue;
        }
        $submission_data = $webform_submission->getData();

        if ($submission_data['approved'] == 0) {
          // If private checkbox doesn't exist, skip.
          if (!array_key_exists(0, $private)) {
            continue;
          }
          else {
            // If private checkbox exists and is not checked, skip.
            if ($private[0]['value'] == 0) {
              continue;
            }

            if ($submission_data['resource_allowed_on_affinity_group'] == 0) {
              // Pending resources are visible to admins, coordinators of this
              // group and match PMs only.
              if (!$can_see_pending) {
                continue;
              }
              // Only those who can edit the submission get the link.
              if ($can_allow) {
                $needs_approval = [
                  '#type' => 'link',
                  '#title' => '⚠️ Allow on this Affinity Group ⚠️ ',
                  '#url' => Url::fromRoute('entity.webform_submission.edit_form', ['webform' => 'resource', 'webform_submission' => $value['target_id']]),
                  '#attributes' => ['class' => ['text-red-500', 'font-bold']],
                ];

                $needs_approval = \Drupal::service('renderer')->render($needs_approval)->__toString();
              }
            }
          }
        }

        // Ci link name and url.
        $ci_link_path = '/knowledge-base/resources/';
        $ci_link = [
          '#type' => 'link',
          '#title' => $submission_data['title'],
          '#url' => Url::fromUri('internal:' . $ci_link_path . $value['target_id']),
        ];
        $ci_link_name = '<div>' . \Drupal::service('renderer')->render($ci_link)->__toString() . '</div>';

        $tags = '';
        foreach ($submission_data['tags'] as $tag) {
          // Lookup tags.
          $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tag);
          if ($term !== NULL) {
            $link = [
              '#type' => 'link',
              '#title' => $term->getName(),
              '#url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tag]),
              '#attributes' => ['class' => ['px-2', 'py-1', 'font-normal', 'no-underline', 'border', 'border-black', 'border-solid', 'hover--border-dark-teal', 'hover--text-dark-teal', 'w-fit']],
            ];
            $tags .= '<div class="mr-2 me-4 mb-2">' . \Drupal::service('renderer')->render($link)->__toString() . '</div>';
          }
        }
        $tags = '<div class="square-tags d-flex flex flex-wrap">' . $tags . '</div>';
        // Lookup skills by id and make an array of names.
        $skills = '';
        $skill_list = [];
        foreach ($submission_data['skill_level'] as $skill) {
          $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($skill);
          if ($term !== NULL) {
            array_push($skill_list, $term->getName());
          }
        }

        $skills = \Drupal::service('access_misc.skillLevel')->getSkillsImage($skill_list);

        $rows[] = [
          '#sort' => mb_strtolower((string) $submission_data['title']),
          'name' => [
            'data' => [
              '#markup' => $ci_link_name . $needs_approval,
            ],
            'class' => [
              'border-x-0',
              'border-b',
              'border-t-0',
              'border-gray',
              'border-solid',
              'pb-4',
            ],
          ],
          'tags' => [
            'data' => [
              '#markup' => $tags,
            ],
            'class' => [
              'border-x-0',
              'border-b',
              'border-t-0',
              'border-gray',
              'border-solid',
              'pb-4',
            ],
          ],
          'skill' => [
            'data' => [
              '#markup' => $skills,
            ],
            'class' => [
              'border-x-0',
              'border-b',
              'border-t-0',
              'border-gray',
              'border-solid',
              'pb-4',
            ],
          ],
        ];
      }

      usort($rows, fn($a, $b) => strcmp($a['#sort'], $b['#sort']));
      foreach ($rows as &$row) {
        unset($row['#sort']);
      }
      unset($row);

      $html['ci-links'] = [
        '#theme' => 'table',
        '#sticky' => TRUE,
        '#header' => $header,
        '#rows' => $rows,
        '#attributes' => ['id' => 'ci-links', 'class' => ['table-search border-spacing-0']],
      ];
      $rendered .= \Drupal::service('renderer')->render($html['ci-links']);
    }

    $build = [
      ['#markup' => $rendered],
    ];
    $cache->applyTo($build);
    return $build;

  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ['affinity_group_ci_links']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

}
