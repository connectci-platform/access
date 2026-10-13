<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\Core\PageCache\RequestPolicy\CommandLineOrUnsafeMethod;
use Drupal\Core\PageCache\RequestPolicy\NoSessionOpen;
use Drupal\Core\Session\SessionConfigurationInterface;

/**
 * Page cache request policy that treats CLI requests as cacheable.
 *
 * The default policy refuses to serve or store anything under PHP's CLI SAPI,
 * which would leave the internal page cache out of play in kernel tests. This
 * is the default policy with only that CLI check removed.
 */
class NonCliRequestPolicy extends ChainRequestPolicy {

  public function __construct(SessionConfigurationInterface $session_configuration) {
    $this->addPolicy(new class() extends CommandLineOrUnsafeMethod {

      /**
       * {@inheritdoc}
       */
      protected function isCli() {
        return FALSE;
      }

    });
    $this->addPolicy(new NoSessionOpen($session_configuration));
  }

}
