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
   * @var \Drupal\user\UserInterface
   */
  protected $currentUser;

  /**
   * User badges.
   *
   * @var array
   */
  protected $userBadges;

  /**
   * The vocabulary context for load/add/save operations.
   *
   * @var string
   */
  protected $currentVocabulary = 'badges';

  /**
   * Returns the user field name for a given vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return string
   *   The user entity field name.
   */
  private function getBadgeFieldName($vocabulary) {
    return $vocabulary === 'open_ondemand_badges'
      ? 'field_open_ondemand_badges'
      : 'field_user_badges';
  }

  /**
   * Returns the database table name for a given vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return string
   *   The database table name.
   */
  private function getBadgeTableName($vocabulary) {
    $field_name = $this->getBadgeFieldName($vocabulary);
    return 'user__' . $field_name;
  }

  /**
   * Returns the target_id column name for a given vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return string
   *   The target_id column name.
   */
  private function getBadgeTargetColumn($vocabulary) {
    $field_name = $this->getBadgeFieldName($vocabulary);
    return $field_name . '_target_id';
  }

  /**
   * Return badge term ID by name.
   */
  public function getBadgeTid($badge_name, $vocabulary = 'badges') {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', $vocabulary);
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
    $query = \Drupal::entityQuery('user');
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
    $query = \Drupal::entityQuery('user');
    $query->condition('field_region', $program);
    $query->accessCheck(FALSE);
    $users = $query->execute();
    return $users;
  }

  /**
   * Load the users badges.
   */
  public function loadUserBadges($user_id, $vocabulary = 'badges') {
    $this->currentUser = User::load($user_id);
    $this->currentVocabulary = $vocabulary;
    $field_name = $this->getBadgeFieldName($vocabulary);
    $this->userBadges = $this->currentUser->get($field_name)->getValue();
  }

  /**
   * Add badges to user.
   */
  public function addUserBadges($badge) {
    foreach ($this->userBadges as $existing) {
      if ($existing['target_id'] == $badge) {
        return;
      }
    }
    $this->userBadges[] = ['target_id' => $badge];
  }

  /**
   * Save user.
   */
  public function saveUserBadges($vocabulary = NULL) {
    $vocab = $vocabulary ?? $this->currentVocabulary;
    $field_name = $this->getBadgeFieldName($vocab);
    $this->currentUser->set($field_name, $this->userBadges);
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
    $query = \Drupal::entityQuery('user');
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
    $webform = \Drupal::entityTypeManager()->getStorage('webform')->load('join_the_cssn_network');
    $webform_submissions = \Drupal::entityTypeManager()->getStorage('webform_submission')->loadByProperties(['webform_id' => $webform->id()]);
    // Grab all submissions submited in the last 90 days.
    $submission_users = [];
    foreach ($webform_submissions as $submission) {
      $created = $submission->getCreatedTime();
      $now = \Drupal::time()->getCurrentTime();
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
  public function checkBadges($badge, $user, $vocabulary = 'badges') {
    $connection = \Drupal::database();
    $table = $this->getBadgeTableName($vocabulary);
    $target_column = $this->getBadgeTargetColumn($vocabulary);
    $query = $connection->select($table, 't');
    $query->fields('t', [$target_column]);
    $query->condition('t.entity_id', $user);
    $query->condition('t.' . $target_column, $badge);
    $result = $query->execute()->fetchField();

    return $result ? TRUE : FALSE;
  }

  /**
   * Set multiple users badge via the database.
   */
  public function setBadges($badge, $users, $vocabulary = 'badges') {
    $connection = \Drupal::database();
    $table = $this->getBadgeTableName($vocabulary);
    $target_column = $this->getBadgeTargetColumn($vocabulary);

    // Remove all badges to reset.
    $connection->delete($table)
      ->condition($target_column, $badge)
      ->execute();

    foreach ($users as $user) {
      // Need to look up delta for $user, if it exists increment by 1.
      $query = $connection->select($table, 't');
      $query->fields('t', ['delta']);
      $query->condition('t.entity_id', $user);
      $query->orderBy('delta', 'DESC');
      $delta = $query->execute()->fetchField();
      if ($delta >= 0) {
        $delta++;
      }
      if ($delta == NULL) {
        $delta = 0;
      }

      $connection->insert($table)
        ->fields([
          'bundle' => 'user',
          'deleted' => 0,
          'entity_id' => $user,
          'revision_id' => $user,
          'langcode' => 'en',
          'delta' => $delta,
          $target_column => $badge,
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
      $badge_load = $this->loadUserBadges($uid);
      // Check if user has badge.
      $badge_check = $this->checkBadges($badge, [$uid]);
      if (!$badge_check) {
        $badges = $this->getUserBadges();
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
    $query = \Drupal::database()->select('node__' . $field, 'fd');
    $query->fields('fd', [$field . '_target_id']);
    $query->condition('fd.bundle', $bundle);
    $field_users = $query->execute()->fetchAll();

    foreach ($field_users as $field_user) {
      $uid = $field_user->{$field . '_target_id'};
      $user = User::load($uid);
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

  /**
   * Assign a single badge to a user by UID.
   *
   * @param int $uid
   *   The user ID.
   * @param int $badge_tid
   *   The badge taxonomy term ID.
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return bool
   *   TRUE if assigned, FALSE if user already had the badge.
   */
  public function assignBadgeToUser($uid, $badge_tid, $vocabulary = 'badges') {
    if ($this->checkBadges($badge_tid, $uid, $vocabulary)) {
      return FALSE;
    }
    $this->loadUserBadges($uid, $vocabulary);
    $this->addUserBadges($badge_tid);
    $this->saveUserBadges($vocabulary);
    return TRUE;
  }

  /**
   * Remove a single badge from a user by UID.
   *
   * @param int $uid
   *   The user ID.
   * @param int $badge_tid
   *   The badge taxonomy term ID.
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return bool
   *   TRUE if removed, FALSE if user did not have the badge.
   */
  public function removeBadgeFromUser($uid, $badge_tid, $vocabulary = 'badges') {
    if (!$this->checkBadges($badge_tid, $uid, $vocabulary)) {
      return FALSE;
    }
    $this->loadUserBadges($uid, $vocabulary);
    $this->userBadges = array_values(array_filter(
      $this->userBadges,
      fn($b) => $b['target_id'] != $badge_tid
    ));
    $this->saveUserBadges($vocabulary);
    return TRUE;
  }

}
