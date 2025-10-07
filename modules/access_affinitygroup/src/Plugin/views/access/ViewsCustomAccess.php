<?php


namespace Drupal\access_affinitygroup\Plugin\views\access;

use Drupal\views\Plugin\views\access\AccessPluginBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Class ViewsCustomAccess
 *
 * @ingroup views_access_plugins
 *
 * @ViewsAccess(
 *     id = "ViewsCustomAccess",
 *     title = @Translation("Custom AG Access"),
 *     help = @Translation("Add custom logic to access() method"),
 * )
 */
class ViewsCustomAccess extends AccessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Custom AG Access');
  }


  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    $access = FALSE;
    // Get current user roles.
    $roles = $account->getRoles();
    // If roles contain administrator role, then grant access.
    if (in_array('administrator', $roles)) {
      $access = TRUE;
    }

    // Try to get nid from query string first, then from route.
    $nid = \Drupal::request()->query->get('nid');
    if (!$nid) {
      $node = \Drupal::routeMatch()->getParameter('node');
      $nid = $node ? $node->id() : NULL;
    }

    if ($nid) {
      $node = \Drupal\node\Entity\Node::load($nid);
      if ($node) {
        $coordinators = $node->get('field_coordinator')->getValue();
        foreach ($coordinators as $coordinator) {
          if ($coordinator['target_id'] == $account->id()) {
            $access = TRUE;
            break;
          }
        }
      }
    }
    return $access;
  }


  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    $route->setRequirement('_access', 'TRUE');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Cache per user and per URL (since we check ?nid query parameter).
    return ['user', 'url.query_args:nid'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();

    // Add the affinity group node as a cache tag so when it's updated
    // (e.g., coordinators changed), the view cache is invalidated.
    // Try query parameter first, then route.
    $nid = \Drupal::request()->query->get('nid');
    if (!$nid) {
      $node = \Drupal::routeMatch()->getParameter('node');
      if ($node) {
        $nid = $node->id();
      }
    }

    if ($nid) {
      $tags[] = 'node:' . $nid;
    }

    return $tags;
  }
}
