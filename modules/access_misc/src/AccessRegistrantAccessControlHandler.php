<?php

namespace Drupal\access_misc;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\recurring_events_registration\RegistrantAccessControlHandler;

/**
 * Access controller for the Registrant entity.
 *
 * @see \Drupal\recurring_events_registration\Entity\Registrant.
 */
class AccessRegistrantAccessControlHandler extends RegistrantAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    // Get current uri.
    $current_uri = \Drupal::service('path.current')->getPath();
    $url_bits = explode('/', $current_uri);
    $event_id = is_numeric($url_bits[2]) ? $url_bits[2] : 0;

    $eventinstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($event_id);

    if (!$eventinstance) {
      return AccessResult::neutral();
    }

    $eventseries = $eventinstance->getEventSeries();
    // Get author of event series.
    $author = $eventseries->getOwner();

    /** @var \Drupal\recurring_events_registration\Entity\RegistrantInterface $entity */
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view registrant entities');

      case 'update':
        if ($account->id() !== $entity->getOwnerId()) {
          return AccessResult::allowedIfHasPermission($account, 'edit registrant entities');
        }
        return AccessResult::allowedIfHasPermissions($account, [
          'edit registrant entities',
          'edit own registrant entities',
        ], 'OR');

      case 'delete':
        // Allow access
      if ($author->id() == $account->id()) {
        return AccessResult::allowed();
      }
      if ($account->hasPermission('administer registrant entity')) {
        return AccessResult::allowed();
      }

      case 'resend':
        return AccessResult::allowedIfHasPermission($account, 'resend registrant emails');

      case 'anon-update':
      case 'anon-delete':
        return $this->checkAnonymousAccess($entity, $operation, $account);
    }

    // Unknown operation, no opinion.
    return AccessResult::neutral();
  }

}
