<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Index selected user badges.
 *
 * @SearchApiProcessor(
 *   id = "user_badges",
 *   label = @Translation("User Badges"),
 *   description = @Translation("Index selected user badges."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserBadges extends ProcessorPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

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
    $processor->fileUrlGenerator = $container->get('file_url_generator');

    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('User Badges'),
        'description' => $this->t('The badges of the user.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_badges'] = new ProcessorProperty($definition);
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

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_badges');

    // Collect badges from both regular and OOD badge fields.
    $badge_fields = ['field_user_badges', 'field_open_ondemand_badges'];
    $badge_refs = [];
    foreach ($badge_fields as $field_name) {
      if ($user->hasField($field_name)) {
        foreach ($user->get($field_name)->getValue() as $ref) {
          $badge_refs[] = $ref;
        }
      }
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $file_storage = $this->entityTypeManager->getStorage('file');

    foreach ($fields as $field) {
      foreach ($badge_refs as $badge) {
        $term = $term_storage->load($badge['target_id']);
        if (!$term || $term->get('field_badge')->isEmpty()) {
          continue;
        }

        $title = $term->getName();

        $badge_image = $term->get('field_badge')->getValue();
        $badge_image_alt = $badge_image[0]['alt'];
        $file = $file_storage->load($badge_image[0]['target_id']);
        if (!$file) {
          continue;
        }
        $badge_img = $this->fileUrlGenerator->generateString($file->getFileUri());

        $field->addValue("$title:$badge_img:$badge_image_alt");
      }
    }
  }

}
