<?php

namespace Drupal\ticketing\Plugin\WebformHandler;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;

/**
 * Create and send the request ticketing email.
 *
 * @WebformHandler(
 *   id = "Ticketing Send Email",
 *   label = @Translation("Ticketing Send Email"),
 *   category = @Translation("Entity creation"),
 *   description = @Translation("Send the Ticketing email"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class TicketingSendEmailHandler extends WebformHandlerBase {

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
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected EmailValidatorInterface $emailValidator;

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
    $instance->emailValidator = $container->get('email.validator');
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
    }

    // Adjust the To: email address based on form selections with this logic:
    // 1 - if a resource is selected, use it
    // 2 - if an allocations category is provided, use that category
    // (with some possible overrides)
    // 3 - if some other category is selected, use that
    // 4 - otherwise use the default queue.
    if ($data['resource'] !== 'issue_not_resource_related') {
      $to = $data['resource'];

      // Some queues have an alias override.
      $queue_alias_overrides = ["0-PSC", "0-TACC"];
      foreach ($queue_alias_overrides as $alias_override) {
        if (str_starts_with($to, $alias_override)) {
          $to = $alias_override;
        }
      }

    }
    elseif ($data['is_your_issue_related_to_allocations_'] == 'Yes') {
      $to = 'ACCESS-Allocations-Support';
    }
    elseif (!empty($data['category'])) {
      $to = $data['category'];
      if ($to == 'ACCESS-XDMoD') {
        $to = 'ACCESS-Metrics';
      }
      elseif ((str_starts_with($to, "ACCESS-Operations-Security"))) {
        // 2022-09-13 -- both "ACCESS-Operations-Security" and
        // "ACCESS-Operations-Security-Accounts" should go to
        // ACCESS-Operations-Security.
        $to = "ACCESS-Operations-Security";
      }
    }
    else {
      $to = '0-Help';
    }

    // Append the email domain.
    $to .= '@tickets.access-ci.org';

    if ($this->debug) {
      // FOR TESTING
      // $to .= ', jasperjunk@gmail.com, andrew@elytra.net';.
      $to = 'jasper.amp@gmail.com';
    }

    // Build up the email params.
    $params = [];
    $params['to'] = $to;
    $params['title'] = $data['subject'];

    // Add the cc's.
    if ($data['cc']) {
      $ccs = explode(',', $data['cc']);
      $valid_ccs = [];
      foreach ($ccs as $cc) {
        $cc = trim($cc);
        $valid = $this->emailValidator->isValid($cc);

        if ($valid) {
          $valid_ccs[] = $cc;
        }
        else {
          $msg = "In the CC list, \"$cc\" is not a valid email address and was not used";
          $this->messenger()->addWarning($msg);
        }
      }
      $params['headers']['cc'] = implode(',', $valid_ccs);
    }

    // Get tags.
    foreach ($data['tag2'] as $tag_id) {
      $term = Term::load($tag_id);
      $data['tag_names'][] = $term->getName();
    }

    // Get the body.
    $from_email = $this->currentUser->getEmail();

    if ($this->debug) {
      $msg = basename(__FILE__) . ':' . __LINE__ . ' -- $from_email = ' . print_r($from_email, TRUE);
      $this->messenger()->addStatus($msg);
    }

    $body = (string) $this->getMailMessageBody($data['problem_description'], $data['tag_names'],
          $data['suggested_tag'], $from_email);
    $params['body'] = $body;

    if ($this->debug) {
      $msg = basename(__FILE__) . ':' . __LINE__ . ' -- in postSave() = $body = ' . print_r($body, TRUE);
      $this->messenger()->addStatus($msg);
    }

    // Add attachments.
    if ($data['attachment']) {
      foreach ($data['attachment'] as $attachment) {
        $load = File::load($attachment);
        $file = (object) [
          'filename' => $load->getFilename(),
          'uri' => $load->getFileUri(),
          'filemime' => $load->getMimeType(),
        ];
        $params['files'][] = $file;
      }
    }

    // Settings for the mail send.
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

    // If user suggested a tag, send that to the support email.
    $new_tag = $data['suggested_tag'];

    if (strlen(trim($new_tag)) > 0) {

      $to = "access-support-website@tickets.access-ci.org";

      if ($this->debug) {
        // FOR TESTING
        // $to .= ', jasperjunk@gmail.com, andrew@elytra.net';.
        $to = 'jasper.amp@gmail.com';
      }

      $params = [];

      $params['to'] = $to;
      $params['title'] = "request for new tag: $new_tag";
      $params['body'] = "The user with email $from_email has suggested this new tag:  $new_tag";

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

  }

  /**
   * Builds the ticketing email body.
   *
   * @param string $description
   *   The problem description.
   * @param array<int, string> $tags
   *   The selected tag names.
   * @param string $suggested_tag
   *   A user-suggested tag.
   * @param string $from_email
   *   The submitter's email address.
   *
   * @return string
   *   The rendered email body.
   */
  public function getMailMessageBody(string $description, array $tags, string $suggested_tag, string $from_email): string {
    $ticketing_module_path = $this->moduleExtensionList->getPath('ticketing');
    return (string) $this->twig->load($ticketing_module_path . '/templates/ticketing-mail.html.twig')->render([
      'problem_description' => $description,
      'tags' => $tags,
      'suggested_tag' => $suggested_tag,
      'email' => $from_email,
    ]);
  }

}
