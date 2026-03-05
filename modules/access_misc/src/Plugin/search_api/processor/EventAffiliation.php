<?php

namespace Drupal\access_misc\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

// phpcs:disable Drupal.Files.LineLength.TooLong
/**
 * Search API Processor for indexing Event affiliation as the built in one isn't working.
 *
 * @SearchApiProcessor(
 *   id = "custom_event_affiliation",
 *   label = @Translation("Custom Event Affiliation"),
 *   description = @Translation("Add event affiliation to the index."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
// phpcs:enable Drupal.Files.LineLength.TooLong
class EventAffiliation extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Custom Event Affiliation'),
        'description' => $this->t('Custom Affiliation Tags for the event.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_event_affiliation'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_event_affiliation');
    foreach ($fields as $field) {
      $series = $entity->getEventSeries();
      if (empty($series)) {
        return;
      }

      $affiliation = $series->get('field_affiliation')->view();

      foreach ($affiliation as $aff) {
        // Skip if not array.
        if (!is_array($aff)) {
          continue;
        }
        // Skip if no markup.
        if (!array_key_exists('#markup', $aff)) {
          continue;
        }
        $field->addValue($aff['#markup']);
      }

    }
  }

}
