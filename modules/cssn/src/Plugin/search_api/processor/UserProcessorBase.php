<?php

namespace Drupal\cssn\Plugin\search_api\processor;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\SearchApiException;
use Drupal\user\UserInterface;

/**
 * Base class for processors that only make sense for user items.
 *
 * Subclasses read data off the user entity (and often query the flagging
 * table by user ID), so they must never run against non-user items.
 */
abstract class UserProcessorBase extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() === 'user') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the user entity behind an item, if the item is a user item.
   *
   * @param \Drupal\search_api\Item\ItemInterface<\Drupal\search_api\Item\FieldInterface> $item
   *   The search item.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user, or NULL if the item does not wrap a user entity.
   */
  protected function getUserFromItem(ItemInterface $item): ?UserInterface {
    try {
      $entity_type_id = $item->getDatasource()->getEntityTypeId();
    }
    catch (SearchApiException) {
      return NULL;
    }
    if ($entity_type_id !== 'user') {
      return NULL;
    }

    $user = $item->getOriginalObject()?->getValue();
    return $user instanceof UserInterface ? $user : NULL;
  }

}
