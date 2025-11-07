<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\user\Entity\User;
use Drupal\flag\Event\FlagEvents;
use Drupal\flag\Event\FlaggingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 *
 */
class ProjectFlagSubscriber implements EventSubscriberInterface {

  /**
   * Subscribe to onFlag events.
   *
   * - Set state for flagging CI Links so email notifications can be send daily.
   * - Invalidate CI Link cache on flagging.
   */
  public function onFlag(FlaggingEvent $event) {
    $flagging = $event->getFlagging();
    $flag_id = $flagging->getFlagId();
    $entity_sid = $flagging->getFlaggable()->id();
    // Only apply this to 'PA Science' for now.
    if ($flag_id == 'interested_in_project') {
      $domain = \Drupal::service('access_misc.sitetools')->getProgram();
      $domain = is_array($domain) ? implode(',', $domain) : $domain;
      if ($domain == 933) {
        $policy = 'access_misc_project';
        $policy_subtype = 'project_flagged';
        // Lookup webform submission.
        $webform_submission = \Drupal::entityTypeManager()
          ->getStorage('webform_submission')
          ->load($entity_sid);
        // Get field 'project_title' of submission.
        $title = $webform_submission->getData()['project_title'];
        $author_uid = $webform_submission->getOwnerId();
        $author_email = User::load($author_uid)->getEmail();

        // Create url 'host/webform/project/submissions/$entity_id/edit'.
        $host = \Drupal::request()->getSchemeAndHttpHost();
        $url = $host . '/webform/project/submissions/' . $entity_sid . '/edit';

        // Get id of user flagging the project.
        $flagged_by = $flagging->getOwnerId();
        $user = User::load($flagged_by);

        $field_user_first_name = $user->get('field_user_first_name')->value;
        $field_user_last_name = $user->get('field_user_last_name')->value;
        $fullname = $field_user_first_name . ' ' . $field_user_last_name;

        $role = 'pascience_manager';

        $to_email = \Drupal::service('access_misc.usertools')->getEmails([$role], []);

        $variables = [
          'title' => $title,
          'flagged_name' => $fullname,
          'url' => $url,
        ];

        if ($to_email) {
          // Send to all managers.
          \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $to_email, $variables);
        }
        if ($author_email) {
          // Send to author.
          \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $author_email, $variables);
        }
      }

    }
  }

  /**
   *
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[FlagEvents::ENTITY_FLAGGED][] = ['onFlag'];
    return $events;
  }

}
