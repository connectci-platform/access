<?php

namespace Drupal\access_news\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

// phpcs:disable Drupal.Files.LineLength.TooLong
/**
 * Search API Processor for indexing custom Affinity Group field for Announcement.
 *
 * @SearchApiProcessor(
 *   id = "custom_announcement_affinity_group",
 *   label = @Translation("Custom Affinity Group field for Announcement"),
 *   description = @Translation("Indexes the custom Affinity Group field for Announcement content type."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
// phpcs:enable Drupal.Files.LineLength.TooLong
class AnnouncementAg extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Custom Affinity Group field for Announcement'),
        'description' => $this->t('Indexes the custom Affinity Group field for Announcement content type.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_announcement_ag'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_announcement_ag');
    foreach ($fields as $field) {
      // Get field 'field_affinity_group' value from the entity.
      if ($entity->hasField('field_affinity_group') && !$entity->get('field_affinity_group')->isEmpty()) {

        foreach ($entity->get('field_affinity_group')->getValue() as $value) {
          if (isset($value['target_id'])) {
            $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($value['target_id']);
            $field->addValue($term->getName());
          }
        }

      }

    }
  }

}
