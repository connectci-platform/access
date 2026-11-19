<?php

namespace Drupal\access_cilink\Access;

use Drupal\access_cilink\KbResourceRepository;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Route access for the KB resource detail page.
 *
 * Allowed when the resource is public (approved and not private), when the
 * user owns it or is an administrator or kb_pm, or through a private affinity
 * group the resource is TAGGED with (the group's field_affinity_group term is
 * one of the submission's affinity_group terms). A group that merely
 * references the resource in field_resources_entity_reference never grants
 * access: coordinators can reference any resource, so that would let one
 * private group open another group's private resource. A private group
 * (field_ag_private = 1) grants access to its members when the resource is
 * approved or allowed on affinity groups (the allowed flag only matters while
 * the resource is pending), and to its coordinators always.
 * Public and unpublished groups never grant access.
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

    // Affinity group exception: overrides both approved and private, but only
    // through a private group the resource is tagged with. A reference from
    // another group never grants access (see the class docblock).
    $cacheability->addCacheTags(['node_list:affinity_group']);
    $uid = $account->isAuthenticated() ? (int) $account->id() : 0;
    if ($uid > 0) {
      $data = $submission->getData();
      $allowed_on_group = (int) ($data['resource_allowed_on_affinity_group'] ?? 0) === 1;
      $approved = (int) ($data['approved'] ?? 0) === 1;
      foreach ($this->loadTaggedGroups($submission) as $node) {
        // Toggling field_ag_private or editing membership must invalidate.
        $cacheability->addCacheableDependency($node);
        if ((int) $node->get('field_ag_private')->value !== 1) {
          continue;
        }
        // The group page lists every approved resource and the form hides the
        // allowed checkbox once approved, so approval opens it to members. The
        // allowed flag only matters while the resource is pending.
        $is_member = ($approved || $allowed_on_group)
          && in_array($uid, $this->referencedUids($node, 'field_ag_private_users'), TRUE);
        // Coordinators approve the resource, so they see it while pending
        // regardless of the allowed checkbox.
        $is_coordinator = in_array($uid, $this->referencedUids($node, 'field_coordinator'), TRUE);
        if ($is_member || $is_coordinator) {
          return AccessResult::allowed()->addCacheableDependency($cacheability);
        }
      }
    }

    return AccessResult::forbidden('This KB resource is not public.')
      ->addCacheableDependency($cacheability);
  }

  /**
   * Loads the published affinity groups a resource is tagged with.
   *
   * A group is tagged when its field_affinity_group term is one of the
   * submission's affinity_group term IDs. Groups that only reference the
   * submission in field_resources_entity_reference are deliberately not
   * returned, and unpublished groups are skipped.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The distinct group nodes.
   */
  protected function loadTaggedGroups(WebformSubmissionInterface $submission): array {
    $tids = $submission->getData()['affinity_group'] ?? [];
    $tids = array_values(array_unique(array_filter(
      array_map('intval', (array) $tids)
    )));
    if (!$tids) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    // accessCheck(FALSE): private affinity group access lives in
    // hook_entity_access, which entity queries do not apply. Membership is
    // checked explicitly by the caller.
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'affinity_group')
      ->condition('status', 1)
      ->condition('field_affinity_group', $tids, 'IN')
      ->execute();

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $storage->loadMultiple($nids);
    return $nodes;
  }

  /**
   * Returns the user IDs in a node's user reference field, as integers.
   *
   * @return int[]
   *   The user IDs.
   */
  protected function referencedUids(NodeInterface $node, string $field): array {
    if (!$node->hasField($field)) {
      return [];
    }
    return array_map('intval', array_column($node->get($field)->getValue(), 'target_id'));
  }

}
