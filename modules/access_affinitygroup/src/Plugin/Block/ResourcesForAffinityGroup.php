<?php

namespace Drupal\access_affinitygroup\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
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
    $node = \Drupal::routeMatch()->getParameter('node');
    $node = $node ? $node : \Drupal::entityTypeManager()->getStorage('node')->load(327);
    // Load field_resources_entity_reference field.
    $field_resources_entity_reference = $node->get('field_resources_entity_reference')->getValue();
    // Create empty string in case the following if statement is not true.
    $rendered = '';
    if (!empty($field_resources_entity_reference)) {
      // Get field field_affinity_group_category.
      $affinity_group_tag = $node->get('field_affinity_group')->getValue();

      // Lookup webform submission with data that has 'affinity_group' with the value of $affinity_group_tag.
      $affinity_group_tag = $affinity_group_tag[0]['target_id'];
      $webform_submissions = \Drupal::database()->select('webform_submission_data', 'wsd')
        ->fields('wsd', ['sid'])
        ->condition('name', 'affinity_group')
        ->condition('value', $affinity_group_tag)
        ->execute()
        ->fetchCol();

      foreach ($webform_submissions as $ws) {
        $field_resources_entity_reference[] = [
          'target_id' => $ws
        ];
      }

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
        $webform_submission = WebformSubmission::load($value['target_id']);
        if (!$webform_submission) {
          // Webform submissions are sanitized in dev enviroments.
          continue;
        }
        $submission_data = $webform_submission->getData();

        if ($submission_data['approved'] == 0) {
          if (!array_key_exists(0, $private)) {
            continue;
          }
          else {
            if ($private[0]['value'] == 0) {
              continue;
            }
          }
        }

        // Ci link name and url.
        // On ASP, use /knowledge-base/ci-links/{sid}.
        // On other domains, use /ci-links/{sid}.
        $token = \Drupal::token();
        $domainName = Html::getClass($token->replace(t('[domain:name]')));
        if ($domainName == 'access-support') {
          $ci_link_path = '/knowledge-base/resources/';
        }
        else {
          $ci_link_path = '/knowledge-base/resources/';
        }
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
          'name' => [
            'data' => [
              '#markup' => $ci_link_name,
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

      $html['ci-links'] = [
        '#theme' => 'table',
        '#sticky' => TRUE,
        '#header' => $header,
        '#rows' => $rows,
        '#attributes' => ['id' => 'ci-links', 'class' => ['table-search border-spacing-0']],
      ];
      $rendered .= \Drupal::service('renderer')->render($html['ci-links']);
    }

    return [
      ['#markup' => $rendered],
    ];

  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ['affinity_group_ci_links']);
  }

  /**
   *
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
