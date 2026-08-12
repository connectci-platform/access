<?php

namespace Drupal\access\EventSubscriber;

use Drupal\access\AccessJwtKeyProvider;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets a signed JWT cookie for authenticated users.
 *
 * The `SESSaccess_auth` cookie carries the user's ACCESS ID as a signed JWT
 * so the QA Bot agent can determine identity server-side without trusting
 * client-supplied headers.
 *
 * This cookie coexists with the `SESSaccesscisso` cookie managed by
 * drupal_seamless_cilogon — they serve different purposes:
 *   - SESSaccesscisso: SSO presence signal (no identity data)
 *   - SESSaccess_auth: Signed identity assertion (JWT with ACCESS ID)
 *
 * Both cookies use the same domain and 18-hour TTL, but their expiration
 * behavior differs intentionally:
 *   - SESSaccesscisso uses FIXED expiration: set once at login, expires at a
 *     fixed point in time regardless of activity.
 *   - SESSaccess_auth uses ROLLING expiration: refreshed on every response,
 *     so it only expires after 18 hours of *inactivity*. This is better UX
 *     for long working sessions — the identity cookie stays valid as long as
 *     the user is actively browsing.
 *
 * JWTs are signed with ES256 (ECDSA P-256). Each issuing site holds its own
 * private key; the agent validates using the public key published at the
 * site's JWKS endpoint. No shared secret is needed.
 *
 * @see \Drupal\drupal_seamless_cilogon\EventSubscriber\DrupalSeamlessCilogonEventSubscriber
 */
class AccessAuthCookieSubscriber implements EventSubscriberInterface {

  /**
   * Cookie name for the JWT identity cookie.
   */
  const COOKIE_NAME = 'SESSaccess_auth';

  /**
   * The JWT key provider service.
   *
   * @var \Drupal\access\AccessJwtKeyProvider
   */
  protected AccessJwtKeyProvider $keyProvider;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The ACCESS ID domain. Compared against lowercased values.
   */
  const ACCESS_ID_DOMAIN = '@access-ci.org';

  /**
   * Cached openid_connect_authmap existence (NULL = not yet checked).
   *
   * @var bool|null
   */
  protected ?bool $authmapTableExists = NULL;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\access\AccessJwtKeyProvider $key_provider
   *   The JWT key provider service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection, for the openid_connect authmap lookup.
   */
  public function __construct(
    AccessJwtKeyProvider $key_provider,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    LoggerInterface $logger,
    Connection $database,
  ) {
    $this->keyProvider = $key_provider;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->logger = $logger;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run on every response, after authentication is resolved.
    $events[KernelEvents::RESPONSE][] = ['onResponse', 0];
    return $events;
  }

  /**
   * Sets or clears the JWT cookie based on authentication state.
   */
  public function onResponse(ResponseEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    if ($this->currentUser->isAuthenticated()) {
      $this->setAuthCookie($event);
    }
    else {
      $this->clearAuthCookie($event);
    }
  }

  /**
   * Creates and sets the JWT cookie on the response.
   *
   * Called on every authenticated response, which gives the cookie a rolling
   * expiration — the 18-hour window resets with each page load. This differs
   * from SESSaccesscisso (set once at login with a fixed expiration) but is
   * intentional: a user actively working should not lose their identity cookie
   * mid-session.
   */
  protected function setAuthCookie(ResponseEvent $event) {
    $user_entity = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user_entity) {
      return;
    }

    // The JWT "sub" claim is the user's ACCESS ID (e.g. "jsmith@access-ci.org"),
    // the format MCP servers expect in the X-Acting-User header.
    $access_id = $this->deriveAccessId($user_entity);
    if ($access_id === NULL) {
      // No derivable ACCESS ID — no cookie. Log it: this account holds an
      // authenticated session yet has no ACCESS-ID authmap row, which should
      // not happen for a CILogon login. It does happen for import-created
      // placeholder-email accounts that can never be email-linked, and those
      // would otherwise lose the identity cookie silently.
      $this->logger->warning(
        'No ACCESS ID derivable for authenticated uid @uid; SESSaccess_auth cookie not set. The account has no @access-ci.org openid_connect authmap sub.',
        ['@uid' => (int) $user_entity->id()]
      );
      return;
    }

    $private_key = $this->keyProvider->getPrivateKey();
    if (empty($private_key)) {
      return;
    }

    // Use the same expiration as SESSaccesscisso (default: 18 hours).
    // This state value must be a relative string (e.g. '+18 hours') so that
    // strtotime() produces "now + 18h", keeping exp consistent with iat.
    // An absolute value would break the rolling-expiration semantics.
    $cookie_expiration_str = $this->state->get(
      'drupal_seamless_cilogon.seamless_cookie_expiration',
      '+18 hours'
    );
    $cookie_expiration = strtotime($cookie_expiration_str);

    $cookie_domain = $this->getCookieDomain();

    $now = time();
    $exp = $cookie_expiration ?: ($now + 64800); // fallback: 18 hours

    $payload = [
      'iss' => $this->keyProvider->getIssuer(),
      'sub' => $access_id,
      'iat' => $now,
      'exp' => $exp,
    ];

    // The kid (Key ID) lets the agent select the correct public key from
    // the JWKS endpoint. Derived from the public key so it rotates with it.
    $kid = $this->keyProvider->getKeyId($private_key);
    if ($kid === NULL) {
      return;
    }

