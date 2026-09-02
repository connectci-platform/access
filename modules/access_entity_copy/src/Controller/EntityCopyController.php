<?php

namespace Drupal\access_entity_copy\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for copying mentorship_engagement nodes.
 */
class EntityCopyController extends ControllerBase {

  /**
   * Redirects to the node add form pre-filled with the source node's data.
   */
  public function copyMentorship(NodeInterface $node): RedirectResponse {
    $url = Url::fromRoute('node.add', ['node_type' => 'mentorship_engagement'], [
      'query' => ['copy' => $node->id()],
    ])->toString();

    return new RedirectResponse($url);
  }

  /**
   * Only expose the tab on mentorship_engagement nodes.
   */
  public function accessCheck(AccountInterface $account, NodeInterface $node): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'mentorship_engagement')
      ->addCacheableDependency($node);
  }

}
