<?php

namespace Drupal\access\EventSubscriber;

use Drupal\access\Controller\AgentTokenController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the token endpoints' CORS headers exactly as the endpoints set them.
 *
 * Two things elsewhere would otherwise change them:
 *
 * - Core's OptionsRequestSubscriber answers every OPTIONS request before
 *   routing (priority 1000), with an Allow header and no CORS headers, so an
 *   /api/agent-token preflight would never reach the controller. This
 *   answers it first.
 * - A site-wide CORS policy for /api/* (advanced_cors) rewrites
 *   Access-Control-* headers on every response. The agent-token endpoint
 *   echoes only registered origins, with credentials, and sends no CORS
 *   headers at all to anyone else; the token-exchange endpoint is
 *   server-to-server and sends none. So the endpoint's own headers are
 *   recorded before other response subscribers run and restored after.
 */
class TokenEndpointCorsSubscriber implements EventSubscriberInterface {

  const AGENT_TOKEN_PATH = '/api/agent-token';
  const TOKEN_EXCHANGE_PATH = '/api/token-exchange';

  /**
   * Request attribute holding the endpoint's own CORS headers.
   */
  const ATTRIBUTE = '_access_token_endpoint_cors';

  public function __construct(
    protected readonly AgentTokenController $controller,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['onRequest', 1001];
    $events[KernelEvents::RESPONSE][] = ['recordCors', 1000];
    $events[KernelEvents::RESPONSE][] = ['restoreCors', -1000];
    return $events;
  }

  /**
   * Answers OPTIONS on the agent-token path.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($event->isMainRequest() && $request->isMethod('OPTIONS') && $request->getPathInfo() === self::AGENT_TOKEN_PATH) {
      $event->setResponse($this->controller->preflight($request));
    }
  }

  /**
   * Records the CORS headers the endpoint set.
   */
  public function recordCors(ResponseEvent $event): void {
    if (!$this->applies($event)) {
      return;
    }
    $event->getRequest()->attributes->set(self::ATTRIBUTE, $this->corsHeaders($event->getResponse()->headers->all()));
  }

  /**
   * Puts back exactly the recorded CORS headers.
   */
  public function restoreCors(ResponseEvent $event): void {
    if (!$this->applies($event)) {
      return;
    }
    $headers = $event->getResponse()->headers;
    foreach (array_keys($this->corsHeaders($headers->all())) as $name) {
      $headers->remove($name);
    }
    foreach ($event->getRequest()->attributes->get(self::ATTRIBUTE, []) as $name => $values) {
      $headers->set($name, $values);
    }
  }

  /**
   * Whether a response is from one of the token endpoints.
   */
  protected function applies(ResponseEvent $event): bool {
    return $event->isMainRequest()
      && in_array($event->getRequest()->getPathInfo(), [self::AGENT_TOKEN_PATH, self::TOKEN_EXCHANGE_PATH], TRUE);
  }

  /**
   * The Access-Control-* entries of a header bag's all() array.
   *
   * @param array<string, list<string|null>> $all
   *   Headers as HeaderBag::all() returns them.
   *
   * @return array<string, list<string|null>>
   *   The Access-Control-* entries.
   */
  protected function corsHeaders(array $all): array {
    return array_filter($all, fn($name) => str_starts_with($name, 'access-control-'), ARRAY_FILTER_USE_KEY);
  }

}
