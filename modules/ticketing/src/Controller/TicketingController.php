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
  public function doRedirect($ticket_id = NULL) {
    $account = User::load(\Drupal::currentUser()->id());  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass, DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
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
