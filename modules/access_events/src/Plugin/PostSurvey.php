<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\access_events\EventDomainContext;
use Drupal\recurring_events\Entity\EventInstance;
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
   * The event domain context switcher.
   *
   * @var \Drupal\access_events\EventDomainContext
   */
  protected $domainContext;

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
   * @param \Drupal\access_events\EventDomainContext $domain_context
   *   The event domain context switcher.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    RequestStack $request_stack,
    $site_tools,
    $mail_service,
    LoggerChannelFactoryInterface $logger_factory,
    EventDomainContext $domain_context,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->siteTools = $site_tools;
    $this->mailService = $mail_service;
    $this->loggerFactory = $logger_factory;
    $this->domainContext = $domain_context;
  }

  /**
   * Get the hostname to build post-survey links on.
   *
   * Falls back to the current request host if no domain is assigned. The
   * active-domain switch that used to live here (so hook_mailer_init() routes
   * through the correct SMTP transport) now belongs to EventDomainContext,
   * which wraps the whole per-instance send and restores the previous domain
   * afterwards — the old version mutated the negotiator from inside a getter
   * and left the last event's domain active for the rest of the request.
   */
  protected function getEventDomain($event_instance) {
    return $this->domainContext->resolveHostname($event_instance)
      ?? $this->requestStack->getCurrentRequest()->getHost();
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
        // Send in the instance's own domain context: the mail transport is
        // chosen from the active domain, and any link rendered through the
        // URL generator takes its host from the request context. Both are
        // restored afterwards, so one event's domain never leaks into the
        // next iteration.
        $this->domainContext->forEntity(
          $event_instance,
          fn () => $this->sendSurveyToRegistrants($event_instance, $entity_id, $now, 'post_survey', 'field_post_survey_sent')
        );

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
        // Sent in the instance's own domain context — see postSurveyEmail().
        $this->domainContext->forEntity(
          $event_instance,
          fn () => $this->sendSurveyToRegistrants($event_instance, $entity_id, $now, 'post_survey_reminder', 'field_post_survey_reminder_sent')
        );

        // Mark Event Instance as survey sent.
        $event_instance->field_post_survey_reminder_sent->value = 1;
        $event_instance->save();
      }
    }
  }

  /**
   * Mails one post-survey notice per not-yet-notified registrant.
   *
   * Shared by the initial send and the 3-day reminder: the two differ only in
   * the mail policy subtype and which "already sent" stamp on the registrant
   * gates and records the send.
   *
   * Always call this through EventDomainContext::forEntity() — the mail
   * transport is selected from the active domain, and the survey link is built
   * on the instance's own domain.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $event_instance
   *   The instance whose registrants to mail.
   * @param int|string $entity_id
   *   The instance id, as it appears in the survey URL.
   * @param int $now
   *   The request time, stamped on each registrant that was mailed.
   * @param string $policy_subtype
   *   The mail policy subtype ('post_survey' or 'post_survey_reminder').
   * @param string $sent_field
   *   The registrant field that gates and records this send.
   */
  protected function sendSurveyToRegistrants(EventInstance $event_instance, $entity_id, int $now, string $policy_subtype, string $sent_field): void {
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

      if ($registrant->get($sent_field)->value) {
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

      try {
        $this->mailService->email($policy, $policy_subtype, $email, $variables);
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('access_misc')
          ->error('Error sending post survey email to ' . $email . ': ' . $e->getMessage());
      }

      // Mark Registrant as survey sent.
      $registrant->set($sent_field, $now);
      $registrant->save();
    }
  }

}
