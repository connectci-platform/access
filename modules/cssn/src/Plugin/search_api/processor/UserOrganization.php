<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\node\Entity\Node;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Index the user's ACCESS organization name as searchable text.
 *
 * @SearchApiProcessor(
 *   id = "user_organization",
 *   label = @Translation("User Organization"),
 *   description = @Translation("Index the user's ACCESS organization name."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class UserOrganization extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('User Organization'),
        'description' => $this->t('The name of the user\'s ACCESS organization.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_user_organization'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $user = $item->getOriginalObject()->getValue();

    if (!$user->hasField('field_access_organization') || $user->get('field_access_organization')->isEmpty()) {
      return;
    }

    $nid = $user->get('field_access_organization')->target_id;
    $node = Node::load($nid);

    if (!$node || $node->bundle() !== 'access_organization') {
      return;
    }

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'search_api_user_organization');

    foreach ($fields as $field) {
      $field->addValue($node->getTitle());
    }
  }

}
