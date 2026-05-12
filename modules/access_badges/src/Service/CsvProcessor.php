<?php

namespace Drupal\access_badges\Service;

use Drupal\access_badges\Plugin\BadgeTools;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Processes CSV uploads for badge assignment.
 */
class CsvProcessor {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The badge tools service.
   *
   * @var \Drupal\access_badges\Plugin\BadgeTools
   */
  protected $badgeTools;

  /**
   * Constructs a CsvProcessor object.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, BadgeTools $badge_tools) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->badgeTools = $badge_tools;
  }

  /**
   * Parses and validates CSV headers.
   *
   * @param resource $handle
   *   An open file handle.
   *
   * @return array|false
   *   An associative map of recognized column => index, or FALSE on failure.
   */
  public function parseHeaders($handle) {
    $row = fgetcsv($handle);
    if (!$row) {
      return FALSE;
    }
    $map = [];
    foreach ($row as $index => $header) {
      $normalized = strtolower(trim($header));
      switch ($normalized) {
        case 'email':
          $map['email'] = $index;
          break;

        case 'first_name':
          $map['first_name'] = $index;
          break;

        case 'last_name':
          $map['last_name'] = $index;
          break;

        case 'name':
        case 'full_name':
          $map['full_name'] = $index;
          break;

        case 'organization':
          $map['organization'] = $index;
          break;
      }
    }
    if (!isset($map['email'])) {
      return FALSE;
    }
    return $map;
  }

  /**
   * Splits a full name into first and last name.
   *
   * "Mary Jane Watson" -> first: "Mary Jane", last: "Watson".
   * Single token "Prince" -> first: "", last: "Prince".
   */
  public function splitName($full_name) {
    $name = $this->normalizeName($full_name);
    if ($name === '') {
      return ['first_name' => '', 'last_name' => ''];
    }
    $last_space = strrpos($name, ' ');
    if ($last_space === FALSE) {
      return ['first_name' => '', 'last_name' => $name];
    }
    return [
      'first_name' => substr($name, 0, $last_space),
      'last_name' => substr($name, $last_space + 1),
    ];
  }

  /**
   * Normalizes a name string (trim + collapse whitespace).
   */
  public function normalizeName($name) {
    return preg_replace('/\s+/', ' ', trim($name));
  }

  /**
   * Extracts a row's data from a CSV line using the header map.
   *
   * @param array $row
   *   A CSV row array.
   * @param array $header_map
   *   The header map from parseHeaders().
   *
   * @return array
   *   Associative array with email, first_name, last_name, organization.
   */
  public function extractRowData(array $row, array $header_map) {
    $email = isset($header_map['email']) ? trim($row[$header_map['email']] ?? '') : '';
    $first_name = '';
    $last_name = '';
    $organization = '';

    // first_name / last_name columns take precedence over full_name.
    if (isset($header_map['first_name']) || isset($header_map['last_name'])) {
      $first_name = isset($header_map['first_name']) ? $this->normalizeName($row[$header_map['first_name']] ?? '') : '';
      $last_name = isset($header_map['last_name']) ? $this->normalizeName($row[$header_map['last_name']] ?? '') : '';
    }
    elseif (isset($header_map['full_name'])) {
      $parts = $this->splitName($row[$header_map['full_name']] ?? '');
      $first_name = $parts['first_name'];
      $last_name = $parts['last_name'];
    }

    if (isset($header_map['organization'])) {
      $organization = trim($row[$header_map['organization']] ?? '');
    }

    return [
      'email' => $email,
      'first_name' => $first_name,
      'last_name' => $last_name,
      'organization' => $organization,
    ];
  }

  /**
   * Processes a single CSV row: match, possible-match, or pending.
   *
   * @param array $data
   *   Row data from extractRowData().
   * @param int $badge_tid
   *   The badge term ID.
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return array
   *   Result with 'type' key: 'assigned', 'already_assigned', 'duplicate_pending',
   *   'possible_matches', or 'pending'.
   */
  public function processRow(array $data, $badge_tid, $vocabulary) {
    $email = $data['email'];
    if (empty($email)) {
      return ['type' => 'pending', 'email' => ''];
    }

    // Step 1: Email lookup.
    $user = $this->findUserByEmail($email);
    if ($user) {
      $uid = $user->id();
      $assigned = $this->badgeTools->assignBadgeToUser($uid, $badge_tid, $vocabulary);
      if ($assigned) {
        return [
          'type' => 'assigned',
          'uid' => $uid,
          'name' => $user->getDisplayName(),
          'email' => $email,
        ];
      }
      return [
        'type' => 'already_assigned',
        'uid' => $uid,
        'name' => $user->getDisplayName(),
        'email' => $email,
      ];
    }

    // Step 2: Check for duplicate pending row.
    if ($this->pendingRowExists($email, $badge_tid)) {
      return ['type' => 'duplicate_pending', 'email' => $email];
    }

    // Step 3: Name matching.
    $first_name = $data['first_name'];
    $last_name = $data['last_name'];
    if (!empty($last_name)) {
      $candidates = $this->findUsersByName($first_name, $last_name);
      if (!empty($candidates)) {
        $matches = [];
        foreach ($candidates as $candidate) {
          // Check if candidate already has the badge.
          if ($this->badgeTools->checkBadges($badge_tid, $candidate->id(), $vocabulary)) {
            continue;
          }
          $strength = 'Possible';
          if (!empty($data['organization'])) {
            $org_match = $this->checkOrganizationMatch($candidate, $data['organization']);
            if ($org_match) {
              $strength = 'Recommended';
            }
          }
          $matches[] = [
            'uid' => $candidate->id(),
            'name' => $candidate->getDisplayName(),
            'email' => $candidate->getEmail(),
            'organization' => $this->getUserOrganization($candidate),
            'strength' => $strength,
          ];
        }
        if (!empty($matches)) {
          // Persist the best candidate as a review row so it appears in the
          // Needs Review tab even after the user navigates away from the upload
          // form results. Prefer a Recommended match over a Possible one.
          $best = $matches[0];
          foreach ($matches as $candidate) {
            if ($candidate['strength'] === 'Recommended') {
              $best = $candidate;
              break;
            }
          }
          $this->insertReviewRow($data, $badge_tid, $vocabulary, $best['uid']);

          return [
            'type' => 'possible_matches',
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'organization' => $data['organization'],
            'candidates' => $matches,
          ];
        }
      }
    }

    // Step 4: No match — add to pending.
    $this->insertPendingRow($data, $badge_tid, $vocabulary);
    return ['type' => 'pending', 'email' => $email];
  }

