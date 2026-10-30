<?php

namespace Drupal\access\Controller;

use Drupal\access\AccessIdResolver;
use Drupal\access\AccessJwtKeyProvider;
use Drupal\access\TokenExchange\ClientRegistry;
use Drupal\access\TokenExchange\TokenIssuer;
use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/agent-token: a short-lived agent token for the signed-in user.
 *
 * Called by browser script on a registered origin, with credentials, so the
 * request carries this site's Drupal session. The session identifies the
 * user, and the exact Origin selects the browser client and its scopes. The
 * response is a 15-minute at+jwt token addressed to that client's audience,
 * which the page sends to the agent as a Bearer token.
 *
 * Every failure answers JSON, never a redirect, and responses to a
 * registered origin carry CORS headers so the page can read the error.
 */
class AgentTokenController {

  /**
   * The per-user flood event: 120 tokens per hour.
   */
  const FLOOD_EVENT = 'access.agent_token.sub';
  const FLOOD_LIMIT = 120;
  const FLOOD_WINDOW = 3600;

  /**
   * The seamless module's SSO presence cookie.
   *
   * @see \Drupal\drupal_seamless_cilogon\EventSubscriber\DrupalSeamlessCilogonEventSubscriber::SEAMLESSCOOKIENAME
   */
  const SSO_COOKIE = 'SESSaccesscisso';

  public function __construct(
    protected readonly ClientRegistry $registry,
    protected readonly TokenIssuer $issuer,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly AccessIdResolver $accessIdResolver,
    protected readonly FloodInterface $flood,
    protected readonly LoggerInterface $logger,
    protected readonly StateInterface $state,
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Mints an agent token.
   */
  public function mint(Request $request): Response {
    // Origin is checked before anything else, so an unregistered page can
    // neither read a response nor use up a user's flood allowance.
    $origin = (string) $request->headers->get('Origin', '');
    $match = $origin === '' ? NULL : $this->registry->findBrowserOrigin($origin);
    if ($match === NULL) {
      return $this->error(NULL, 403, 'invalid_origin', 'The request origin is not registered.');
    }
    if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
      return $this->error($origin, 403, 'invalid_origin', 'Cross-site requests are not accepted.');
    }

    if ($request->isMethod('OPTIONS')) {
      return $this->preflight($request);
    }
    if (!$request->isMethod('POST')) {
      return $this->error($origin, 405, 'invalid_request', 'Use POST.');
    }

    $sub = $this->currentUser->isAuthenticated()
      ? $this->accessIdResolver->accessIdForUid((int) $this->currentUser->id())
      : NULL;
    if ($sub === NULL) {
      return $this->error($origin, 401, 'login_required', 'Sign in to continue.');
    }
    // The seamless module logs a user out once their SSO cookie is gone, but
    // it skips /api/* routes, so the same rule is applied here.
    if ($this->seamlessRequiresSsoCookie() && !$request->cookies->has(self::SSO_COOKIE)) {
      return $this->error($origin, 401, 'login_required', 'Sign in to continue.');
    }

    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $sub)) {
      $this->logger->notice('Agent token refused: flood limit for @sub.', ['@sub' => $sub]);
      return $this->error($origin, 429, 'slow_down', 'Too many requests. Try again later.', [
        'Retry-After' => (string) self::FLOOD_WINDOW,
      ]);
    }
    $token = $this->issuer->issue(
      AccessJwtKeyProvider::PURPOSE_AGENT,
      $request->getHost(),
      $sub,
      $match['audience'],
      $match['client_id'],
      $match['scopes'],
      ['endpoint' => 'agent-token', 'origin' => $origin]
    );
    if ($token === NULL) {
      return $this->error($origin, 500, 'server_error', 'Token signing is not available.');
    }
    // Counted only once issued, so server errors spend no allowance.
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $sub);

    return $this->withCors(new JsonResponse([
      'access_token' => $token,
      'token_type' => 'Bearer',
      'expires_in' => TokenIssuer::LIFETIME,
    ]), $origin);
  }

  /**
   * Answers a CORS preflight.
   *
   * The mint fetch sends no custom headers, so browsers normally skip the
   * preflight; this answers one anyway. Core's OptionsRequestSubscriber
   * answers OPTIONS before routing, so TokenEndpointCorsSubscriber calls
   * this ahead of it.
   */
  public function preflight(Request $request): Response {
    $origin = (string) $request->headers->get('Origin', '');
    if ($origin === '' || $this->registry->findBrowserOrigin($origin) === NULL) {
      return $this->error(NULL, 403, 'invalid_origin', 'The request origin is not registered.');
    }
    $response = $this->withCors(new Response('', 204), $origin);
    $response->headers->set('Access-Control-Allow-Methods', 'POST');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
    $response->headers->set('Access-Control-Max-Age', '600');
    return $response;
  }

  /**
   * Whether this request must carry the seamless SSO cookie.
   *
   * Mirrors the seamless module's own gate: the module and the CILogon client
   * are installed, seamless login is on, and (under the domain module) this
   * is the ACCESS Support domain.
   */
  protected function seamlessRequiresSsoCookie(): bool {
    if (!$this->moduleHandler->moduleExists('drupal_seamless_cilogon')
      || !$this->moduleHandler->moduleExists('openid_connect_cilogon_client')) {
      return FALSE;
    }
    if (!$this->state->get('drupal_seamless_cilogon.seamless_login_enabled', TRUE)) {
      return FALSE;
    }
    if (!$this->moduleHandler->moduleExists('domain')) {
      return TRUE;
    }
    $domain = \Drupal::service('domain.negotiator')->getActiveDomain();
    return $domain !== NULL && Html::getClass($domain->label()) === 'access-support';
  }

  /**
   * An error response, with CORS headers when the origin is registered.
   *
   * @param string|null $origin
   *   The registered origin to echo, or NULL to send no CORS headers.
   * @param int $status
   *   The HTTP status.
   * @param string $error
   *   The error code.
   * @param string $description
   *   The error description.
   * @param array<string, string> $headers
   *   Extra response headers.
   */
  protected function error(?string $origin, int $status, string $error, string $description, array $headers = []): Response {
    $response = new JsonResponse(['error' => $error, 'error_description' => $description], $status, $headers);
    return $origin === NULL ? $this->noStore($response) : $this->withCors($response, $origin);
  }

  /**
   * Adds the CORS headers for a registered origin.
   */
  protected function withCors(Response $response, string $origin): Response {
    $response->headers->set('Access-Control-Allow-Origin', $origin);
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->setVary('Origin', FALSE);
    return $this->noStore($response);
  }

  /**
   * Marks a response as never cacheable.
   */
  protected function noStore(Response $response): Response {
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
