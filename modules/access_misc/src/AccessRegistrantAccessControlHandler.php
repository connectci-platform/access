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
        // Resolve the event series from the registrant entity itself, not
        // from the request path — this handler must also work on routes
        // that don't follow the /events/{id}/... URL shape (e.g. the JSON
        // API registrations endpoints).
        $eventseries = $entity->getEventSeries() ?? $entity->getEventInstance()?->getEventSeries();
        $author = $eventseries?->getOwner();

        // The event organizer may delete any registration for their event.
        if ($author && $author->id() == $account->id()) {
          return AccessResult::allowed();
        }
        if ($account->hasPermission('administer any registrant')) {
          return AccessResult::allowed();
        }
        if ($account->id() !== $entity->getOwnerId()) {
          return AccessResult::allowedIfHasPermission($account, 'delete registrant entities');
        }
        return AccessResult::allowedIfHasPermissions($account, [
          'delete registrant entities',
          'delete own registrant entities',
        ], 'OR');

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
