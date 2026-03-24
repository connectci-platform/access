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

    // Get url query 'check_logged_in'.
    $logged_in = \Drupal::request()->query->get('check_logged_in') ? Xss::filter(\Drupal::request()->query->get('check_logged_in')) : '';

    if ($logged_in && $user_is_authenticated) {
      $request = \Drupal::request();
      $session = $request->getSession();
      $query_set = $session->get('cilogon_destination');
      if ($query_set) {
        $session->remove('cilogon_destination');
        \Drupal::logger('access_misc')->notice("Redirecting to $query_set");
        // Use Url::fromUserInput() to validate the redirect is internal
        try {
          $url = \Drupal\Core\Url::fromUserInput($query_set);
          $event->setResponse(new RedirectResponse($url->toString()));
        }
        catch (\InvalidArgumentException $e) {
          // Invalid URL, redirect to homepage instead
          \Drupal::logger('access_misc')->warning("Invalid redirect destination: $query_set");
          $event->setResponse(new RedirectResponse('/'));
        }
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

    $config_name = 'openid_connect.settings.' . $client_name;
    $configuration = $container->get('config.factory')->get($config_name)->get('settings');
    $pluginManager = $container->get('plugin.manager.openid_connect_client');
    $client = $pluginManager->createInstance($client_name, $configuration);

    // Store redirect destination in session for post-login redirect.
    if (NULL !== $request->query->get('redirect')) {
      $query = Xss::filter($request->query->get('redirect'));
      $session = $request->getSession();
      $session->set('cilogon_destination', $query);
      \Drupal::logger('access_misc')->notice("Destination set to $query");
    }

    $_SESSION['openid_connect_op'] = 'login';
    $_SESSION['openid_connect_destination'] = [
      '/login',
      ['query' => 'check_logged_in=1'],
    ];

    $scopes = implode(' ', $client->getClientScopes());
    $response = $client->authorize($scopes);
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
