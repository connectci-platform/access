<?php

namespace Drupal\access_misc\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Match.
 */
class LoginController extends ControllerBase {

  /**
   * Check user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Perform redirect.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * Page cache kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * Constructs request stuff.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   Used to get current active user.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   Kill switch.
   */
  public function __construct(AccountProxyInterface $current_user,
                              KillSwitch $kill_switch,
                              RedirectDestinationInterface $redirect_destination
  ) {
    $this->currentUser = $current_user;
    $this->redirectDestination = $redirect_destination;
    $this->killSwitch = $kill_switch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('page_cache_kill_switch'),
      $container->get('redirect.destination')
    );
  }

  /**
   * Route user to login.
   */
  public function login() {
    $this->killSwitch->trigger();
    // Check if user is logged in.
    if ($this->currentUser->isAuthenticated()) {
      // Get redirect destination from url.
      $destination = $this->redirectDestination->get() ? Xss::filter($this->redirectDestination->get()) : '';
      if (empty($destination) || str_starts_with($destination, '/login')) {
        // Get destination url query.
        $query = \Drupal::request()->query->all();
        $redirect = $query['redirect'] ?? '';
        $destination = '/';

        if (!empty($redirect)) {
          // If redirect is set, use it.
          $destination = Xss::filter($redirect);
        }
      }
      // Redirect to destination.
      $response = new RedirectResponse($destination);
      $response->send();
      return [
        '#type' => 'markup',
        '#markup' => "👋 " . $this->t("You shouldn't see this."),
      ];
    }
    return [
      '#type' => 'markup',
      '#markup' => "👋 " . $this->t("You shouldn't see this."),
    ];
  }

}
