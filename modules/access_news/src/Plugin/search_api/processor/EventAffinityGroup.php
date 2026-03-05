<?php

namespace Drupal\access_news\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Search API Processor for indexing Event affinity group.
 *
 * @SearchApiProcessor(
 *   id = "custom_event_affinity_group",
 *   label = @Translation("Custom Event Affinity Group"),
 *   description = @Translation("Add event affinity group to the index."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class EventAffinityGroup extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Custom Event Affinity Group'),
        'description' => $this->t('Custom Affinity Group for the event.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_event_affinity_group'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_event_affinity_group');
    foreach ($fields as $field) {
      $series = $entity->getEventSeries();
      if (empty($series)) {
        return;
      }

      // Get field 'field_affinity_group' value from the event series.
      if ($series->hasField('field_affinity_group') && !$series->get('field_affinity_group')->isEmpty()) {

        foreach ($series->get('field_affinity_group')->getValue() as $value) {
          if (isset($value['target_id'])) {
            $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($value['target_id']);
            if ($term) {
              $field->addValue($term->getName());
            }
          }
        }

      }

    }
  }

}
