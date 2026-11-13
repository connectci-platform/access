<?php

namespace Drupal\access_misc\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Grants view access to published and member projects.
 *
 * The "project" webform's own view_any rule is limited to staff roles. This
 * service adds view access (for single submissions and for entity queries and
 * views) for projects that are published (the "approved" element is set) and
 * for users listed in the project's "mentor" or "student" elements.
 *
 * Only ever grants access (allowed or neutral, never forbidden).
 */
class ProjectSubmissionViewAccess {

  /**
   * The webform that holds project submissions.
   */
  const WEBFORM_ID = 'project';

  /**
   * Elements that list the users who belong to a project.
   */
  const MEMBER_ELEMENTS = ['mentor', 'student'];

  /**
   * Constructs a ProjectSubmissionViewAccess object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(protected Connection $database) {}

  /**
   * Checks view access to a project submission.
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
    if ($submission->getWebform()->id() !== self::WEBFORM_ID) {
      return AccessResult::neutral();
    }

    if ((string) $submission->getElementData('approved') === '1') {
      return AccessResult::allowed()->addCacheableDependency($submission);
    }

    $result = AccessResult::neutral()
      ->addCacheContexts(['user'])
      ->addCacheableDependency($submission);
    if ($account->isAuthenticated()) {
      $uid = (string) $account->id();
      foreach (self::MEMBER_ELEMENTS as $element) {
        $value = $submission->getElementData($element);
        $value = is_array($value) ? $value : [$value];
        if (in_array($uid, array_map('strval', array_filter($value, 'is_scalar')), TRUE)) {
          return AccessResult::allowed()
            ->addCacheContexts(['user'])
            ->addCacheableDependency($submission);
        }
      }
    }
    return $result;
  }

  /**
   * Adds project view grants to a webform submission query.
   *
   * @param \Drupal\Core\Database\Query\AlterableInterface $query
   *   The query being altered.
   * @param array<int, array<string, mixed>> $tables
   *   The webform submission tables, each with an 'alias' and a 'condition'
   *   OR condition group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the query runs for.
   * @param string $op
   *   The operation.
   */
  public function alterQuery(AlterableInterface $query, array $tables, AccountInterface $account, string $op): void {
    if ($op !== 'view') {
      return;
    }
    if (!$query instanceof SelectInterface) {
      return;
    }
    foreach ($tables as $table) {
      $alias = $table['alias'];

      $published = $this->database->select('webform_submission_data', 'wsd_pub')
        ->fields('wsd_pub', ['sid'])
        ->condition('wsd_pub.webform_id', self::WEBFORM_ID)
        ->condition('wsd_pub.name', 'approved')
        ->condition('wsd_pub.value', '1');
      $group = $query->andConditionGroup()
        ->condition($alias . '.webform_id', self::WEBFORM_ID)
        ->condition($alias . '.sid', $published, 'IN');
      $table['condition']->condition($group);

      if ($account->isAuthenticated()) {
        $member = $this->database->select('webform_submission_data', 'wsd_member')
          ->fields('wsd_member', ['sid'])
          ->condition('wsd_member.webform_id', self::WEBFORM_ID)
          ->condition('wsd_member.name', self::MEMBER_ELEMENTS, 'IN')
          ->condition('wsd_member.value', (string) $account->id());
        $group = $query->andConditionGroup()
          ->condition($alias . '.webform_id', self::WEBFORM_ID)
          ->condition($alias . '.sid', $member, 'IN');
        $table['condition']->condition($group);
      }
    }
  }

}