    try {
      $token = JWT::encode($payload, $private_key, 'ES256', $kid);
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to encode JWT cookie: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $cookie = new Cookie(
      self::COOKIE_NAME,
      $token,
      $exp,
      '/',
      $cookie_domain,
      TRUE,   // secure
      TRUE,   // httpOnly
      FALSE,  // raw
      Cookie::SAMESITE_NONE
    );

    $event->getResponse()->headers->setCookie($cookie);
  }

  /**
   * Derives a user's ACCESS ID for the JWT `sub` claim.
   *
   * The Drupal username is NOT reliably the ACCESS ID. The site has two
   * user-creation paths with different naming conventions: the
   * allocations-import cron names users by bare ACCESS ID ("apasquale"), while
   * CILogon first-login provisioning generates a display-style username
   * ("andrew-pasquale-4"). Assuming the username is the id left every
   * login-provisioned user without an identity cookie.
   *
   * The id comes from the openid_connect authmap ONLY — the uid's `sub`, when
   * that sub is ACCESS-ID shaped. There is no username-based derivation of any
   * kind. No authmap row means no cookie.
   *
   * That is complete by construction rather than lossy: anyone holding an
   * authenticated session on this site logged in through CILogon, which writes
   * the authmap row, and connect_existing_users (TRUE here) links a legacy
   * account to that same row on its first login. So every real session has a
   * row. Deriving an id from the username instead would be actively unsafe —
   * openid_connect's generateUsername() produces @-free tokens like "toto" and
   * "Eric.Brown" that are not ACCESS IDs, and the site holds duplicate
   * accounts per human, so a username-derived cookie could assert an identity
   * the user does not own.
   *
   * Note on case: `openid_connect_authmap.sub` is utf8mb4_general_ci, so the
   * lookup in getAuthmapSub() is case-INSENSITIVE at the DB even though the
   * suffix check here is explicitly lowercased.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user account.
   *
   * @return string|null
   *   The ACCESS ID, or NULL if none can be derived.
   */
  protected function deriveAccessId($user): ?string {
    $sub = $this->getAuthmapSub((int) $user->id());
    if ($sub !== NULL && str_ends_with(strtolower($sub), self::ACCESS_ID_DOMAIN)) {
      return $sub;
    }
    return NULL;
  }

  /**
   * Returns an openid_connect authmap `sub` for a uid, if any.
   *
   * Reads the table directly: OpenIDConnectAuthmap::getConnectedAccounts()
   * keys its result by client name, which this subscriber has no way to know.
   * Some rows hold opaque CILogon subs
   * ("http://cilogon.org/serverA/users/NNN") rather than ACCESS IDs, so the
   * caller checks the form before trusting it.
   *
   * @param int $uid
   *   The user id.
   *
   * @return string|null
   *   The sub, or NULL if there is no usable authmap row.
   */
  protected function getAuthmapSub(int $uid): ?string {
    // The table belongs to the optional contrib openid_connect module.
    if (!$this->authmapTableExists()) {
      return NULL;
    }

    $subs = $this->database->select('openid_connect_authmap', 'a')
      ->fields('a', ['sub'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchCol();

    // Prefer an ACCESS-ID-shaped sub over an opaque one.
    foreach ($subs as $sub) {
      if (str_ends_with(strtolower((string) $sub), self::ACCESS_ID_DOMAIN)) {
        return $sub;
      }
    }
    return $subs ? (string) reset($subs) : NULL;
  }

  /**
   * Whether the optional contrib authmap table is present (memoized).
   *
   * This subscriber runs on EVERY response, so the schema probe must not be
   * re-issued per request.
   */
  protected function authmapTableExists(): bool {
    if ($this->authmapTableExists === NULL) {
      $this->authmapTableExists = $this->database->schema()
        ->tableExists('openid_connect_authmap');
    }
    return $this->authmapTableExists;
  }

  /**
   * Gets the cookie domain, preferring the ACCESS_JWT_COOKIE_DOMAIN env var.
   *
   * This allows DDEV and other local environments to override the domain
   * without modifying Drupal state (e.g. ACCESS_JWT_COOKIE_DOMAIN=.ddev.site).
   */
  protected function getCookieDomain(): string {
    $env_domain = getenv('ACCESS_JWT_COOKIE_DOMAIN');
    if (!empty($env_domain)) {
      return $env_domain;
    }

    return $this->state->get(
      'drupal_seamless_cilogon.seamless_cookie_domain',
      '.access-ci.org'
    );
  }

  /**
   * Clears the JWT cookie for anonymous users (e.g. after logout).
   */
  protected function clearAuthCookie(ResponseEvent $event) {
    // Only clear if the request actually carries the cookie (e.g. user just
    // logged out). Sending Set-Cookie on every anonymous response prevents
    // CDN/Varnish from caching the page.
    if (!$event->getRequest()->cookies->has(self::COOKIE_NAME)) {
      return;
    }

    $cookie_domain = $this->getCookieDomain();

    $event->getResponse()->headers->clearCookie(
      self::COOKIE_NAME,
      '/',
      $cookie_domain,
      TRUE,   // secure
      TRUE,   // httpOnly
      Cookie::SAMESITE_NONE
    );
  }

}
