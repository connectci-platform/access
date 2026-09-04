<?php

declare(strict_types=1);

namespace Drupal\access_events\EventSubscriber;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Strips the admin-route flag recurring_events 3.0.0 added to /events.
 */
class RouteAlterSubscriber implements EventSubscriberInterface {

  /**
   * Removes options._admin_route from entity.eventinstance.collection.
   *
   * The recurring_events module (3.0.0) added options._admin_route: TRUE to the
   * eventinstance collection route (entity.eventinstance.collection, path
   * /events). The events_facet View's page_1 display shares that exact
   * path, so Views takes over the existing route rather than registering
   * its own — and inherits the admin-route flag along with it, even though
   * the View has no admin-theme setting of its own. Since 'view the
   * administration theme' is granted to admin-ish roles, logged-in
   * privileged users ended up with /events wrapped in the admin theme while
   * anonymous visitors (who lack that permission) still saw the default
   * theme. Strip the flag to restore recurring_events 2.0.3 behavior.
   *
   * Must run after \Drupal\views\EventSubscriber\RouteSubscriber, which
   * takes over this route at priority -175.
   */
  public function onAlterRoutes(RouteBuildEvent $event): void {
    $collection = $event->getRouteCollection();
    if ($route = $collection->get('entity.eventinstance.collection')) {
      $options = $route->getOptions();
      unset($options['_admin_route']);
      $route->setOptions($options);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -200];
    return $events;
  }

}
