<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;

/**
 * Index selected user flagged affinity groups.
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
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
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
   */
  public function addFieldValues(ItemInterface $item) {
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

    foreach ($fields as $field) {
      foreach ($badge_refs as $badge) {
        $term = Term::load($badge['target_id']);
        if (!$term || $term->get('field_badge')->isEmpty()) {
          continue;
        }

        $title = $term->getName();

        $badge_image = $term->get('field_badge')->getValue();
        $badge_image_alt = $badge_image[0]['alt'];
        $file = File::load($badge_image[0]['target_id']);
        if (!$file) {
          continue;
        }
        $path = $file->getFileUri();
        $badge_img = \Drupal::service('file_url_generator')->generateString($path);

        $field->addValue("$title:$badge_img:$badge_image_alt");
      }
    }
  }

}
