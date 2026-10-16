<?php

namespace Drupal\access_misc\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Grants program-scoped delete access to project submissions.
 *
 * Project submissions are webform submissions of the "project" webform. The
 * webform's own delete_any rule only allows administrators, so this service
 * adds delete access for the roles listed in ROLE_PROGRAMS, limited to
 * projects whose Program (region element) is one of the role's programs.
 *
 * To let another role delete another program's projects, add one line to
 * ROLE_PROGRAMS.
 */
class ProjectSubmissionDeleteAccess {

  /**
   * The webform that holds project submissions.
   */
  const WEBFORM_ID = 'project';

  /**
   * Map of role id => list of region term ids whose projects it may delete.
   */
  const ROLE_PROGRAMS = [
    // PA Science manager: PA Science (933).
    'pascience_manager' => [933],
  ];

  /**
   * Checks delete access to a project submission.
   *
   * Only ever grants access (allowed or neutral, never forbidden) so the
   * webform's own access rules for administrators remain in effect.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(WebformSubmissionInterface $submission, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::neutral()
      ->addCacheContexts(['user.roles'])
      ->addCacheableDependency($submission);

    if ($submission->getWebform()->id() !== self::WEBFORM_ID) {
      return $result;
    }

    $allowed_programs = $this->getAllowedPrograms($account);
    if (!$allowed_programs) {
      return $result;
    }

    $regions = $this->getSubmissionRegions($submission);
    // Every region must be allowed, so a project shared with another program
    // cannot be deleted. A missing region is never allowed.
    if ($regions && !array_diff($regions, $allowed_programs)) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.roles'])
        ->addCacheableDependency($submission);
    }
    return $result;
  }

  /**
   * Gets the region term ids (as strings) the account may delete projects of.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string[]
   *   Region term ids.
   */
  protected function getAllowedPrograms(AccountInterface $account): array {
    $programs = [];
    foreach (array_intersect_key(self::ROLE_PROGRAMS, array_flip($account->getRoles())) as $role_programs) {
      $programs = array_merge($programs, $role_programs);
    }
    return array_values(array_unique(array_map('strval', $programs)));
  }

  /**
   * Gets the submission's region term ids as strings.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   *
   * @return string[]
   *   Region term ids, empty when none.
   */
  protected function getSubmissionRegions(WebformSubmissionInterface $submission): array {
    $value = $submission->getElementData('region');
    $value = is_array($value) ? $value : [$value];
    $regions = [];
    foreach ($value as $id) {
      if (is_scalar($id) && (string) $id !== '' && (string) $id !== '0') {
        $regions[] = (string) $id;
      }
    }
    return array_values(array_unique($regions));
  }

}
