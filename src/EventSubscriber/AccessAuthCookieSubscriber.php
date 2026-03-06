<?php

namespace Drupal\access\EventSubscriber;

use Drupal\access\AccessJwtKeyProvider;
use Firebase\JWT\JWT;
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
   * Constructs the subscriber.
   *
   * @param \Drupal\access\AccessJwtKeyProvider $key_provider
   *   The JWT key provider service.
   */
  public function __construct(AccessJwtKeyProvider $key_provider) {
    $this->keyProvider = $key_provider;
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

    $user = \Drupal::currentUser();

    if ($user->isAuthenticated()) {
      $this->setAuthCookie($event, $user);
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
  protected function setAuthCookie(ResponseEvent $event, $user) {
    // Get the full account name (e.g. "jsmith@access-ci.org").
    // This is used as the JWT "sub" claim and matches the format
    // expected by MCP servers in the X-Acting-User header.
    $user_entity = \Drupal\user\Entity\User::load($user->id());
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
    $cookie_expiration_str = \Drupal::state()->get(
      'drupal_seamless_cilogon.seamless_cookie_expiration',
      '+18 hours'
    );
    $cookie_expiration = strtotime($cookie_expiration_str);

    // Use the same domain as SESSaccesscisso.
    $cookie_domain = \Drupal::state()->get(
      'drupal_seamless_cilogon.seamless_cookie_domain',
      '.access-ci.org'
    ) ?? '.access-ci.org';

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
      \Drupal::logger('access')->warning('Failed to encode JWT cookie: @message', [
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
   * Clears the JWT cookie for anonymous users (e.g. after logout).
   */
  protected function clearAuthCookie(ResponseEvent $event) {
    $cookie_domain = \Drupal::state()->get(
      'drupal_seamless_cilogon.seamless_cookie_domain',
      '.access-ci.org'
    ) ?? '.access-ci.org';

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
