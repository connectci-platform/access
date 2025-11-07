<?php

namespace Drupal\access_misc\Plugin\EmailBuilder;

use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Email Builder plug-in for the access_misc module.
 *
 * @EmailBuilder(
 *   id = "access_misc_project",
 *   label = @Translation("Access Misc Project"),
 *   sub_types = {
 *     "project_flagged" = @Translation("Email author/role when project gets flagged"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class ProjectEmailBuilder extends EmailBuilderBase {
  public function build(EmailInterface $email) {
  }
}
