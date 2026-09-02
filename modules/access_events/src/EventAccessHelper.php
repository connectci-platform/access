<?php

namespace Drupal\access_events;

use Drupal\access_affinitygroup\Access\CoordinatorAccess;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Authorization helper for event write operations.
 *
 * $series->access($op) is authoritative and covers administrator, news_pm
 * (holds the eventseries edit/delete permissions — a legitimate events
 * editor, not denied), the author, and field_other_authors users, with no
 * hardcoded role list. The one case Drupal's own permissions don't model is
 * an affinity-group coordinator managing a group event they did not author
 * and lack a blanket edit permission for — that grant is added on top, via
 * the shared coordinator-access service, guarded on the series actually
 * having affinity groups (an AG-less series relies on entity access alone;
 * the coordinator loop returns vacuous TRUE on an empty group list, which
 * would otherwise grant every authenticated user).
 *
 * Reads \Drupal::currentUser() internally rather than taking a $user
 * parameter, because access_events_entity_access() keys its author /
 * field_other_authors grants off the current user — this is only correct
 * when called under the acting-user switch, which the routes guarantee.
 */
class EventAccessHelper {

  public function __construct(
    protected CoordinatorAccess $coordinatorAccess,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Whether the current user may perform $op on $series.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The event series.
   * @param string $op
   *   The entity operation ('update' or 'delete').
   *
   * @return bool
   *   TRUE if Drupal entity access allows the operation, or the series carries
   *   affinity groups the current user coordinates.
   */
  public function userMayManageSeries(EventSeries $series, string $op): bool {
    if ($series->access($op)) {
      return TRUE;
    }

    $groups = $series->hasField('field_affinity_group_node')
      ? $series->get('field_affinity_group_node')->referencedEntities()
      : [];

    // Guarded: an AG-less series must not fall through to the coordinator
    // loop, which returns vacuous TRUE on an empty array.
    return !empty($groups)
      && $this->coordinatorAccess->userCoordinatesAllGroups($this->currentUser, $groups);
  }

}
