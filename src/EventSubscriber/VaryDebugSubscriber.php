<?php

namespace Drupal\access\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Debug-only: log Vary header state at many points across kernel.response.
 *
 * Intended for short-lived investigation only. Each method logs the current
 * Vary header(s) to syslog so we can trace where duplicate "Cookie" tokens
 * are introduced relative to VaryDeduplicateSubscriber (priority -999).
 *
 * Outputs go to watchdog (visible via `terminus drush <env> -- wd-show`).
 */
class VaryDebugSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
        ['atPlus1000', 1000],
        ['atPlus100', 100],
        ['atPlus1', 1],
        ['atMinus1', -1],
        ['atMinus500', -500],
        ['atMinus998', -998],
        ['atMinus1000', -1000],
        ['atMinus1500', -1500],
        ['atMinus2000', -2000],
        ['atMinus9999', -9999],
      ],
    ];
  }

  public function atPlus1000(ResponseEvent $event): void { $this->log($event, '+1000'); }
  public function atPlus100(ResponseEvent $event): void { $this->log($event, '+100'); }
  public function atPlus1(ResponseEvent $event): void { $this->log($event, '+1'); }
  public function atMinus1(ResponseEvent $event): void { $this->log($event, '-1'); }
  public function atMinus500(ResponseEvent $event): void { $this->log($event, '-500'); }
  public function atMinus998(ResponseEvent $event): void { $this->log($event, '-998'); }
  public function atMinus1000(ResponseEvent $event): void { $this->log($event, '-1000'); }
  public function atMinus1500(ResponseEvent $event): void { $this->log($event, '-1500'); }
  public function atMinus2000(ResponseEvent $event): void { $this->log($event, '-2000'); }
  public function atMinus9999(ResponseEvent $event): void { $this->log($event, '-9999'); }

  private function log(ResponseEvent $event, string $priority): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    // Only log paths flagged with our debug header so this stays cheap.
    if ($request->headers->get('X-Vary-Debug') !== '1') {
      return;
    }
    $response = $event->getResponse();
    $varyAll = $response->headers->all('vary');
    \Drupal::logger('vary_debug')->notice(
      sprintf('pri=%s path=%s count=%d values=%s', $priority, $path, count($varyAll), json_encode($varyAll))
    );
  }

}
