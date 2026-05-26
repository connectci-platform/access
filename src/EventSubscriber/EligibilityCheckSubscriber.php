<?php

namespace Drupal\access\EventSubscriber;

use Drupal\access\EligibilityState;
use Drupal\access_affinitygroup\Service\AllocationsClient;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Populates EligibilityState on each authenticated main request.
 *
 * Caches eligible results in $_SESSION for the session (eligible→ineligible
 * transitions are rare and would be picked up on next login). Does not
 * cache ineligible results so the banner disappears immediately when the
 * user completes their profile.
 */
class EligibilityCheckSubscriber implements EventSubscriberInterface {

  const SESSION_KEY = 'access_eligibility';
  const ACCESS_SUFFIX = '@access-ci.org';

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AllocationsClient $allocations,
    protected EligibilityState $state,
  ) {}

  public static function getSubscribedEvents(): array {
    // Priority 50 runs after Drupal core's AuthenticationSubscriber
    // (priorities 300 and 31, see core's authentication.services.yml) and
    // before route matching. By this point the current user is fully
    // resolved, and the populated state is available to controllers and
    // blocks that render later in the request.
    return [
      KernelEvents::REQUEST => [['onRequest', 50]],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user) {
      return;
    }
    $accountName = $user->getAccountName();
    if (!str_ends_with($accountName, self::ACCESS_SUFFIX)) {
      return;
    }

    $request = $event->getRequest();
    $session = $request->hasSession() ? $request->getSession() : NULL;
    if ($session && $session->get(self::SESSION_KEY) === 'eligible') {
      $this->state->setEligible();
      return;
    }

    $username = substr($accountName, 0, -strlen(self::ACCESS_SUFFIX));
    $result = $this->allocations->getEligibilityForUser($username);
    if ($result === NULL) {
      // Transient failure; leave state unknown so the banner is not shown.
      return;
    }

    if ($result['eligible']) {
      $this->state->setEligible();
      if ($session) {
        $session->set(self::SESSION_KEY, 'eligible');
      }
    }
    else {
      $this->state->setIneligible($result['reason'] ?? 'Your ACCESS profile is incomplete.');
      // Intentionally do NOT cache — re-check next request so the banner
      // disappears as soon as the user completes their profile.
    }
  }

}
