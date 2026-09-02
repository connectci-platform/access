<?php

namespace Drupal\ticketing\Plugin\WebformHandler;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;

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

  /**
   * Whether debug messages should be displayed.
   *
   * @var bool
   */
  public $debug = FALSE;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * The Twig environment.
   *
   * @var \Twig\Environment
   */
  protected Environment $twig;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin
   *   instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->currentUser = $container->get('current_user');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->moduleExtensionList = $container->get('extension.list.module');
    $instance->twig = $container->get('twig');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webformSubmission, $update = TRUE): void {
    $data = $webformSubmission->getData();

    if ($this->debug) {
      $msg = basename(__FILE__) . ':' . __LINE__ . ' -- in postSave() = $data = ' . print_r($data, TRUE);
      $this->messenger()->addStatus($msg);

      // $data = Array ( [your_name] => a [email] => jasperjunk@gmail.com
      // [access_id] => [comment] => )
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

    $langcode = $this->currentUser->getPreferredLangcode();
    $send = TRUE;
    $module = 'ticketing';
    $key = "ticketing";

    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

    if (!$result['result']) {
      $msg = "There was a problem sending the email";
      $this->messenger()->addWarning($msg);
    }

    if ($this->debug) {
      $msg = basename(__FILE__) . ':' . __LINE__ . ' -- mail $result = ' . print_r($result, TRUE);
      $this->messenger()->addStatus($msg);
    }
  }

  /**
   * Builds the organization request email body.
   *
   * @param array<string, mixed> $data
   *   The webform submission data.
   *
   * @return string
   *   The rendered email body.
   */
  public function getMailMessageBody(array $data): string {
    $ticketing_module_path = $this->moduleExtensionList->getPath('ticketing');
    return (string) $this->twig->load($ticketing_module_path . '/templates/request-orgs-list-add-mail.html.twig')->render([
      'name' => $data['your_name'],
      'email' => $data['your_email'],
      'organization' => $data['your_organization'],
      'address_line_1' => $data['address_line_1'] ?? '',
      'address_line_2' => $data['address_line_2'] ?? '',
      'city' => $data['city'] ?? '',
      'state_province_region' => $data['state_province_region'] ?? '',
      'zip_postal_code' => $data['zip_postal_code'] ?? '',
      'country' => $data['country'] ?? '',
      'organization_webpage' => $data['organization_webpage'] ?? '',
    ]);
  }

}
