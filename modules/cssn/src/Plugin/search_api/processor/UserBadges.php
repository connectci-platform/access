<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\taxonomy\Entity\Term;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\file\Entity\File;

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
   */
  public function addFieldValues(ItemInterface $item) {
    $user = $item->getOriginalObject()->getValue();
    $badges = $user->get('field_user_badges')->getValue();

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_badges');

    foreach ($fields as $field) {
      foreach ($badges as $badge) {
        $term = Term::load($badge['target_id']);

        $title = $term->getName();

        $badge_image = $term->get('field_badge')->getValue();
        $badge_image_alt = $badge_image[0]['alt'];
        $file = File::load($badge_image[0]['target_id']);
        $path = $file->getFileUri();
        $badge_img = \Drupal::service('file_url_generator')->generateString($path);

        $field->addValue("$title:$badge_img:$badge_image_alt");
      }
    }
  }

}
