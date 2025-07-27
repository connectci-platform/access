<?php

namespace Drupal\ccmnet\Plugin\EmailBuilder;

use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Email Builder plug-in for the ccmnet module.
 *
 * @EmailBuilder(
 *   id = "ccmnet",
 *   sub_types = {
 *     "mentor_changed" = @Translation("Mentor Changed notification"),
 *     "mentee_changed" = @Translation("Mentee Changed notification"),
 *     "liaison_mentor_mentee_changed" = @Translation("Liaison Mentor/Mentee Changed notification"),
 *     "mentorship_in_progress" = @Translation("Notify liaison and ccmnet pm that mentorship has switched to progress"),
 *     "mentorship_created_admin" = @Translation("Notify ccmnet admin that mentorship has been created"),
 *     "mentorship_approved_ccmnet_pm" = @Translation("Notify ccmnet pm that mentorship has been approved"),
 *     "ccmnet_new_user" = @Translation("Notify ccmnet pm that a new user has been created"),
 *     "ccmnet_welcome" = @Translation("Welcome message for new ccmnet user"),
 *     "cron_author_update" = @Translation("Once per day send an email notifying the author of the update to mentor/mentee"),
 *     "cron_role_update" = @Translation("Once per day send an email notifying the ccmnet role of the update to mentor/mentee"),
 *     "mentorship_created" = @Translation("Notify person their mentorship engagement has been created"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class MentorshipEmailBuilder extends EmailBuilderBase {
  public function build(EmailInterface $email) {
    $email->setFrom('noreply@ccmnet.org');
    
    // Handle HTML content from variables (like 'extra' containing HTML lists)
    $variables = $email->getVariables();
    if (isset($variables['extra']) && is_string($variables['extra'])) {
      // If 'extra' contains HTML, set it as the body
      $email->setBody($variables['extra']);
    } else {
      // Set HTML content type for proper rendering
      $body = $email->getBody();
      if (is_array($body)) {
        $body = implode("\n", $body);
      }
      if (!empty($body)) {
        $email->setBody($body);
      }
    }
  }
}
