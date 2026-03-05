<?php

namespace Drupal\access_misc\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Search API Processor for indexing Event affinity groups.
 *
 * @SearchApiProcessor(
 *   id = "custom_event_affinity_group",
 *   label = @Translation("Custom Event Affinity Group"),
 *   description = @Translation("Adds the affinity groups of the event to the indexed data."),
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
        'description' => $this->t('The affinity groups of the event.'),
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

      $affinity_groups = $series->get('field_affinity_group_node')->getValue();

      foreach ($affinity_groups as $ag) {
        // Get the affinity group title from nid.
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($ag['target_id']);
        if ($node) {
          $ag_title = $node->getTitle();
          $field->addValue($ag_title);
        }
      }

    }
  }

}
