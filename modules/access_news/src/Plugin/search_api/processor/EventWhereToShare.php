<?php

namespace Drupal\access_news\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Search API Processor for indexing Event where to share checkboxes field.
 *
 * @SearchApiProcessor(
 *   id = "event_where_to_share",
 *   label = @Translation("Event Where to Share"),
 *   description = @Translation("Indexes the Event Where to Share field."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class EventWhereToShare extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Event Where to Share'),
        'description' => $this->t('The event no show type.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_where_to_share'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_where_to_share');
    foreach ($fields as $field) {
      $series = $entity->getEventSeries();
      if (empty($series)) {
        return;
      }

      $share = $series->get('field_choose_where_to_share_this')->getValue();

      if ($share != NULL) {
        foreach ($share as $value) {
          if (isset($value['value'])) {
            $field->addValue($value['value']);
          }
        }
      }

    }
  }

}
