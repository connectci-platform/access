<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\flag\Event\FlagEvents;
use Drupal\flag\Event\FlaggingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\access_misc\Plugin\Util\SiteTools;
use Drupal\access_misc\Plugin\Util\UserTools;
use Drupal\access_misc\Services\SymfonyMail;

/**
 * Event subscriber for project flag events.
 */
class ProjectFlagSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The site tools service.
   *
   * @var \Drupal\access_misc\Plugin\Util\SiteTools
   */
  protected $siteTools;

  /**
   * The user tools service.
   *
   * @var \Drupal\access_misc\Plugin\Util\UserTools
   */
  protected $userTools;

  /**
   * The symfony mail service.
   *
   * @var \Drupal\access_misc\Services\SymfonyMail
   */
  protected $symfonyMail;

  /**
   * Constructs a new ProjectFlagSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\access_misc\Plugin\Util\SiteTools $site_tools
   *   The site tools service.
   * @param \Drupal\access_misc\Plugin\Util\UserTools $user_tools
   *   The user tools service.
   * @param \Drupal\access_misc\Services\SymfonyMail $symfony_mail
   *   The symfony mail service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
    SiteTools $site_tools,
    UserTools $user_tools,
    SymfonyMail $symfony_mail,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
    $this->siteTools = $site_tools;
    $this->userTools = $user_tools;
    $this->symfonyMail = $symfony_mail;
  }

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
      $domain = $this->siteTools->getProgram();
      $domain = is_array($domain) ? implode(',', $domain) : $domain;
      if ($domain == 933) {
        $policy = 'access_misc_project';
        $policy_subtype = 'project_flagged';
        // Lookup webform submission.
        $webform_submission = $this->entityTypeManager
          ->getStorage('webform_submission')
          ->load($entity_sid);
        // Get field 'project_title' of submission.
        $title = $webform_submission->getData()['project_title'];
        $author_uid = $webform_submission->getOwnerId();
        $author_email = $this->entityTypeManager
          ->getStorage('user')
          ->load($author_uid)
          ->getEmail();

        // Create url 'host/webform/project/submissions/$entity_id/edit'.
        $request = $this->requestStack->getCurrentRequest();
        $host = $request->getSchemeAndHttpHost();
        $url = $host . '/webform/project/submissions/' . $entity_sid . '/edit';

        // Get id of user flagging the project.
        $flagged_by = $flagging->getOwnerId();
        $user = $this->entityTypeManager
          ->getStorage('user')
          ->load($flagged_by);

        $field_user_first_name = $user->get('field_user_first_name')->value;
        $field_user_last_name = $user->get('field_user_last_name')->value;
        $fullname = $field_user_first_name . ' ' . $field_user_last_name;

        $role = 'pascience_manager';

        $to_email = $this->userTools->getEmails([$role], []);

        $variables = [
          'title' => $title,
          'flagged_name' => $fullname,
          'url' => $url,
        ];

        if ($to_email) {
          // Send to all managers.
          $this->symfonyMail->email($policy, $policy_subtype, $to_email, $variables);
        }
        if ($author_email) {
          // Send to author.
          $this->symfonyMail->email($policy, $policy_subtype, $author_email, $variables);
        }
      }

    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[FlagEvents::ENTITY_FLAGGED][] = ['onFlag'];
    return $events;
  }

}
