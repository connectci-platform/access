<?php

namespace Drupal\access\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deduplicates Vary header values before the response is sent.
 *
 * Runs at priority -999, just before Drupal core's FinishResponseSubscriber
 * (-1000), to collapse repeated "Cookie" tokens that inflate cache key space
 * on Pantheon's Varnish layer.
 */
class VaryDeduplicateSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', -999],
    ];
  }

  /**
   * Deduplicates values in the Vary response header.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    $vary = $response->headers->all('vary');
    if (empty($vary)) {
      return;
    }
    // Vary can be a single comma-separated string or multiple header lines.
    $tokens = [];
    foreach ($vary as $line) {
      foreach (array_map('trim', explode(',', $line)) as $token) {
        if ($token !== '') {
          $tokens[] = $token;
        }
      }
    }
    $seen = [];
    $unique = [];
    foreach ($tokens as $token) {
      $key = strtolower($token);
      if (!isset($seen[$key])) {
        $seen[$key] = TRUE;
        $unique[] = $token;
      }
    }
    $response->headers->set('Vary', implode(', ', $unique));
  }

}
