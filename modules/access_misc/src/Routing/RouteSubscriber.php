<?php

namespace Drupal\access_misc\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds the submission page access check to webform submission view routes.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity access check that marks a submission view page.
   */
  const ENTITY_ACCESS = 'webform_submission.view';

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $route) {
      // Core webform routes use the _entity_access requirement. The
      // webform_node routes (/node/{node}/webform/submission/...) pass the
      // same check as an "entity_access" default to their custom access.
      if ($route->getRequirement('_entity_access') !== self::ENTITY_ACCESS
        && $route->getDefault('entity_access') !== self::ENTITY_ACCESS) {
        continue;
      }
      if (!str_contains($route->getPath(), '{webform_submission}')) {
        continue;
      }
      $route->setRequirement('_access_misc_submission_page', 'TRUE');
    }
  }

}
