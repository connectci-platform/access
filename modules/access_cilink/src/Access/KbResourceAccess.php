<?php

namespace Drupal\access_cilink\Access;

use Drupal\access_cilink\KbResourceRepository;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Route access for the KB resource detail page.
 *
 * Allowed when the resource is public (approved and not private), when the
 * user owns it or is an administrator or kb_pm, or when the resource is
 * allowed on an affinity group the user can view.
 */
class KbResourceAccess {

  public function __construct(
    protected KbResourceRepository $repository,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks access to a KB resource detail page.
   *
   * @param string|int $sid
   *   The webform submission ID from the route.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   */
  public function access($sid, AccountInterface $account): AccessResultInterface {
    $submission = ctype_digit((string) $sid)
      ? $this->entityTypeManager->getStorage('webform_submission')->load($sid)
      : NULL;
    if (!$submission instanceof WebformSubmissionInterface) {
      // Missing submission: the controller shows "No KB Resource found.".
      return AccessResult::allowed()->addCacheTags(['webform_submission_list']);
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['webform_submission:' . $submission->id()]);

    // Public does not depend on the user; skipping the "user" context keeps
    // the page cacheable in the dynamic page cache.
    if ($this->repository->isPublic($submission)) {
      return AccessResult::allowed()->addCacheableDependency($cacheability);
    }

    $cacheability->addCacheContexts(['user', 'user.roles']);

    $roles = $account->getRoles();
    if (
      ($account->isAuthenticated() && (int) $account->id() === (int) $submission->getOwnerId())
      || in_array('administrator', $roles, TRUE)
      || in_array('kb_pm', $roles, TRUE)
    ) {
      return AccessResult::allowed()->addCacheableDependency($cacheability);
    }

    // Affinity group exception: overrides both approved and private.
    $cacheability->addCacheTags(['node_list:affinity_group']);
    if ((int) ($submission->getData()['resource_allowed_on_affinity_group'] ?? 0) === 1) {
      $storage = $this->entityTypeManager->getStorage('node');
      // accessCheck(FALSE): private affinity group access lives in
      // hook_entity_access, which entity queries do not apply.
      $nids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'affinity_group')
        ->condition('field_resources_entity_reference', $submission->id())
        ->execute();
      foreach ($storage->loadMultiple($nids) as $node) {
        $result = $node->access('view', $account, TRUE);
        $cacheability->addCacheableDependency($result);
        if ($result->isAllowed()) {
          return AccessResult::allowed()->addCacheableDependency($cacheability);
        }
      }
    }

    return AccessResult::forbidden('This KB resource is not public.')
      ->addCacheableDependency($cacheability);
  }

}
