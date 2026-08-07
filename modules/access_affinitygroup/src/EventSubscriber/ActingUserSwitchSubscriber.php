<?php

declare(strict_types=1);

namespace Drupal\access_affinitygroup\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Switches the request to the acting user so entity access enforces their own
 * permissions, then switches back on terminate.
 *
 * Keys off the `acting_user_uid` request attribute set by ActingUserAccess.
 * Exactly one switchBack site (onTerminate), guarded by a did-switch flag so a
 * request that never switched does not pop an empty AccountSwitcher stack
 * (which would throw). Both handlers are main-request only: kernel.request
 * fires for subrequests but kernel.terminate does not, so switching on a
 * subrequest would push with no matching pop.
 */
class ActingUserSwitchSubscriber implements EventSubscriberInterface {

  private bool $switched = FALSE;

  public function __construct(
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $uid = (int) $event->getRequest()->attributes->get('acting_user_uid', 0);
    if ($uid < 1) {
      return;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      return;
    }
    $this->accountSwitcher->switchTo($user);
    $this->switched = TRUE;
  }

  public function onTerminate(): void {
    if (!$this->switched) {
      return;
    }
    $this->switched = FALSE;
    $this->accountSwitcher->switchBack();
  }

  public static function getSubscribedEvents(): array {
    return [
      // Priority 20: after AccessAwareRouter's access pass (priority 32 on
      // kernel.request) has populated acting_user_uid, before the controller.
      KernelEvents::REQUEST => ['onRequest', 20],
      KernelEvents::TERMINATE => ['onTerminate'],
    ];
  }

}
