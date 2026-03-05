<?php

namespace Drupal\access_misc\Plugin\EmailBuilder;

use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Email Builder plug-in for project notifications.
 *
 * @EmailBuilder(
 *   id = "access_misc_project",
 *   label = @Translation("Access Misc Project"),
 *   sub_types = {
 *     "project_flagged" = @Translation("Email author/role when project gets flagged"),
 *     "project_created_pascience" = @Translation("PA Science - Project Created (to manager)"),
 *     "project_received_pascience" = @Translation("PA Science - Project Received (to author)"),
 *     "project_updated_pascience" = @Translation("PA Science - Project Updated (to manager)"),
 *     "project_approved_pascience" = @Translation("PA Science - Project Approved (to author)"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class ProjectEmailBuilder extends EmailBuilderBase {

  /**
   * Builds the project email.
   */
  public function build(EmailInterface $email) {
    $subtype = $email->getSubType();

    // Determine domain from subtype.
    $domain = NULL;
    if (strpos($subtype, 'pascience') !== FALSE) {
      $domain = 'pa-science';
    }
    // Add other domain mappings here as needed.
    // Get noreply email for the domain.
    $site_tools = \Drupal::service('access_misc.sitetools');
    $from_email = $site_tools->getNoreplyEmail($domain);

    $email->setFrom($from_email);
  }

}
