<?php

namespace Drupal\access_news\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search API Processor for indexing custom Affinity Group field.
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
class AnnouncementAg extends ProcessorPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $processor->entityTypeManager = $container->get('entity_type.manager');

    return $processor;
  }

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
   *
   * @param \Drupal\search_api\Item\ItemInterface<\Drupal\search_api\Item\FieldInterface> $item
   *   The item whose fields should be added.
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $item->getOriginalObject()->getValue();

    $fields = $item->getFields();
    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($fields, NULL, 'search_api_custom_announcement_ag');
    foreach ($fields as $field) {
      // Get field 'field_affinity_group' value from the entity.
      if ($entity->hasField('field_affinity_group') && !$entity->get('field_affinity_group')->isEmpty()) {

        foreach ($entity->get('field_affinity_group')->getValue() as $value) {
          if (isset($value['target_id'])) {
            $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($value['target_id']);
            if (!$term) {
              continue;
            }
            $field->addValue($term->getName());
          }
        }

      }

    }
  }

}
