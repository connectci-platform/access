<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores Redirect's 301s from non-canonical aliases to the canonical one.
 *
 * Core 11.3 removed the alias preload cache (see
 * https://www.drupal.org/node/3532412). Since then, the inbound lookup
 * AliasManager::getPathByAlias() run by routing stores the requested alias in
 * the same lookup map that the outbound AliasManager::getAliasByPath() reads.
 * Redirect's route normalizer then generates a URL equal to the request URI,
 * and a request for an older alias of a node is served with a 200 instead of
 * being redirected to its canonical alias.
 *
 * Clearing the map entry for the current system path after routing makes the
 * next outbound lookup read the canonical alias from storage again. This
 * subscriber never redirects or changes a response itself.
 */
class CanonicalAliasNormalizerSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\path_alias\AliasManagerInterface|null $aliasManager
   *   The alias manager, or NULL when path_alias is not installed.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   The current path stack.
   */
  public function __construct(
    protected ?AliasManagerInterface $aliasManager,
    protected CurrentPathStack $currentPath,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 31: after routing (32), which populates the lookup map from the
    // inbound alias, and before Redirect's route normalizer (30), which reads
    // it.
    return [KernelEvents::REQUEST => ['onRequest', 31]];
  }

  /**
   * Clears the alias lookup entry for the current system path.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->aliasManager === NULL) {
      return;
    }
    $path = $this->currentPath->getPath($event->getRequest());
    // Only paths reached through an alias need clearing. An aliased path's
    // root is in the prefix list, so cacheClear() skips its prefix list
    // rebuild; for unaliased paths getAliasByPath() returns early without a
    // query, and clearing would needlessly rebuild that list on every hit.
    if ($this->aliasManager->getAliasByPath($path) === $path) {
      return;
    }
    $this->aliasManager->cacheClear($path);
  }

}
