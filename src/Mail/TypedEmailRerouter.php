<?php

namespace Drupal\access\Mail;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reroute_email\Constants\RerouteEmailConstants;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Exception\SkipMailException;
use Psr\Log\LoggerInterface;

/**
 * Applies reroute_email's settings to symfony_mailer typed emails.
 *
 * The reroute_email module only reroutes legacy mail through
 * hook_mail_alter(). Typed symfony_mailer emails bypass that hook, so this
 * service mirrors the behavior of reroute_email_mail_alter() for them. It
 * reuses reroute_email_check() so the allowlist, roles, and mail key settings
 * are evaluated identically.
 */
class TypedEmailRerouter {

  use StringTranslationTrait;

  /**
   * Constructs a TypedEmailRerouter.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The access logger channel.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected MessengerInterface $messenger,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Registers the rerouting callbacks on a typed email.
   *
   * @param \Drupal\symfony_mailer\EmailInterface $email
   *   The email, in the init phase.
   */
  public function init(EmailInterface $email): void {
    if (!$this->moduleHandler->moduleExists('reroute_email')) {
      return;
    }
    // Legacy mail already passed through reroute_email_mail_alter(). Skip it
    // to avoid rerouting and announcing it twice.
    if ($email->getParam('legacy_message') !== NULL) {
      return;
    }

    $state = new \stdClass();
    $state->rerouted = FALSE;
    $state->key = '';
    $state->site = '';
    $state->to = '';
    $state->cc = '';
    $state->bcc = '';

    // @todo Calling setTo() in the build phase triggers a symfony_mailer 1.6
    // deprecation and will fail in symfony_mailer 2.0. It cannot move to the
    // init phase because the To address is not yet set at that point.
    $email->addCallback(function (EmailInterface $email) use ($state): void {
      $this->reroute($email, $state);
    }, EmailInterface::PHASE_BUILD, 10000, 'access_reroute_build');
    // After AbsoluteUrl (700) and before WrapAndConvert (800), so the plain
    // text alternative includes the notice.
    $email->addCallback(function (EmailInterface $email) use ($state): void {
      $this->addNotice($email, $state);
    }, EmailInterface::PHASE_POST_RENDER, 750, 'access_reroute_notice');
  }

  /**
   * Reroutes the email if reroute_email says it should be.
   *
   * @param \Drupal\symfony_mailer\EmailInterface $email
   *   The email, in the build phase.
   * @param \stdClass $state
   *   The shared state for this email.
   *
   * @throws \Drupal\symfony_mailer\Exception\SkipMailException
   *   When the configured rerouting address is empty.
   */
  protected function reroute(EmailInterface $email, \stdClass $state): void {
    global $base_url;

    $key = $email->getType() . '_' . $email->getSubType();
    $to = $this->joinAddresses($email->getTo());
    $cc = $this->joinAddresses($email->getCc());
    $bcc = $this->joinAddresses($email->getBcc());

    $message = [
      'id' => $key,
      'module' => $email->getType(),
      'to' => $to,
      'headers' => [],
    ];
    if ($cc !== '') {
      $message['headers']['Cc'] = $cc;
    }
    if ($bcc !== '') {
      $message['headers']['Bcc'] = $bcc;
    }

    if (!reroute_email_check($message)) {
      return;
    }

    $config = $this->configFactory->get('reroute_email.settings');
    $address = $config->get(RerouteEmailConstants::REROUTE_EMAIL_ADDRESS);
    if ($address === NULL) {
      $address = $this->configFactory->get('system.site')->get('mail');
    }

    $site = (string) $base_url;
    $state->rerouted = TRUE;
    $state->key = $key;
    $state->site = $site;
    $state->to = $to;
    $state->cc = $cc;
    $state->bcc = $bcc;

    // Mirror reroute_email_mail_alter(): the notice, message and log entry
    // are produced even when the email is aborted.
    if ($config->get(RerouteEmailConstants::REROUTE_EMAIL_MESSAGE)) {
      $this->messenger->addStatus($this->t('An email (ID: %message_id) either aborted or rerouted to the configured address. Site administrators can check the recent log entries for complete details on the rerouted email. For more details please refer to Reroute Email settings.', ['%message_id' => $key]));
    }
    $this->logger->notice('An email (ID: %message_id) was either rerouted or aborted. Originally to: @to, cc: @cc, bcc: @bcc.', [
      '%message_id' => $key,
      '@to' => $to,
      '@cc' => $cc,
      '@bcc' => $bcc,
    ]);

    if ($address === '') {
      throw new SkipMailException('Email aborted because the reroute_email address is empty.');
    }

    $email->setTo(reroute_email_split_string($address));
    $email->setCc(NULL);
    $email->setBcc(NULL);

    $email->addTextHeader('X-Rerouted-Mail-Key', $key);
    $email->addTextHeader('X-Rerouted-Website', $site);
    $email->addTextHeader('X-Rerouted-Original-To', $to);
    if ($cc !== '') {
      $email->addTextHeader('X-Rerouted-Original-Cc', $cc);
    }
    if ($bcc !== '') {
      $email->addTextHeader('X-Rerouted-Original-Bcc', $bcc);
    }
  }

  /**
   * Prepends the reroute notice to the HTML body of a rerouted email.
   *
   * @param \Drupal\symfony_mailer\EmailInterface $email
   *   The email, in the post render phase.
   * @param \stdClass $state
   *   The shared state for this email.
   */
  protected function addNotice(EmailInterface $email, \stdClass $state): void {
    if (!$state->rerouted) {
      return;
    }
    $config = $this->configFactory->get('reroute_email.settings');
    if (!$config->get(RerouteEmailConstants::REROUTE_EMAIL_DESCRIPTION)) {
      return;
    }

    // Addresses are user controlled, so every dynamic value is escaped.
    $lines = [
      $this->t('This email was rerouted.'),
      $this->t('Web site:') . ' ' . Html::escape($state->site),
      $this->t('Mail key:') . ' ' . Html::escape($state->key),
      $this->t('Originally to:') . ' ' . Html::escape($state->to),
    ];
    if ($state->cc !== '') {
      $lines[] = $this->t('Originally cc:') . ' ' . Html::escape($state->cc);
    }
    if ($state->bcc !== '') {
      $lines[] = $this->t('Originally bcc:') . ' ' . Html::escape($state->bcc);
    }
    $lines[] = '-----------------------';

    $notice = '<p>' . implode("<br>\n", array_map('strval', $lines)) . "</p>\n";
    $email->setHtmlBody($notice . $email->getHtmlBody());
  }

  /**
   * Joins the email parts of addresses into a comma-separated string.
   *
   * @param \Drupal\symfony_mailer\AddressInterface[] $addresses
   *   The addresses.
   *
   * @return string
   *   The comma-separated email addresses.
   */
  protected function joinAddresses(array $addresses): string {
    return implode(', ', array_map(fn($address) => $address->getEmail(), $addresses));
  }

}
