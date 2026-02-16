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
   * Send post-survey email.
   */
  public function postSurveyEmail() {
    // Get events that don't have their post survey sent yet.
    $entity_query = $this->entityTypeManager->getStorage('eventinstance')->getQuery();
    $entity_query->accessCheck(FALSE);
    $entity_query->condition('field_post_survey_sent', 0);
    $result = $entity_query->execute();

    foreach ($result as $entity_id) {
      $event_instance = $this->entityTypeManager->getStorage('eventinstance')->load($entity_id);

      $end_date = $event_instance->date->end_value;
      $end_date = strtotime($end_date);
      $before_end = $end_date - (30 * 60);
      $now = $this->time->getRequestTime();

      if ($before_end <= $now) {
        $policy = 'access_misc';

        $entity_query = $this->entityTypeManager->getStorage('registrant')->getQuery();
        $entity_query->accessCheck(FALSE);
        $entity_query->condition('eventinstance_id', $entity_id);
        $registrants = $entity_query->execute();

        $series = $event_instance->getEventSeries();
        $series_title = $series->get('title')->value;
        $series_title_url = $this->siteTools->getEventCurrentDomainUrl($entity_id);
        $series_post_survey_text = $series->get('field_post_survey_email_text')->value;

        foreach ($registrants as $registrant_id) {
          $registrant = $this->entityTypeManager->getStorage('registrant')->load($registrant_id);

          if ($registrant->field_post_survey_sent->value) {
            continue;
          }

          $name = $registrant->field_first_name->value . ' ' . $registrant->field_last_name->value;
          $email = $registrant->title->value;
          $user_id = $registrant->user_id->target_id;
          $domain = $this->requestStack->getCurrentRequest()->getHost();
          $post_survey_url = "https://$domain/events/$entity_id/post_survey/$user_id";

          // Get list of unique emails.
          $variables = [
            'title' => $series_title,
            'name' => $name,
            'title_link' => $series_title_url,
            'post_survey_text' => $series_post_survey_text,
            'post_survey_url' => $post_survey_url,
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

    foreach ($result as $entity_id) {
      $event_instance = $this->entityTypeManager->getStorage('eventinstance')->load($entity_id);

      $end_date = $event_instance->date->end_value;
      $end_date = strtotime($end_date);
      // Send reminder 3 days after event end.
      $reminder_date = $end_date + (3 * 24 * 60 * 60);
      $now = $this->time->getRequestTime();

      if ($reminder_date <= $now) {
        $policy = 'access_misc';

        $entity_query = $this->entityTypeManager->getStorage('registrant')->getQuery();
        $entity_query->accessCheck(FALSE);
        $entity_query->condition('eventinstance_id', $entity_id);
        $registrants = $entity_query->execute();

        $series = $event_instance->getEventSeries();
        $series_title = $series->get('title')->value;
        $series_title_url = $this->siteTools->getEventCurrentDomainUrl($entity_id);
        $series_post_survey_text = $series->get('field_post_survey_email_text')->value;

        foreach ($registrants as $registrant_id) {
          $registrant = $this->entityTypeManager->getStorage('registrant')->load($registrant_id);


          if ($registrant->field_post_survey_reminder_sent->value) {
            continue;
          }

          $name = $registrant->field_first_name->value . ' ' . $registrant->field_last_name->value;
          $email = $registrant->title->value;
          $user_id = $registrant->user_id->target_id;
          $domain = $this->requestStack->getCurrentRequest()->getHost();
          $post_survey_url = "https://$domain/events/$entity_id/post_survey/$user_id";

          // Get list of unique emails.
          $variables = [
            'title' => $series_title,
            'name' => $name,
            'title_link' => $series_title_url,
            'post_survey_text' => $series_post_survey_text,
            'post_survey_url' => $post_survey_url,
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

  }

}
