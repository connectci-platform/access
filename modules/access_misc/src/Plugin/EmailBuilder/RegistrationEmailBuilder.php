<?php

namespace Drupal\access_misc\Plugin\EmailBuilder;

use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Email Builder plug-in for the access_misc module.
 *
 * @EmailBuilder(
 *   id = "access_misc",
 *   sub_types = {
 *     "register" = @Translation("User Registers for an Event"),
 *     "registration_approved" = @Translation("User Registration Approved"),
 *     "waitlist" = @Translation("User Added to Waitlist"),
 *     "registrant_digest" = @Translation("Email to event author with registrant digest"),
 *     "post_survey" = @Translation("Email to registered user with post-survey"),
 *     "post_survey_reminder" = @Translation("Email to registered user with post-survey reminder"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class RegistrationEmailBuilder extends EmailBuilderBase {

  /**
   * Builds the registration email.
   */
  public function build(EmailInterface $email) {
    $email->setFrom('noreply@support.access-ci.org');
  }

}
