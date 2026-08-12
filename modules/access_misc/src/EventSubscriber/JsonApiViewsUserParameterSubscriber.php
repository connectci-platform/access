<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\access\AccessIdResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the X-Acting-User ACCESS ID to a user ID for JSON:API views.
 *
 * Resolution is ACCESS-ID-only, through the shared AccessIdResolver — the same
 * resolver the MCP acting-user gate uses, so both surfaces resolve identity
 * identically. This previously matched the header against an email address
 * first and then fell back to the username; both were non-ACCESS-ID channels
 * with no senders in our stack and are gone.
 */
class JsonApiViewsUserParameterSubscriber implements EventSubscriberInterface {

  /**
   * The canonical ACCESS ID resolver.
   *
   * @var \Drupal\access\AccessIdResolver
   */
  protected AccessIdResolver $accessIdResolver;

  /**
   * Constructs a JsonApiViewsUserParameterSubscriber object.
   *
   * @param \Drupal\access\AccessIdResolver $access_id_resolver
   *   The canonical ACCESS ID resolver.
   */
  public function __construct(AccessIdResolver $access_id_resolver) {
    $this->accessIdResolver = $access_id_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 99],
    ];
  }

  /**
   * Resolves X-Acting-User to user ID in JSON:API views requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only process JSON:API views requests.
    if (strpos($request->getPathInfo(), '/jsonapi/views/') !== 0) {
      return;
    }

    // Only process GET requests.
    if ($request->getMethod() !== 'GET') {
      return;
    }

    // Resolve the acting user's ACCESS ID.
    $acting_user = $this->accessIdResolver->resolve($request->headers->get('X-Acting-User'));
    if (!$acting_user) {
      return;
    }

    // Set views-argument[0] to override the default current_user argument.
    // We always set it even if already present, as the header takes precedence.
    $query = $request->query->all();
    $query['views-argument'][0] = (int) $acting_user->id();
    $request->query->replace($query);
  }

}
