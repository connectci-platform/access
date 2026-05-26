<?php

namespace Drupal\access_affinitygroup\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the effective user for /api/1.0/rp-account/{rp_nid}.
 *
 * Allowed:
 *   - Authenticated session user (default).
 *   - Service account with role 'mcp_service' AND a valid X-Acting-User
 *     or X-Acting-User-Email header.
 *
 * Wired in routing as
 *   _custom_access: 'access_affinitygroup.rp_account_access:check'.
 */
class RpAccountAccess {

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
        return AccessResult::forbidden('X-Acting-User requires mcp_service role.')
          ->setCacheMaxAge(0);
      }
      $effective = $this->resolveActingUser($request);
      if (!$effective) {
        return AccessResult::forbidden('X-Acting-User did not resolve to an active user.')
          ->setCacheMaxAge(0);
      }
      $request->attributes->set('rp_account_effective_uid', (int) $effective->id());
    }
    else {
      $request->attributes->set('rp_account_effective_uid', (int) $account->id());
    }

    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  private function resolveActingUser(Request $request) {
    $store = $this->etm->getStorage('user');
    $email = $request->headers->get('X-Acting-User-Email');
    if ($email) {
      $users = $store->loadByProperties(['mail' => $email, 'status' => 1]);
      $u = reset($users);
      if ($u) {
        return $u;
      }
    }
    $name = $request->headers->get('X-Acting-User');
    if ($name) {
      $users = $store->loadByProperties(['name' => $name, 'status' => 1]);
      $u = reset($users);
      if ($u) {
        return $u;
      }
    }
    return NULL;
  }

}
