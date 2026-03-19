<?php

namespace Drupal\access\EventSubscriber;

use Drupal\access\AccessJwtKeyProvider;
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
   */
  public function __construct(
    AccessJwtKeyProvider $key_provider,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    LoggerInterface $logger,
  ) {
    $this->keyProvider = $key_provider;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->logger = $logger;
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
    // Get the full account name (e.g. "jsmith@access-ci.org").
    // This is used as the JWT "sub" claim and matches the format
    // expected by MCP servers in the X-Acting-User header.
    $user_entity = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user_entity) {
      return;
    }

    $account_name = $user_entity->getAccountName();
    if (empty($account_name) || !str_ends_with($account_name, '@access-ci.org')) {
      // No valid ACCESS ID — don't set a cookie.
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
      'sub' => $account_name,
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
