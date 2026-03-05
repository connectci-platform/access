<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\taxonomy\Entity\Term;

/**
 * Index selected user skills id.
 *
 * @SearchApiProcessor(
 *   id = "user_skills_id",
 *   label = @Translation("User Skills Id"),
 *   description = @Translation("Index selected user skills id."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserSkillsId extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('User Skills Id'),
        'description' => $this->t('The user skills.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_skills_id'] = new ProcessorProperty($definition);

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
        ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_skills_id');
      foreach ($fields as $field) {
        foreach ($flagged_skills as $flagged_skill) {
          $term = Term::load($flagged_skill);
          $term_title = $term->get('name')->value;
          $term_title = str_replace(' ', '', $term_title);
          $term_id = $term->id();

          $value = "$term_title,$term_id";
          $field->addValue($value);
        }
      }
    }
  }

}
