<?php

namespace Drupal\access_misc\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

// phpcs:disable Drupal.Files.LineLength.TooLong
/**
 * Search API Processor for indexing Event Skill Level as the built in one isn't working.
 *
 * @SearchApiProcessor(
 *   id = "custom_event_skill_level",
 *   label = @Translation("Custom Event Skill Level"),
 *   description = @Translation("Adds the skill level of the event."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
// phpcs:enable Drupal.Files.LineLength.TooLong
class EventSkillLevel extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Custom Event Skill Level'),
        'description' => $this->t('The skill level of the event.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_custom_event_skill_level'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_event_skill_level');
    foreach ($fields as $field) {
      $series = $entity->getEventSeries();
      if (empty($series)) {
        return;
      }

      $sl = $series->get('field_skill_level')->getValue();

      foreach ($sl as $value) {
        $field->addValue($value['value']);
      }

    }
  }

}
