<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\access\AccessIdResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the acting user's ACCESS ID to a UUID in JSON:API requests.
 *
 * Fills in the `uid` relationship on POST/PATCH bodies from the X-Acting-User
 * header, so callers do not have to know the target's Drupal UUID.
 *
 * Resolution is ACCESS-ID-only, through the shared AccessIdResolver — the same
 * resolver the MCP acting-user gate uses, so both surfaces resolve identity
 * identically. The class name is historical: the email path it was named for
 * is gone.
 *
 * Removed deliberately, all non-ACCESS-ID channels with no senders in our
 * stack: the X-Acting-User-Email header, the username fallback on
 * X-Acting-User, and the body shorthands `relationships.uid.data.mail` and
 * `.name`. Emails are mutable and sometimes placeholders, usernames are not
 * reliable identifiers on this site, and neither was ever part of the signed
 * assertion chain.
 */
class JsonApiEmailToUuidSubscriber implements EventSubscriberInterface {

  /**
   * The canonical ACCESS ID resolver.
   *
   * @var \Drupal\access\AccessIdResolver
   */
  protected AccessIdResolver $accessIdResolver;

  /**
   * Constructs a JsonApiEmailToUuidSubscriber object.
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
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  /**
   * Resolves the acting user's ACCESS ID to a UUID on POST/PATCH requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only process JSON:API requests.
    if (strpos($request->getPathInfo(), '/jsonapi/') !== 0) {
      return;
    }

    // Only process POST and PATCH requests.
    if (!in_array($request->getMethod(), ['POST', 'PATCH'])) {
      return;
    }

    $content = $request->getContent();
    if (empty($content)) {
      return;
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data'])) {
      return;
    }

    $modified = FALSE;

    // Fill in the uid relationship from the acting user's ACCESS ID. Only when
    // the caller supplied no uid relationship at all — an explicit one (a real
    // UUID, or a `mail`/`name` shorthand that is no longer honored) is left
    // exactly as sent.
    if (!isset($data['data']['relationships']['uid'])) {
      $acting_user = $this->accessIdResolver->resolve($request->headers->get('X-Acting-User'));

      if ($acting_user) {
        $data['data']['relationships']['uid'] = [
          'data' => [
            'type' => 'user--user',
            'id' => $acting_user->uuid(),
          ],
        ];
        $modified = TRUE;
      }
    }

    // Update the request content if modified.
    if ($modified) {
      $request->initialize(
        $request->query->all(),
        $request->request->all(),
        $request->attributes->all(),
        $request->cookies->all(),
        $request->files->all(),
        $request->server->all(),
        json_encode($data)
      );
    }
  }

}
