<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Post Survey functions.
 */
class PostSurvey {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The site tools service.
   *
   * @var mixed
   */
  protected $siteTools;

  /**
   * The mail service.
   *
   * @var mixed
   */
  protected $mailService;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Construct object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param mixed $site_tools
   *   The site tools service.
   * @param mixed $mail_service
   *   The mail service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    RequestStack $request_stack,
    $site_tools,
    $mail_service,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->siteTools = $site_tools;
    $this->mailService = $mail_service;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get the hostname for an event instance from its domain_access field.
   *
   * Falls back to the current request host if no domain is assigned.
   */
  protected function getEventDomain($event_instance) {
    $domains = $event_instance->get('domain_access')->referencedEntities();
    if (!empty($domains)) {
      // Set the active domain so hook_mailer_init() routes through
      // the correct SMTP transport.
      $negotiator = \Drupal::service('domain.negotiator');
      $negotiator->setActiveDomain(reset($domains));
      return reset($domains)->getHostname();
    }
    return $this->requestStack->getCurrentRequest()->getHost();
  }

  /**
   * Send post-survey email.
   */
  public function postSurveyEmail() {
    // Get events that don't have their post survey sent yet.
    $entity_query = $this->entityTypeManager->getStorage('eventinstance')->getQuery();
    $entity_query->accessCheck(FALSE);
    $entity_query->condition('field_post_survey_sent', 0);
    $result = $entity_query->execute();

    $negotiator = \Drupal::service('domain.negotiator');
    $original_domain = $negotiator->getActiveDomain();

    foreach ($result as $entity_id) {
      $event_instance = $this->entityTypeManager->getStorage('eventinstance')->load($entity_id);

      // A cancelled (archived) instance's registrants already got the
      // cancellation notice; a post-survey for an event that was called off
      // would be confusing at best. moderation_state is not reliably usable
      // as an entity-query condition on this entity type, so filter here
      // after load instead.
      if ($event_instance->get('moderation_state')->value === 'archived') {
        continue;
      }

      // An instance with no end date is not eligible for a post-survey: a
      // null/empty/unparseable end_value makes strtotime() return false, and
      // false - 1800 <= now is always TRUE, which would wrongly send the
      // survey and stamp it sent. Skip such an instance entirely (no send, no
      // stamp) rather than mis-fire on a date the instance does not have.
      $end_value = $event_instance->date->end_value;
      $end_date = $end_value ? strtotime($end_value) : FALSE;
      if ($end_date === FALSE) {
        continue;
      }
      $before_end = $end_date - (30 * 60);
      $now = $this->time->getRequestTime();

      if ($before_end <= $now) {
        $policy = 'access_misc';
        $domain = $this->getEventDomain($event_instance);

        $entity_query = $this->entityTypeManager->getStorage('registrant')->getQuery();
        $entity_query->accessCheck(FALSE);
        $entity_query->condition('eventinstance_id', $entity_id);
        $registrants = $entity_query->execute();

        $series = $event_instance->getEventSeries();
        $series_title = $series->get('title')->value;
        // Inheritance computed field — instance override falls back to series.
        $email_template = $event_instance->get('post_survey_email_text')->value;

        foreach ($registrants as $registrant_id) {
          $registrant = $this->entityTypeManager->getStorage('registrant')->load($registrant_id);

          if ($registrant->field_post_survey_sent->value) {
            continue;
          }

          $name = $registrant->field_first_name->value . ' ' . $registrant->field_last_name->value;
          $email = $registrant->title->value;
          $user_id = $registrant->user_id->target_id;
          $post_survey_url = "https://$domain/events/$entity_id/post_survey/$user_id";

          // Render the full email body from the event's template field.
          $custom_body = _access_events_render_email_template($email_template, [
            'name' => $name,
            'title' => $series_title,
            'post_survey_url' => $post_survey_url,
          ]);

          $variables = [
            'title' => $series_title,
            'name' => $name,
            'custom_body' => $custom_body,
          ];

          $policy_subtype = 'post_survey';
          try {
            $this->mailService->email($policy, $policy_subtype, $email, $variables);
          }
          catch (\Exception $e) {
            $this->loggerFactory->get('access_misc')
              ->error('Error sending post survey email to ' . $email . ': ' . $e->getMessage());
          }

          // Mark Registrant as survey sent.
          $registrant->set('field_post_survey_sent', $now);
          $registrant->save();
        }

        // Mark Event Instance as survey sent.
        $event_instance->field_post_survey_sent->value = 1;
        $event_instance->save();
      }
    }

    // Restore the original active domain.
    if ($original_domain) {
      $negotiator->setActiveDomain($original_domain);
    }
  }

