<?php

namespace Drupal\access_badges\Plugin;

use Drupal\user\Entity\User;

/**
 * Badges lookup.
 */
class BadgeTools {

  /**
   * Loaded User.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $currentUser;

  /**
   * User badges.
   *
   * @var array
   */
  protected $userBadges;

  /**
   * Return skill level image.
   */
  public function getBadgeTid($badge_name) {
    $query = \Drupal::entityQuery('taxonomy_term');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $query->condition('vid', 'badges');
    $query->condition('name', $badge_name);
    $query->accessCheck(FALSE);
    $tids = $query->execute();
    // There should just be one term.
    $tid = implode('', $tids);
    return $tid;
  }

  /**
   * Return Users that have access-ci in name.
   */
  public function getAccessUsers() {
    // User entity lookup that were created 90 days or less ago and has
    // access-ci.org in their name.
    $query = \Drupal::entityQuery('user');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $query->condition('created', strtotime('-90 days'), '>');
    $query->condition('name', '%access-ci.org%', 'LIKE');
    $query->accessCheck(FALSE);
    $users = $query->execute();
    return $users;
  }

  /**
   * Return Users that have a certain region/program.
   */
  public function getProgramUsers($program) {
    // User entity lookup that have a certain region/program.
    $query = \Drupal::entityQuery('user');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $query->condition('field_region', $program);
    $query->accessCheck(FALSE);
    $users = $query->execute();
    return $users;
  }

  /**
   * Load the users badges.
   */
  public function loadUserBadges($user_id) {
    // Lookup user field 'field_user_badges'.
    $this->currentUser = User::load($user_id);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $this->userBadges = $this->currentUser->get('field_user_badges')->getValue();
  }

  /**
   * Add badges to user.
   */
  public function addUserBadges($badge) {
    $this->userBadges[] = ['target_id' => $badge];
  }

  /**
   * Save user.
   */
  public function saveUserBadges() {
    $this->currentUser->set('field_user_badges', $this->userBadges);
    $this->currentUser->save();
  }

  /**
   * Return the users badges.
   */
  public function getUserBadges() {
    return $this->userBadges;
  }

  /**
   * Return Users that have the affinity group leader role.
   */
  public function getAgRoleUsers() {
    $query = \Drupal::entityQuery('user');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $query->condition('roles', 'affinity_group_leader');
    $query->accessCheck(FALSE);
    $users = $query->execute();
    return $users;
  }

  /**
   * Return Users that have submitted the CSSN webform in the last 90 days.
   */
  public function getNewCssnUsers() {
    // Lookup webform submissions for 'join_the_cssn_network'.
    $webform = \Drupal::entityTypeManager()->getStorage('webform')->load('join_the_cssn_network');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $webform_submissions = \Drupal::entityTypeManager()->getStorage('webform_submission')->loadByProperties(['webform_id' => $webform->id()]);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    // Grab all submissions submited in the last 90 days.
    $submission_users = [];
    foreach ($webform_submissions as $submission) {
      $created = $submission->getCreatedTime();
      $now = \Drupal::time()->getCurrentTime();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $diff = $now - $created;
      if ($diff < 7776000) {
        $submission_users[] = $submission->getOwnerId();
      }
    }
    return $submission_users;
  }

  /**
   * Check if user has badge, return boolean.
   */
  public function checkBadges($badge, $user) {
    $connection = \Drupal::database();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $query = $connection->select('user__field_user_badges', 'ufub');
    $query->fields('ufub', ['field_user_badges_target_id']);
    $query->condition('ufub.entity_id', $user);
    $query->condition('ufub.field_user_badges_target_id', $badge);
    $result = $query->execute()->fetchField();

    return $result ? TRUE : FALSE;
  }

  /**
   * Set multiple users badge via the database.
   */
  public function setBadges($badge, $users) {
    $connection = \Drupal::database();  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

    // Remove all badges to reset.
    $connection->delete('user__field_user_badges')
      ->condition('field_user_badges_target_id', $badge)
      ->execute();

    foreach ($users as $user) {
      // Need to look up delta for $user, if it exists increment by 1.
      $query = $connection->select('user__field_user_badges', 'ufub');
      $query->fields('ufub', ['delta']);
      $query->condition('ufub.entity_id', $user);
      $query->orderBy('delta', 'DESC');
      $delta = $query->execute()->fetchField();
      if ($delta >= 0) {
        $delta++;
      }
      if ($delta == NULL) {
        $delta = 0;
      }

      $connection->insert('user__field_user_badges')
        ->fields([
          'bundle' => 'user',
          'deleted' => 0,
          'entity_id' => $user,
          'revision_id' => $user,
          'langcode' => 'en',
          'delta' => $delta,
          'field_user_badges_target_id' => $badge,
        ])
        ->execute();
    }
  }

  /**
   * Set user badge via saving user.
   */
  public function setUserBadge($badge, $users) {
    foreach ($users as $user) {
      $uid = $user['target_id'];
      $this->loadUserBadges($uid);
      // Check if user has badge.
      $badge_check = $this->checkBadges($badge, [$uid]);
      if (!$badge_check) {
        // Set badges for user.
        $this->addUserBadges($badge);
        $this->saveUserBadges();
      }
    }
  }

  /**
   * Fields with user id's to badge.
   */
  public function fieldToBadge($field, $badge, $bundle) {
    $query = \Drupal::database()->select('node__' . $field, 'fd');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $query->fields('fd', [$field . '_target_id']);
    $query->condition('fd.bundle', $bundle);
    $field_users = $query->execute()->fetchAll();

    foreach ($field_users as $field_user) {
      $uid = $field_user->{$field . '_target_id'};
      $user = User::load($uid);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $badgetid_new = $this->getBadgeTid($badge);
      $badgetid = $user->get('field_user_badges')->getValue();
      $badge_check = $this->checkBadges($badgetid_new, $uid);
      // Give user the badge if they don't have one.
      if (!$badge_check) {
        $badgetid[] = ['target_id' => $badgetid_new];
        $user->set('field_user_badges', $badgetid);
        $user->save();
      }
    }
  }

}
