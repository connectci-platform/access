<?php

namespace Drupal\access_misc\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

// phpcs:disable Drupal.Files.LineLength.TooLong
/**
 * Search API Processor for indexing Event tags as the built in one isn't working.
 *
 * @SearchApiProcessor(
 *   id = "event_tags",
 *   label = @Translation("Event Tags"),
 *   description = @Translation("Adds the tags of the event to the indexed data."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
// phpcs:enable Drupal.Files.LineLength.TooLong
class EventTags extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Custom Event Tags'),
        'description' => $this->t('The tags of the event.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_event_tags'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_event_tags');
    foreach ($fields as $field) {
      $series = $entity->getEventSeries();
      if (empty($series)) {
        return;
      }

      $tags = $series->get('field_tags')->getValue();

      foreach ($tags as $tag) {
        // Get the tag name from tid.
        $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tag['target_id']);
        if ($term) {
          $term_name = $term->getName();
          $field->addValue($term_name);
        }
      }

    }
  }

}
