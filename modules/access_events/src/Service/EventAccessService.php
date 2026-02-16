<?php

namespace Drupal\access_events\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Service for checking event access permissions.
 */
class EventAccessService {

  /**
   * Check if an account is an author of the event series.
   *
   * This includes the primary author and any users listed in field_other_authors.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $eventseries
   *   The event series entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return bool
   *   TRUE if the account is an author, FALSE otherwise.
   */
  public function isEventAuthor(EventSeries $eventseries, AccountInterface $account): bool {
    $author = $eventseries->getOwner();
    $other_authors = $eventseries->get('field_other_authors')->getValue();
    $other_authors[] = ['target_id' => $author->id()];
    $author_ids = array_column($other_authors, 'target_id');

    return in_array($account->id(), $author_ids);
  }

}
