<?php

namespace Drupal\access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Canonical ACCESS ID -> user resolution.
 *
 * This is the ONE place that turns an ACCESS ID into a Drupal user, shared by
 * the MCP acting-user gate and the JSON:API subscribers so every surface
 * resolves identity identically.
 *
 * An ACCESS ID arrives in either the full ("apasquale1@access-ci.org") or bare
 * ("apasquale1") form, and is resolved against the openid_connect authmap
 * `sub` — nothing else. Specifically NOT by username and NOT by email:
 *
 *  - The username is not a reliable identifier. The site names accounts
 *    inconsistently (bare ACCESS ID from the allocations import, display-style
 *    from CILogon provisioning) and holds many duplicate accounts for the same
 *    person, so username matching resolved callers to arbitrary accounts.
 *  - Email is mutable, sometimes a placeholder, and was never part of the
 *    signed assertion chain. The resolution space must equal the assertion
 *    space.
 *
 * Only ACTIVE users resolve.
 *
 * Note on case: `openid_connect_authmap.sub` is utf8mb4_general_ci, so the sub
 * comparison is case-INSENSITIVE at the DB even though the domain check here is
 * explicitly lowercased.
 */
class AccessIdResolver {

  /**
   * The ACCESS ID domain. Compared against lowercased values.
   */
  public const ACCESS_ID_DOMAIN = '@access-ci.org';

  /**
   * Cached openid_connect_authmap existence (NULL = not yet checked).
   *
   * Callers run per request (and the cookie subscriber per response), so the
   * schema probe is memoized rather than re-issued each time.
   *
   * @var bool|null
   */
  protected ?bool $authmapTableExists = NULL;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
  ) {}

  /**
   * Resolves an ACCESS ID to an active user.
   *
   * @param string|null $value
   *   The ACCESS ID, full or bare form.
   *
   * @return \Drupal\user\UserInterface|null
   *   The active user, or NULL if the value is not a resolvable ACCESS ID.
   */
  public function resolve(?string $value) {
    $full = $this->qualify($value);
    return $full === NULL ? NULL : $this->loadActiveUserByAuthmapSub($full);
  }

  /**
   * Normalizes a value to a full ACCESS ID, or NULL if it is not one.
   *
   * A value containing "@" must already be in the ACCESS ID domain; it is
   * never reduced to its local part, so "fred@gmail.com" is not ACCESS ID
   * "fred" and yields NULL without any lookup.
   *
   * @param string|null $value
   *   The candidate value.
   *
   * @return string|null
   *   The full-form ACCESS ID, or NULL.
   */
  public function qualify(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }

    if (str_contains($value, '@')) {
      return str_ends_with(strtolower($value), self::ACCESS_ID_DOMAIN) ? $value : NULL;
    }

    return $value . self::ACCESS_ID_DOMAIN;
  }

  /**
   * Loads the ACTIVE user associated with an openid_connect `sub`.
   *
   * Queries the authmap table directly rather than going through
   * OpenIDConnectAuthmap::userLoadBySub(), which requires a client name callers
   * have no way to know, and which returns blocked accounts. Matching on `sub`
   * alone is safe because a `sub` is globally unique across clients; the
   * exact-match comparison also means opaque CILogon subs
   * ("http://cilogon.org/serverA/users/NNN") can never collide with an
   * ACCESS ID.
   *
   * The contrib `identifier` index leads with client_name, so a sub-only
   * predicate cannot use it; access_affinitygroup_update_10014() adds a
   * dedicated index on `sub`.
   *
   * @param string $sub
   *   The full ACCESS ID, e.g. "apasquale1@access-ci.org".
   *
   * @return \Drupal\user\UserInterface|null
   *   The active user, or NULL.
   */
  protected function loadActiveUserByAuthmapSub(string $sub) {
    // The table belongs to the optional contrib openid_connect module.
    if (!$this->authmapTableExists()) {
      return NULL;
    }

    $uids = $this->database->select('openid_connect_authmap', 'a')
      ->fields('a', ['uid'])
      ->condition('sub', $sub)
      ->execute()
      ->fetchCol();
    if (!$uids) {
      return NULL;
    }

    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $user) {
      if ($user->isActive()) {
        return $user;
      }
    }
    return NULL;
  }

  /**
   * Whether the optional contrib authmap table is present (memoized).
   */
  protected function authmapTableExists(): bool {
    if ($this->authmapTableExists === NULL) {
      $this->authmapTableExists = $this->database->schema()
        ->tableExists('openid_connect_authmap');
    }
    return $this->authmapTableExists;
  }

}