  /**
   * Finds a user by email address (case-insensitive).
   *
   * @param string $email
   *   The email address.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity, or NULL.
   */
  public function findUserByEmail($email) {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
    return $users ? reset($users) : NULL;
  }

  /**
   * Finds users by first and last name.
   *
   * @param string $first_name
   *   First name.
   * @param string $last_name
   *   Last name.
   *
   * @return \Drupal\user\UserInterface[]
   *   Array of matching user entities.
   */
  public function findUsersByName($first_name, $last_name) {
    $query = $this->entityTypeManager->getStorage('user')->getQuery();
    $query->condition('field_user_last_name', $last_name);
    if (!empty($first_name)) {
      $query->condition('field_user_first_name', $first_name);
    }
    $query->condition('status', 1);
    $query->accessCheck(FALSE);
    $uids = $query->execute();
    if (empty($uids)) {
      return [];
    }
    return $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
  }

  /**
   * Checks if a user's organization matches the given organization name.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   * @param string $organization
   *   Organization name to match.
   *
   * @return bool
   *   TRUE if matching.
   */
  public function checkOrganizationMatch($user, $organization) {
    $user_org = $this->getUserOrganization($user);
    if (empty($user_org) || empty($organization)) {
      return FALSE;
    }
    return mb_strtolower($user_org) === mb_strtolower(trim($organization));
  }

  /**
   * Gets the organization title for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return string
   *   The organization title, or empty string.
   */
  public function getUserOrganization($user) {
    if (!$user->hasField('field_access_organization') || $user->get('field_access_organization')->isEmpty()) {
      return '';
    }
    $org_entity = $user->get('field_access_organization')->entity;
    return $org_entity ? $org_entity->label() : '';
  }

  /**
   * Checks if a pending row already exists for this email + badge.
   *
   * @param string $email
   *   The email address.
   * @param int $badge_tid
   *   The badge term ID.
   *
   * @return bool
   *   TRUE if exists.
   */
  public function pendingRowExists($email, $badge_tid) {
    $count = $this->database->select('access_badges_pending', 'p')
      ->condition('p.email', $email)
      ->condition('p.badge_tid', $badge_tid)
      ->countQuery()
      ->execute()
      ->fetchField();
    return $count > 0;
  }

  /**
   * Inserts a 'review' status row with a matched UID into the pending table.
   *
   * @param array $data
   *   Row data with email, first_name, last_name, organization.
   * @param int $badge_tid
   *   The badge term ID.
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param int $matched_uid
   *   The UID of the best-matched user candidate.
   */
  public function insertReviewRow(array $data, $badge_tid, $vocabulary, $matched_uid) {
    $this->database->insert('access_badges_pending')
      ->fields([
        'email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'organization' => $data['organization'],
        'badge_tid' => $badge_tid,
        'vocabulary' => $vocabulary,
        'created' => \Drupal::time()->getRequestTime(),
        'status' => 'review',
        'matched_uid' => $matched_uid,
      ])
      ->execute();
  }

  /**
   * Inserts a row into the pending table.
   *
   * @param array $data
   *   Row data with email, first_name, last_name, organization.
   * @param int $badge_tid
   *   The badge term ID.
   * @param string $vocabulary
   *   The vocabulary machine name.
   */
  public function insertPendingRow(array $data, $badge_tid, $vocabulary) {
    $this->database->insert('access_badges_pending')
      ->fields([
        'email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'organization' => $data['organization'],
        'badge_tid' => $badge_tid,
        'vocabulary' => $vocabulary,
        'created' => \Drupal::time()->getRequestTime(),
        'status' => 'pending',
      ])
      ->execute();
  }

}
