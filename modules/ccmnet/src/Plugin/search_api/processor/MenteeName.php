<?php

namespace Drupal\ccmnet\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search API Processor for indexing Mentee field using the users name.
 *
 * @SearchApiProcessor(
 *   id = "mentee_name",
 *   label = @Translation("Mentee Name Processor"),
 *   description = @Translation("Index the Mentee Name field."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class MenteeName extends ProcessorPluginBase {

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
        'label' => $this->t('Mentee Name'),
        'description' => $this->t('The name of the mentee.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_mentee_name'] = new ProcessorProperty($definition);

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
      ->filterForPropertyPath($fields, NULL, 'search_api_mentee_name');
    foreach ($fields as $field) {
      $uid = $entity->get('field_mentee')->getValue();
      if (empty($uid)) {
        return;
      }
      $uid = $uid[0]['target_id'];
      $user_lookup = $this->entityTypeManager->getStorage('user')->load($uid);
      $first_name = $user_lookup->get('field_user_first_name')->value;
      $last_name = $user_lookup->get('field_user_last_name')->value;
      $name = "$first_name $last_name";
      $field->addValue($name);
    }
  }

}
