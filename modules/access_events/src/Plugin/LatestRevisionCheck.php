<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\content_moderation\Access\LatestRevisionCheck as CoreLatestRevisionCheck;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\Routing\Route;

/**
 * Access check for the entity moderation tab with other_authors support.
 */
class LatestRevisionCheck extends CoreLatestRevisionCheck {

  /**
   * Checks that there is a pending revision available.
   *
   * This checker assumes the presence of an '_entity_access' requirement key
   * in the same form as used by EntityAccessCheck.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   *
   * @see \Drupal\Core\Entity\EntityAccessCheck
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    // This tab should not show up unless there's a reason to show it.
    $entity = $this->loadEntity($route, $route_match);
    if ($this->moderationInfo->hasPendingRevision($entity)) {
      // Check the global permissions first.
      $access_result = AccessResult::allowedIfHasPermissions($account, [
        'view latest version',
        'view any unpublished content',
      ]);
      if (!$access_result->isAllowed()) {
        // Check entity owner access.
        $owner_access = AccessResult::allowedIfHasPermissions($account, [
          'view latest version',
          'view own unpublished content',
        ]);
        $owner_access = $owner_access->andIf((AccessResult::allowedIf($entity instanceof EntityOwnerInterface && ($entity->getOwnerId() == $account->id()))));
        $access_result = $access_result->orIf($owner_access);
      }

      // Check if user is referenced in the other_authors field.
      if (!$access_result->isAllowed()) {
        $other_authors = [];

        // For eventseries, check field_other_authors directly.
        if ($entity->getEntityTypeId() == 'eventseries' && $entity->hasField('field_other_authors')) {
          $other_authors = $entity->get('field_other_authors')->getValue();
        }
        // For eventinstance, get the parent eventseries and check
        // its field_other_authors.
        elseif ($entity->getEntityTypeId() == 'eventinstance' && method_exists($entity, 'getEventSeries')) {
          $eventseries = $entity->getEventSeries();
          if ($eventseries && $eventseries->hasField('field_other_authors')) {
            $other_authors = $eventseries->get('field_other_authors')->getValue();
          }
        }

        if (!empty($other_authors)) {
          $user_ids = array_column($other_authors, 'target_id');
          if (in_array($account->id(), $user_ids)) {
            // User is in the other_authors field, grant access if they
            // have the base permission.
            $additional_editor_access = AccessResult::allowedIfHasPermissions($account, ['view latest version']);
            $access_result = $access_result->orIf($additional_editor_access);
          }
        }
      }

      return $access_result->addCacheableDependency($entity);
    }

    return AccessResult::forbidden('No pending revision for moderated entity.')->addCacheableDependency($entity);
  }

}
