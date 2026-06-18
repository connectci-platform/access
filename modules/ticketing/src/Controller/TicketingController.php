<?php

namespace Drupal\ticketing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Redirect to JSM.
 */
final class TicketingController extends ControllerBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a TicketingController object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user')
    );
  }

  /**
   * Redirect to JSM, and prefill the .
   */
  public function doRedirect(?string $ticket_id = NULL): TrustedRedirectResponse {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUser->id());
    $account_name = $account->getAccountName();
    $display_name = $account->getDisplayName();

    $ticket_id = is_numeric($ticket_id) ? $ticket_id : 17;

    if ($ticket_id == 26) {
      $uri = Url::fromUri('https://access-ci.atlassian.net/servicedesk/customer/portal/3/group/5/create/' . $ticket_id,
        [
          'query' => [
            'customfield_10103' => $account_name,
            'customfield_10108' => $display_name,
          ],
        ]
      );
    }
    else {
      $uri = Url::fromUri('https://access-ci.atlassian.net/servicedesk/customer/portal/2/group/3/create/' . $ticket_id,
        [
          'query' => [
            'customfield_10103' => $account_name,
            'customfield_10108' => $display_name,
          ],
        ]
      );
    }

    return new TrustedRedirectResponse($uri->toString());
  }

}
