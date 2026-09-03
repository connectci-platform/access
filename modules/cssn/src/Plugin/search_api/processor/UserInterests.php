<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Index selected user interests.
 *
 * @SearchApiProcessor(
 *   id = "user_interests",
 *   label = @Translation("User Interests"),
 *   description = @Translation("Index selected user interests."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserInterests extends ProcessorPluginBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

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

    $processor->database = $container->get('database');
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
        'label' => $this->t('User Interest'),
        'description' => $this->t('The user interest.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_interest'] = new ProcessorProperty($definition);
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
    $user = $item->getOriginalObject()->getValue();
    $query = $this->database->select('flagging', 'fl');
    $query->condition('fl.uid', $user->id());
    $query->condition('fl.flag_id', 'interest');
    $query->fields('fl', ['entity_id']);
    $flagged = $query->execute()->fetchCol();

    if (empty($flagged)) {
      return;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_interest');
    foreach ($fields as $field) {
      foreach ($flagged as $flagged_id) {
        $term = $term_storage->load($flagged_id);
        if (!$term) {
          continue;
        }
        $field->addValue($term->get('name')->value);
      }
    }
  }

}
