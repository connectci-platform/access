<?php

namespace Drupal\access_affinitygroup\Access;

use Drupal\Core\Session\AccountInterface;

/**
 * Shared coordinator-membership check for affinity-group-scoped writes.
 *
 * Extracted from AnnouncementApiController::userMayPostToGroups so the events
 * authz helper (and any future group-scoped write) can reuse the same
 * coordinator loop instead of re-implementing it.
 */
class CoordinatorAccess {

  /**
   * Whether $user is a coordinator of every group in $groupNodes.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to check.
   * @param \Drupal\node\NodeInterface[] $groupNodes
   *   Already-loaded affinity_group nodes (may contain NULL entries).
   *
   * @return bool
   *   TRUE only if the user is in every group's field_coordinator. A NULL
   *   group, a group missing field_coordinator, or a single non-coordinated
   *   group returns FALSE.
   */
  public function userCoordinatesAllGroups(AccountInterface $user, array $groupNodes): bool {
    foreach ($groupNodes as $group) {
      if (!$group || !$group->hasField('field_coordinator')) {
        return FALSE;
      }
      $isCoordinator = FALSE;
      foreach ($group->get('field_coordinator')->getValue() as $ref) {
        if ((int) $ref['target_id'] === (int) $user->id()) {
          $isCoordinator = TRUE;
          break;
        }
      }
      if (!$isCoordinator) {
        return FALSE;
      }
    }
    // Vacuous TRUE on an empty array — callers with a group-less subject MUST
    // guard this call on a non-empty $groupNodes themselves (see
    // EventAccessHelper::userMayManageSeries), or every authenticated user
    // would pass.
    return TRUE;
  }

}
