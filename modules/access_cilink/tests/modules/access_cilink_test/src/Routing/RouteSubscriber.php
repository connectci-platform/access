<?php

namespace Drupal\access_cilink_test\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Swaps the KB resource detail controller for a stub.
 *
 * The real controller renders a full page that needs the rest of the ACCESS
 * stack (libraries, flag links, login form). The kernel tests only exercise
 * route access, so the body is irrelevant.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['access_cilink.cilinks', 'access_cilink.kb_cilinks'] as $name) {
      if ($route = $collection->get($name)) {
        $route->setDefault('_controller', '\Drupal\access_cilink_test\Controller\StubController::detail');
        $route->setDefault('_title', 'KB Resource');
        $defaults = $route->getDefaults();
        unset($defaults['_title_callback']);
        $route->setDefaults($defaults);
      }
    }
  }

}
