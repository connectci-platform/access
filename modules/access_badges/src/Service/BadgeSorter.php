<?php

namespace Drupal\access_badges\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Sorts user badges to match badges taxonomy term order.
 */
class BadgeSorter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Sort field_user_badges on a user entity to match taxonomy weight/name order.
   */
  public function sortUserBadges(UserInterface $user): void {
    $badges = $user->get('field_user_badges')->getValue();
    if (count($badges) < 2) {
      return;
    }

    $tids = $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', 'badges')
      ->accessCheck(FALSE)
      ->sort('weight')
      ->sort('name')
      ->execute();

    $order = array_flip(array_values($tids));
    $user_tids = array_column($badges, 'target_id');

    usort($user_tids, function ($a, $b) use ($order) {
      $pos_a = $order[$a] ?? PHP_INT_MAX;
      $pos_b = $order[$b] ?? PHP_INT_MAX;
      return $pos_a - $pos_b;
    });

    $user->set('field_user_badges', array_map(fn($tid) => ['target_id' => $tid], $user_tids));
  }

  /**
   * Sort badges for all users and return count of users saved.
   */
  public function sortAllUsers(): int {
    $uids = $this->entityTypeManager->getStorage('user')
      ->getQuery()
      ->condition('uid', 0, '>')
      ->accessCheck(FALSE)
      ->execute();

    $count = 0;
    foreach ($uids as $uid) {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      $badges = $user->get('field_user_badges')->getValue();
      if (count($badges) < 2) {
        continue;
      }
      $this->sortUserBadges($user);
      $user->save();
      $count++;
    }
    return $count;
  }

}