  /**
   * Send post-survey reminder email.
   */
  public function postSurveyReminderEmail() {
    // Get events that their post survey sent but not the reminder.
    $entity_query = $this->entityTypeManager->getStorage('eventinstance')->getQuery();
    $entity_query->accessCheck(FALSE);
    $entity_query->condition('field_post_survey_sent', 1);
    $entity_query->condition('field_post_survey_reminder_sent', 0);
    $result = $entity_query->execute();

    $negotiator = \Drupal::service('domain.negotiator');
    $original_domain = $negotiator->getActiveDomain();

    foreach ($result as $entity_id) {
      $event_instance = $this->entityTypeManager->getStorage('eventinstance')->load($entity_id);

      // An instance with no end date is not eligible for a post-survey
      // reminder: a null/empty/unparseable end_value makes strtotime() return
      // false, so the reminder window would compute off a date the instance
      // does not have. Skip such an instance (no send, no stamp).
      $end_value = $event_instance->date->end_value;
      $end_date = $end_value ? strtotime($end_value) : FALSE;
      if ($end_date === FALSE) {
        continue;
      }
      // Send reminder 3 days after event end.
      $reminder_date = $end_date + (3 * 24 * 60 * 60);
      $now = $this->time->getRequestTime();

      if ($reminder_date <= $now) {
        $policy = 'access_misc';
        $domain = $this->getEventDomain($event_instance);

        $entity_query = $this->entityTypeManager->getStorage('registrant')->getQuery();
        $entity_query->accessCheck(FALSE);
        $entity_query->condition('eventinstance_id', $entity_id);
        $registrants = $entity_query->execute();

        $series = $event_instance->getEventSeries();
        $series_title = $series->get('title')->value;
        // Inheritance computed field — instance override falls back to series.
        $email_template = $event_instance->get('post_survey_email_text')->value;

        foreach ($registrants as $registrant_id) {
          $registrant = $this->entityTypeManager->getStorage('registrant')->load($registrant_id);

          if ($registrant->field_post_survey_reminder_sent->value) {
            continue;
          }

          $name = $registrant->field_first_name->value . ' ' . $registrant->field_last_name->value;
          $email = $registrant->title->value;
          $user_id = $registrant->user_id->target_id;
          $post_survey_url = "https://$domain/events/$entity_id/post_survey/$user_id";

          // Render the full email body from the event's template field.
          $custom_body = _access_events_render_email_template($email_template, [
            'name' => $name,
            'title' => $series_title,
            'post_survey_url' => $post_survey_url,
          ]);

          $variables = [
            'title' => $series_title,
            'name' => $name,
            'custom_body' => $custom_body,
          ];

          $policy_subtype = 'post_survey_reminder';
          try {
            $this->mailService->email($policy, $policy_subtype, $email, $variables);
          }
          catch (\Exception $e) {
            $this->loggerFactory->get('access_misc')
              ->error('Error sending post survey email to ' . $email . ': ' . $e->getMessage());
          }

          // Mark Registrant as survey sent.
          $registrant->set('field_post_survey_reminder_sent', $now);
          $registrant->save();
        }

        // Mark Event Instance as survey sent.
        $event_instance->field_post_survey_reminder_sent->value = 1;
        $event_instance->save();
      }
    }

    // Restore the original active domain.
    if ($original_domain) {
      $negotiator->setActiveDomain($original_domain);
    }
  }

}
