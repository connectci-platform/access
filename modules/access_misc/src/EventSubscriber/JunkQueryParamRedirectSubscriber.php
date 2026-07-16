<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\Core\Routing\LocalRedirectResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects requests whose query string carries entity-mangled junk keys.
 *
 * Entity-naive crawlers read the HTML-escaped "&amp;" separators in facet
 * and pager links literally, producing query keys prefixed with "amp;"
 * (raw "amp%3B"). Facets then propagate those junk keys into every link
 * they generate, so each crawled page mints dozens of new unique URLs and
 * every generation adds another "amp;" layer: an infinite,
 * exponentially-branching space of uncacheable pages.
 *
 * Collapsing that space is the fix: any request with "amp;"-prefixed keys
 * gets a 301 to its canonical URL with the junk keys dropped. The redirect
 * is issued before routing, so junk requests never reach a controller.
 */
class JunkQueryParamRedirectSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 100: ahead of routing (32), so the redirect is issued
    // before any controller or view executes.
    return [KernelEvents::REQUEST => ['onRequest', 100]];
  }

  /**
   * Issues a canonicalizing redirect when junk query keys are present.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)) {
      return;
    }

    $queryString = $request->server->get('QUERY_STRING') ?? '';
    $sanitized = self::sanitizeQueryString($queryString);
    if ($sanitized === NULL) {
      return;
    }

    $url = $request->getPathInfo() . ($sanitized === '' ? '' : '?' . $sanitized);
    $event->setResponse(new LocalRedirectResponse($url, 301));
  }

  /**
   * Strips "amp;"-prefixed junk keys from a raw query string.
   *
   * @param string $queryString
   *   The raw query string from the request.
   *
   * @return string|null
   *   The canonical query string with junk pairs removed (may be ''), or
   *   NULL when the query string needs no sanitizing.
   */
  public static function sanitizeQueryString(string $queryString): ?string {
    if ($queryString === '') {
      return NULL;
    }
    // Cheap pre-screen before splitting: junk keys contain "amp;" either
    // raw or percent-encoded.
    if (stripos($queryString, 'amp%3b') === FALSE && stripos($queryString, 'amp;') === FALSE) {
      return NULL;
    }

    $kept = [];
    $dropped = FALSE;
    foreach (explode('&', $queryString) as $pair) {
      $key = rawurldecode(explode('=', $pair, 2)[0]);
      // One "amp;" layer per crawl generation can stack arbitrarily deep.
      if (preg_match('/^(amp;)+/i', $key)) {
        $dropped = TRUE;
        continue;
      }
      $kept[] = $pair;
    }

    if (!$dropped) {
      return NULL;
    }
    return implode('&', $kept);
  }

}
