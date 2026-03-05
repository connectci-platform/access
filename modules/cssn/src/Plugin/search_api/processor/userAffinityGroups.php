<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\taxonomy\Entity\Term;

/**
 * Index selected user flagged affinity groups.
 *
 * @SearchApiProcessor(
 *   id = "user_affinity_groups",
 *   label = @Translation("User Affinity Groups"),
 *   description = @Translation("Index selected user flagged affinity groups."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserAffinityGroups extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('User Affinity Groups'),
        'description' => $this->t('The affinity groups that the user has flagged.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_affinity_groups'] = new ProcessorProperty($definition);

    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $user = $item->getOriginalObject()->getValue();
    $term = \Drupal::database()->select('flagging', 'fl');
    $term->condition('fl.uid', $user->id());
    $term->condition('fl.flag_id', 'affinity_group');
    $term->fields('fl', ['entity_id']);
    $flagged_skills = $term->execute()->fetchCol();

    if ($flagged_skills != NULL) {
      $fields = $this->getFieldsHelper()
        ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_affinity_groups');
      foreach ($fields as $field) {
        foreach ($flagged_skills as $flagged_skill) {
          $term_title = Term::load($flagged_skill)->get('name')->value;
          $field->addValue($term_title);
        }
      }
    }
  }

}
