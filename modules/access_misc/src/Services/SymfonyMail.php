<?php

namespace Drupal\access_misc\Services;

/**
 * Environment icon to be used on header title.
 *
 * @symfonyMail(
 *   id = "symfonyMail",
 *   title = @Translation("Symfony Mail"),
 *   description = @Translation("Symfony Mail Service.")
 * )
 */
class SymfonyMail {

  /**
   * Store link.
   *
   * @var string
   */
  private $link;

  /**
   * Constructs a new SymfonyMail object.
   */
  public function __construct() {
  }

  /**
   * Send mail.
   */
  public function email($policy, $policy_subtype, $set_email, $variables) {
    // Currently multiple emails are not working. Send separately.
    if (strpos($set_email, ',') !== FALSE) {
      $set_email = explode(',', $set_email);
    }
    $set_email = is_array($set_email) ? $set_email : [$set_email];
    foreach ($set_email as $single_email) {
      $email_factory = \Drupal::service('email_factory'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $email = $email_factory->newTypedEmail($policy, $policy_subtype);
      foreach ($variables as $key => $value) {
        $email->setVariable($key, $value);
      }
      $email->setTo($single_email);
      $email->send();
    }
  }

}
