<?php

namespace Drupal\access_badges\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Sorts user badges to match badges taxonomy term order.
 */
class BadgeSorter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Sort field_user_badges on a user to match taxonomy weight/name order.
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
   * Sort badges for all users via direct DB writes, bypassing entity save.
   */
  public function sortAllUsers(): int {
    $tids = $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', 'badges')
      ->accessCheck(FALSE)
      ->sort('weight')
      ->sort('name')
      ->execute();
    $order = array_flip(array_values($tids));

    // Load all badge rows grouped by user.
    $rows = $this->database->select('user__field_user_badges', 'b')
      ->fields('b', ['entity_id', 'revision_id', 'langcode', 'delta', 'field_user_badges_target_id'])
      ->condition('deleted', 0)
      ->orderBy('entity_id')
      ->orderBy('delta')
      ->execute()
      ->fetchAll();

    $by_user = [];
    foreach ($rows as $row) {
      $by_user[$row->entity_id][] = $row;
    }

    $count = 0;
    foreach ($by_user as $uid => $user_rows) {
      if (count($user_rows) < 2) {
        continue;
      }

      usort($user_rows, function ($a, $b) use ($order) {
        $pos_a = $order[$a->field_user_badges_target_id] ?? PHP_INT_MAX;
        $pos_b = $order[$b->field_user_badges_target_id] ?? PHP_INT_MAX;
        return $pos_a - $pos_b;
      });

      // Check if already sorted to avoid unnecessary writes.
      $already_sorted = TRUE;
      foreach ($user_rows as $delta => $row) {
        if ((int) $row->delta !== $delta) {
          $already_sorted = FALSE;
          break;
        }
      }
      if ($already_sorted) {
        continue;
      }

      // Delete and re-insert to avoid unique key conflicts on delta.
      $this->database->delete('user__field_user_badges')
        ->condition('entity_id', $uid)
        ->condition('deleted', 0)
        ->execute();

      $insert = $this->database->insert('user__field_user_badges')
        ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta', 'field_user_badges_target_id']);

      foreach ($user_rows as $delta => $row) {
        $insert->values([
          'bundle' => 'user',
          'deleted' => 0,
          'entity_id' => $row->entity_id,
          'revision_id' => $row->revision_id,
          'langcode' => $row->langcode,
          'delta' => $delta,
          'field_user_badges_target_id' => $row->field_user_badges_target_id,
        ]);
      }
      $insert->execute();
      $count++;
    }
    return $count;
  }

}
