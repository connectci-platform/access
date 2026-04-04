<?php

namespace Drupal\access_misc\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders an absolute URL for the entity, respecting domain_access.
 *
 * @ingroup views_field_handlers
 */
#[ViewsField('domain_aware_url')]
class DomainAwareUrl extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query needed — computed at render time.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity
      ?? (!empty($values->_object) ? $values->_object->getValue() : NULL);
    if (!$entity) {
      return '';
    }

    if (!$entity->hasLinkTemplate('canonical')) {
      return '';
    }

    $relative_path = $entity->toUrl('canonical')->toString();
    $sitetools = \Drupal::service('access_misc.sitetools');

    return $sitetools->buildDomainAwareUrl($relative_path, $entity);
  }

}
