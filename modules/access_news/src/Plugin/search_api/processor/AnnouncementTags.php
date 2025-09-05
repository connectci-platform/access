<?php

namespace Drupal\access_news\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Search API Processor for indexing Announcments tags.
 *
 * @SearchApiProcessor(
 *   id = "announcement_tags",
 *   label = @Translation("Announcment Tags"),
 *   description = @Translation("Adds the tags to the announcement index."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class AnnouncementTags extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Announcement Tags'),
        'description' => $this->t('The tags associated with the announcement nodes.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_announcement_tags'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_announcement_tags');
    foreach ($fields as $field) {

      if ($entity->hasField('field_tags') && !$entity->get('field_tags')->isEmpty()) {

        foreach ($entity->get('field_tags')->getValue() as $value) {
          if (isset($value['target_id'])) {
            $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($value['target_id']);
            $field->addValue($term->getName());
          }
        }

      }

    }
  }

}
