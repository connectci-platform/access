<?php

namespace Drupal\ticketing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\Entity\User;

/**
 * Redirect to JSM.
 */
class TicketingController extends ControllerBase {

  /**
   * Redirect to JSM, and prefill the .
   */
  public function doRedirect() {
    $account = User::load(\Drupal::currentUser()->id());
    $account_name = $account->getAccountName();
    $display_name = $account->getDisplayName();

    $uri = Url::fromUri('https://access-ci.atlassian.net/servicedesk/customer/portal/2/group/3/create/31',
      [
        'query' => [
          'customfield_10103' => $account_name,
          'customfield_10108' => $display_name,
        ],
      ]
    );

    return new TrustedRedirectResponse($uri->toString());
  }

}
