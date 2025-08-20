<?php

namespace Drupal\ticketing\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Create and send the account support email.
 *
 * @WebformHandler(
 *   id = "Account Support Add Header",
 *   label = @Translation("Account Support Add Header"),
 *   category = @Translation("Entity creation"),
 *   description = @Translation("Account Support Add Header"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class AccountSupportHandler extends WebformHandlerBase {
  public $debug = FALSE;

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webformSubmission, $update = TRUE) {
    $data = $webformSubmission->getData();

    if ($this->debug) {
      $msg = basename(__FILE__) . ':' . __LINE__ . ' -- ' . 'in postSave() = $data = ' . print_r($data, TRUE);
      \Drupal::messenger()->addStatus($msg);

      // $data = Array ( [your_name] => a [email] => jasperjunk@gmail.com [access_id] => [comment] => )
    }

    $to = "0-Help@tickets.access-ci.org";

    if ($this->debug) {

      // FOR TESTING.
      $to .= ', jasperjunk@gmail.com, andrew@elytra.net';
    }

    // Build up the email params.
    $params = [];
    $params['to'] = $to;
    $body = (string) $this->getXMailMessageBody($data);
    $params['body'] = $body;
    $params['title'] = 'account support request from ' . $data['your_name'];

    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = TRUE;
    $module = 'ticketing';
    $key = "ticketing";
    $mailManager = \Drupal::service('plugin.manager.mail');

    $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

    if ($result === FALSE || (array_key_exists('result', $result) && !$result['result'])) {
      $msg = "There was a problem sending the email";
      \Drupal::messenger()->addWarning($msg);
    }

    if ($this->debug) {
      $msg = basename(__FILE__) . ':' . __LINE__ . ' -- ' . 'mail $result = ' . print_r($result, TRUE);
      \Drupal::messenger()->addStatus($msg);
    }
  }

  public function getXMailMessageBody($data) {
    $ticketing_module_path = \Drupal::service('extension.list.module')->getPath('ticketing');
    $twig = \Drupal::service('twig');
    return $twig->render(
          $ticketing_module_path . '/templates/account-support-mail.html.twig',
          [
            'theme_hook_original' => 'not-applicable',
            'name' => $data['your_name'],
            'email' => $data['email'],
            'access_id' => $data['access_id'],
            'comment' => $data['comment'],
          ]
      );
  }

}
