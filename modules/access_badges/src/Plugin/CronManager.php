<?php

namespace Drupal\access_badges\Plugin;

use Drupal\user\Entity\User;

/**
 * Manages cron job callbacks for the access_badges module.
 */
class CronManager {

  /**
   * Checks pending badge rows for name/org matches against existing users.
   *
   * Called by ultimate_cron daily. Processes rows with status = 'pending',
   * queries users by first/last name, checks organization match, and
   * flags rows as 'review' with matched_uid. Sends digest email to
   * administrators if new matches are found.
   */
  public static function checkPendingMatches() {
    $database = \Drupal::database();
    $entity_type_manager = \Drupal::entityTypeManager();

    // Load all pending rows.
    $pending_rows = $database->select('access_badges_pending', 'p')
      ->fields('p')
      ->condition('p.status', 'pending')
      ->execute()
      ->fetchAll();

    if (empty($pending_rows)) {
      return;
    }

    $new_matches = [];

    foreach ($pending_rows as $row) {
      // Skip if no last name to match on.
      if (empty($row->last_name)) {
        continue;
      }

      // Query users by name.
      $query = $entity_type_manager->getStorage('user')->getQuery();
      $query->condition('field_user_last_name', $row->last_name);
      if (!empty($row->first_name)) {
        $query->condition('field_user_first_name', $row->first_name);
      }
      $query->condition('status', 1);
      $query->accessCheck(FALSE);
      $uids = $query->execute();

      if (empty($uids)) {
        continue;
      }

      // Use the first matching user (admin can review alternatives).
      $users = $entity_type_manager->getStorage('user')->loadMultiple($uids);
      $best_uid = NULL;
      $best_strength = 'Possible';

      foreach ($users as $user) {
        $strength = 'Possible';

        // Check organization match.
        if (!empty($row->organization) && $user->hasField('field_access_organization') && !$user->get('field_access_organization')->isEmpty()) {
          $org_entity = $user->get('field_access_organization')->entity;
          if ($org_entity && mb_strtolower($org_entity->label()) === mb_strtolower($row->organization)) {
            $strength = 'Recommended';
          }
        }

        // Prefer recommended match, otherwise first found.
        if ($best_uid === NULL || $strength === 'Recommended') {
          $best_uid = $user->id();
          $best_strength = $strength;
        }

        // Stop searching if we found a recommended match.
        if ($strength === 'Recommended') {
          break;
        }
      }

      if ($best_uid) {
        $database->update('access_badges_pending')
          ->fields([
            'status' => 'review',
            'matched_uid' => $best_uid,
          ])
          ->condition('id', $row->id)
          ->execute();

        $matched_user = User::load($best_uid);
        $new_matches[] = [
          'csv_name' => trim($row->first_name . ' ' . $row->last_name),
          'csv_email' => $row->email,
          'matched_name' => $matched_user ? $matched_user->getDisplayName() : 'UID ' . $best_uid,
          'strength' => $best_strength,
        ];
      }
    }

    // Send digest email if new matches found.
    if (!empty($new_matches)) {
      static::sendDigestEmail($new_matches);
    }

    \Drupal::logger('access_badges')->info('Pending match check complete. @count new matches found.', [
      '@count' => count($new_matches),
    ]);
  }

  /**
   * Sends a digest email to all administrators about new matches.
   *
   * @param array $new_matches
   *   Array of match data.
   */
  protected static function sendDigestEmail(array $new_matches) {
    // Get all active administrator users.
    $query = \Drupal::entityTypeManager()->getStorage('user')->getQuery();
    $query->condition('roles', 'administrator');
    $query->condition('status', 1);
    $query->accessCheck(FALSE);
    $admin_uids = $query->execute();

    if (empty($admin_uids)) {
      return;
    }

    $admins = User::loadMultiple($admin_uids);
    $mail_manager = \Drupal::service('plugin.manager.mail');

    foreach ($admins as $admin) {
      $mail_manager->mail(
        'access_badges',
        'pending_match_digest',
        $admin->getEmail(),
        $admin->getPreferredLangcode(),
        ['matches' => $new_matches],
        NULL,
        TRUE
      );
    }
  }

}
