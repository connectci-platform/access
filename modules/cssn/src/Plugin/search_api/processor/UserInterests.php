<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\taxonomy\Entity\Term;

/**
 * Index selected user interests.
 *
 * @SearchApiProcessor(
 *   id = "user_interests",
 *   label = @Translation("User Interests"),
 *   description = @Translation("Index selected user interests."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserInterests extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('User Interest'),
        'description' => $this->t('The user interest.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_interest'] = new ProcessorProperty($definition);

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
    $term->condition('fl.flag_id', 'interest');
    $term->fields('fl', ['entity_id']);
    $flagged_interests = $term->execute()->fetchCol();

    if ($flagged_interests != NULL) {
      $fields = $this->getFieldsHelper()
        ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_interest');
      foreach ($fields as $field) {
        foreach ($flagged_interests as $flagged_interest) {
          $term_title = Term::load($flagged_interest)->get('name')->value;
          $field->addValue($term_title);
        }
      }
    }
  }

}
