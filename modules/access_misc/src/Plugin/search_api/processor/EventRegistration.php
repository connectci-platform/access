<?php

namespace Drupal\access_misc\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Indexes native-ACCESS registration config from the parent eventseries onto
 * eventinstance rows: enabled flag, capacity, waitlist. Mirrors EventType.
 *
 * @SearchApiProcessor(
 *   id = "custom_event_registration",
 *   label = @Translation("Custom Event Registration"),
 *   description = @Translation("Add native-registration config (enabled/capacity/waitlist) to the index."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class EventRegistration extends ProcessorPluginBase {

  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];
    if (!$datasource) {
      $properties['search_api_registration_enabled'] = new ProcessorProperty([
        'label' => $this->t('Registration enabled'),
        'description' => $this->t('Native ACCESS registration is enabled.'),
        'type' => 'boolean',
        'processor_id' => $this->getPluginId(),
      ]);
      $properties['search_api_registration_capacity'] = new ProcessorProperty([
        'label' => $this->t('Registration capacity'),
        'description' => $this->t('Per-instance seat cap (0/empty = unlimited).'),
        'type' => 'integer',
        'processor_id' => $this->getPluginId(),
      ]);
      $properties['search_api_registration_has_waitlist'] = new ProcessorProperty([
        'label' => $this->t('Registration has waitlist'),
        'description' => $this->t('Waitlist is enabled.'),
        'type' => 'boolean',
        'processor_id' => $this->getPluginId(),
      ]);
    }
    return $properties;
  }

  public function addFieldValues(ItemInterface $item) {
    $entity = $item->getOriginalObject()->getValue();
    if (!method_exists($entity, 'getEventSeries')) {
      return;
    }
    $series = $entity->getEventSeries();
    if (empty($series) || !$series->hasField('event_registration')) {
      return;
    }
    $reg = $series->get('event_registration')->first();
    $enabled = $reg ? (bool) $reg->registration : FALSE;
    $capacity = $reg && $reg->capacity ? (int) $reg->capacity : 0;
    $waitlist = $reg ? (bool) $reg->waitlist : FALSE;

    $this->setValue($item, 'search_api_registration_enabled', $enabled);
    $this->setValue($item, 'search_api_registration_capacity', $capacity);
    $this->setValue($item, 'search_api_registration_has_waitlist', $waitlist);
  }

  private function setValue(ItemInterface $item, string $propertyPath, $value): void {
    $fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL, $propertyPath);
    foreach ($fields as $field) {
      $field->addValue($value);
    }
  }

}
