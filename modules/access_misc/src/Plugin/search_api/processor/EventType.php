<?php

namespace Drupal\access_misc\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

// phpcs:disable Drupal.Files.LineLength.TooLong
/**
 * Search API Processor for indexing Event type as the built in one isn't working.
 *
 * @SearchApiProcessor(
 *   id = "custom_event_type",
 *   label = @Translation("Custom Event Type"),
 *   description = @Translation("Add event type to the index."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
// phpcs:enable Drupal.Files.LineLength.TooLong
class EventType extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Custom Event Type'),
        'description' => $this->t('The type of the event.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_event_type'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_event_type');
    foreach ($fields as $field) {
      $series = $entity->getEventSeries();
      if (empty($series)) {
        return;
      }

      $type = $series->get('field_event_type')->getValue();

      // Get the allowed values (labels) for the event_type field.
      $field_storage = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('eventseries')['field_event_type'];
      $allowed_values = options_allowed_values($field_storage);

      foreach ($type as $value) {
        // Add the label instead of the raw value.
        $raw_value = $value['value'];
        $label = $allowed_values[$raw_value] ?? $raw_value;
        $field->addValue($label);
      }

    }
  }

}
