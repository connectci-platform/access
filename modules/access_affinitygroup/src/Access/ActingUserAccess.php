<?php

namespace Drupal\access_affinitygroup\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Acting-user access check for MCP-service endpoints.
 *
 * A service account holding the `mcp_service` role may act on behalf of a user
 * by sending X-Acting-User (ACCESS ID) or X-Acting-User-Email. Resolves the
 * acting user and stashes their uid as the `acting_user_uid` request attribute.
 * With no acting header, falls back to the authenticated user's own uid.
 *
 * Generic (no rp-account logic). Mirrors RpAccountAccess but sets a
 * tool-agnostic attribute name so any MCP endpoint can reuse it.
 */
class ActingUserAccess {

  private const SERVICE_ROLE = 'mcp_service';

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
  ) {}

  public function check(AccountInterface $account, Request $request) {
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden('Anonymous access denied.')->setCacheMaxAge(0);
    }
    $hasActingHeader = $request->headers->has('X-Acting-User')
      || $request->headers->has('X-Acting-User-Email');
    if ($hasActingHeader) {
      if (!in_array(self::SERVICE_ROLE, $account->getRoles(), TRUE)) {
        return AccessResult::forbidden('X-Acting-User requires mcp_service role.')->setCacheMaxAge(0);
      }
      $effective = $this->resolveActingUser($request);
      if (!$effective) {
        return AccessResult::forbidden('X-Acting-User did not resolve to an active user.')->setCacheMaxAge(0);
      }
      $request->attributes->set('acting_user_uid', (int) $effective->id());
    }
    else {
      $request->attributes->set('acting_user_uid', (int) $account->id());
    }
    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  private function resolveActingUser(Request $request) {
    $store = $this->etm->getStorage('user');
    $email = $request->headers->get('X-Acting-User-Email');
    if ($email) {
      $users = $store->loadByProperties(['mail' => $email, 'status' => 1]);
      if ($u = reset($users)) {
        return $u;
      }
    }
    $name = $request->headers->get('X-Acting-User');
    if ($name) {
      $users = $store->loadByProperties(['name' => $name, 'status' => 1]);
      if ($u = reset($users)) {
        return $u;
      }
    }
    return NULL;
  }

}
