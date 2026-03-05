<?php

namespace Drupal\access_affinitygroup\Plugin\EmailBuilder;

use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Email Builder plug-in for the access_affinitygroup module.
 *
 * @EmailBuilder(
 *   id = "affinitygroup",
 *   sub_types = {
 *     "simplelist_error" = @Translation("Simplelist Error"),
 *     "allocation_error" = @Translation("Allocation Error"),
 *     "cc_error" = @Translation("Constant Contact Error"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class AgEmailBuilder extends EmailBuilderBase {

  /**
   * Builds the email by setting the from address.
   */
  public function build(EmailInterface $email) {
    $email->setFrom('noreply@access-ci.org');
  }

}
