<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\taxonomy\Entity\Term;

/**
 * Index selected user skills.
 *
 * @SearchApiProcessor(
 *   id = "user_skills",
 *   label = @Translation("User Skills"),
 *   description = @Translation("Index selected user skills."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserSkills extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('User Skills'),
        'description' => $this->t('The user skills.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_skills'] = new ProcessorProperty($definition);

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
    $term->condition('fl.flag_id', 'skill');
    $term->fields('fl', ['entity_id']);
    $flagged_skills = $term->execute()->fetchCol();

    if ($flagged_skills != NULL) {
      $fields = $this->getFieldsHelper()
        ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_skills');
      foreach ($fields as $field) {
        foreach ($flagged_skills as $flagged_skill) {
          $term_title = Term::load($flagged_skill)->get('name')->value;
          $term_title = str_replace(' ', '', $term_title);
          $field->addValue($term_title);
        }
      }
    }
  }

}
