<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event Subscriber EventSubscriber.
 */
class Subscriber implements EventSubscriberInterface {

  /**
   * Redirect user if not authenticated and on /login page.
   */
  public function onRequest(RequestEvent $event) {

    $user_is_authenticated = \Drupal::currentUser()->isAuthenticated();
    $route_name = \Drupal::routeMatch()->getRouteName();

    // Return if we are not on ACCESS Support domain.
    $token = \Drupal::token();
    $domainName = t("[domain:name]");
    $current_domain_name = Html::getClass($token->replace($domainName));
    $domain_verified = $current_domain_name === 'access-support';

    // Log user in on the /login page.
    if ($route_name == 'misc.login' && !$user_is_authenticated) {
      $this->doRedirectToCilogon($event);
    }
    // Redirect user.login to Cilogon (but allow API requests for service accounts).
    $is_api_request = $event->getRequest()->getRequestFormat() === 'json'
      || $event->getRequest()->query->get('_format') === 'json';
    if ($domain_verified && $route_name == 'user.login' && !$user_is_authenticated && !$is_api_request) {
      $this->doRedirectToCilogon($event);
    }

    // Get destination query.
    $query = \Drupal::request()->query->get('redirect') ? Xss::filter(\Drupal::request()->query->get('redirect')) : '';
    // Get url query 'check_logged_in'.
    $logged_in = \Drupal::request()->query->get('check_logged_in') ? Xss::filter(\Drupal::request()->query->get('check_logged_in')) : '';

    if ($query) {
      $request = \Drupal::request();
      $session = $request->getSession();
      $session->set('cilogon_destination', $query);
      \Drupal::logger('access_misc')->notice("Destination set to $query");
    }

    if ($logged_in) {
      $request = \Drupal::request();
      $session = $request->getSession();
      $query_set = $session->get('cilogon_destination');
      if ($query_set) {
        $session->remove('cilogon_destination');
        \Drupal::logger('access_misc')->notice("Redirecting to $query_set");
        $event->setResponse(new RedirectResponse($query_set));
      }
    }
  }

  /**
   * Redirect to Cilogon.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   Response event.   *.
   */
  protected function doRedirectToCilogon(RequestEvent $event) {
    $request = $event->getRequest();

    $container = \Drupal::getContainer();
    $client_name = 'cilogon';
    
    // Try openid_connect first, fallback to cilogon_auth
    $moduleHandler = \Drupal::service('module_handler');
    $using_openid_connect = $moduleHandler->moduleExists('openid_connect_cilogon_client');
    
    if ($using_openid_connect) {
      // Use openid_connect
      $config_name = 'openid_connect.settings.' . $client_name;
      $configuration = $container->get('config.factory')->get($config_name)->get('settings');
      $pluginManager = $container->get('plugin.manager.openid_connect_client');
      $client = $pluginManager->createInstance($client_name, $configuration);
      
      // Set destination in session for openid_connect
      $destination = $request->getRequestUri();
      $query = NULL;
      if (NULL !== \Drupal::request()->query->get('redirect')) {
        $query = Xss::filter(\Drupal::request()->query->get('redirect'));
      }
      
      $_SESSION['openid_connect_op'] = 'login';
      $_SESSION['openid_connect_destination'] = [$destination, ['query' => $query]];
      
      // Get scopes from client
      $scopes = implode(' ', $client->getClientScopes());
      $response = $client->authorize($scopes);
    }
    else {
      // Fallback to cilogon_auth (legacy)
      $config_name = 'cilogon_auth.settings.' . $client_name;
      $configuration = $container->get('config.factory')->get($config_name)->get('settings');
      $pluginManager = $container->get('plugin.manager.cilogon_auth_client.processor');
      $claims = $container->get('cilogon_auth.claims');
      $client = $pluginManager->createInstance($client_name, $configuration);
      $scopes = $claims->getScopes();
      
      $destination = $request->getRequestUri();
      $query = NULL;
      if (NULL !== \Drupal::request()->query->get('redirect')) {
        $query = Xss::filter(\Drupal::request()->query->get('redirect'));
      }
      
      $_SESSION['cilogon_auth_op'] = 'login';
      $_SESSION['cilogon_auth_destination'] = [$destination, ['query' => $query]];
      
      $response = $client->authorize($scopes);
    }

    $response->headers->set('Cache-Control', 'public, max-age=0');
    $event->setResponse($response);
  }

  /**
   * Subscribe to onRequest events.
   *
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest', 31];
    return $events;
  }

}
