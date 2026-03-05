<?php

namespace Drupal\ccmnet\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Search API Processor for indexing Mentee field using the users name.
 *
 * @SearchApiProcessor(
 *   id = "mentee_name",
 *   label = @Translation("Mentee Name Processor"),
 *   description = @Translation("Index the Mentee Name field."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class MenteeName extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Mentee Name'),
        'description' => $this->t('The name of the mentee.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_mentee_name'] = new ProcessorProperty($definition);

    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $entity = $item->getOriginalObject()->getValue();

    $fields = $item->getFields();
    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($fields, NULL, 'search_api_mentee_name');
    foreach ($fields as $field) {
      $uid = $entity->get('field_mentee')->getValue();
      if (empty($uid)) {
        return;
      }
      $uid = $uid[0]['target_id'];
      $user_lookup = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      $first_name = $user_lookup->get('field_user_first_name')->value;
      $last_name = $user_lookup->get('field_user_last_name')->value;
      $name = "$first_name $last_name";
      $field->addValue($name);
    }
  }

}
