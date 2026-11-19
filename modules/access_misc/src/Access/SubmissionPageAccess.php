<?php

namespace Drupal\access_misc\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Route access check for raw webform submission pages.
 *
 * Entity "view" access is granted to everyone for public resources and
 * projects (so views and entity reference formatters work), but the raw
 * submission pages show every field. This check is ANDed with the existing
 * entity access requirement on those routes and limits the pages of the
 * listed webforms to staff, the owner and (for projects) members.
 *
 * @see \Drupal\access_misc\Routing\RouteSubscriber
 */
class SubmissionPageAccess implements AccessInterface {

  /**
   * Webforms whose submission pages are restricted.
   */
  const WEBFORMS = ['resource', 'project'];

  /**
   * Constructs a SubmissionPageAccess object.
   *
   * @param \Drupal\access_misc\Access\ProjectSubmissionViewAccess $projectViewAccess
   *   The project view access service.
   */
  public function __construct(protected ProjectSubmissionViewAccess $projectViewAccess) {}

  /**
   * Checks access to a submission page.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(WebformSubmissionInterface $webform_submission, AccountInterface $account): AccessResultInterface {
    $webform = $webform_submission->getWebform();
    if (!in_array($webform->id(), self::WEBFORMS, TRUE)) {
      // Route requirements are ANDed, so do not interfere with others.
      return AccessResult::allowed();
    }

    $allowed = FALSE;
    if ($account->hasPermission('administer webform')
      || $account->hasPermission('administer webform submission')
      || $account->hasPermission('view any webform submission')) {
      $allowed = TRUE;
    }
    elseif ($webform->access('submission_view_any', $account)) {
      $allowed = TRUE;
    }
    elseif ($account->isAuthenticated() && (int) $account->id() === (int) $webform_submission->getOwnerId()) {
      $allowed = TRUE;
    }
    elseif ($webform->id() === ProjectSubmissionViewAccess::WEBFORM_ID && $this->projectViewAccess->isMember($webform_submission, $account)) {
      $allowed = TRUE;
    }

    $result = $allowed
      ? AccessResult::allowed()
      : AccessResult::forbidden('Raw submission pages are limited to staff, owners and project members.');
    return $result
      ->cachePerUser()
      ->addCacheContexts(['user.permissions'])
      ->addCacheableDependency($webform_submission)
      ->addCacheableDependency($webform);
  }

}
