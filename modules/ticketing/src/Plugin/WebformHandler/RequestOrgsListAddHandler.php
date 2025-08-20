<?php

namespace Drupal\ticketing\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Send the request to add an organization to the ACCESS organizations list.
 *
 * @WebformHandler(
 *   id = "ACCESS Orgs List Add Header",
 *   label = @Translation("ACCESS Orgs List Add Header"),
 *   category = @Translation("Entity creation"),
 *   description = @Translation("ACCESS Orgs List Add Header"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class RequestOrgsListAddHandler extends WebformHandlerBase {
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

    $to = "support@access-ci.atlassian.net";

    if ($this->debug) {
      // FOR TESTING.
      $to = 'andrew@elytra.net';
    }

    // Build up the email params.
    $params = [];
    $params['to'] = $to;
    $params['from'] = $data['your_email'];
    $body = (string) $this->getMailMessageBody($data);
    $params['body'] = $body;
    $params['title'] = 'Request to add an organization from ' . $data['your_name'];

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

  /**
   *
   */
  public function getMailMessageBody($data) {
    $ticketing_module_path = \Drupal::service('extension.list.module')->getPath('ticketing');
    $twig = \Drupal::service('twig');
    return $this->twig->render(
          $ticketing_module_path . '/templates/request-orgs-list-add-mail.html.twig',
          [
            'theme_hook_original' => 'not-applicable',
            'name' => $data['your_name'],
            'email' => $data['your_email'],
            'organization' => $data['your_organization'],
          ]
      );
  }

}
