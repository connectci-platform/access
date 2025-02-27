<?php

namespace Drupal\access_events\Controller;

use Drupal\user\Entity\User;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Event Waitlist.
 */
class EventWaitlist extends ControllerBase {

  /**
   * Perform redirect.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * ID's of registrants.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $registrantIds;

  /**
   * The event instance id from uri.
   *
   * @var int
   */
  protected $eventInstanceId;

  /**
   * The event original url.
   *
   * @var string
   */
  protected $eventRegistrationUrl;

  /**
   * Constructs request stuff.
   *
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    RedirectDestinationInterface $redirect_destination,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->redirectDestination = $redirect_destination;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;

    // Get uri.
    $uri = $this->redirectDestination->get();
    $uri = explode('/', $uri);
    $this->eventInstanceId = $uri[2];
    $this->eventRegistrationUrl = '/' . $uri[1] . '/' . $uri[2] . '/registrations';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('redirect.destination'),
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Route to approve user.
   */
  public function approve() {
    $this->status(1);
    $this->registerApproveEmail();
    $this->addAllocation();

    // Clear cache eventinstance to reset block.
    Cache::invalidateTags(['eventinstance:' . $this->eventInstanceId]);

    return new RedirectResponse($this->eventRegistrationUrl);
  }

  /**
   * Route to unapprove user.
   */
  public function unapprove() {
    $this->status(0);
    $this->removeAllocation();

    // Clear cache eventinstance to reset block.
    Cache::invalidateTags(['eventinstance:' . $this->eventInstanceId]);

    return new RedirectResponse($this->eventRegistrationUrl);
  }

  /**
   * Add user grant allocation.
   */
  private function addAllocation() {
    $username = [$this->currentUsername()];

    $eventinstance_id = $this->eventInstanceId;
    $eventinstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($eventinstance_id);
    $eventseries = $eventinstance->getEventSeries();
    $grant = $eventseries->get('field_event_allocation_grant')->value;

    if ($grant == 0) {
      return;
    }

    \Drupal::service('access_events.XsedeApi')->setGrantedUsers($grant, $username);
  }

  /**
   * Remove user grant allocation.
   */
  private function removeAllocation() {
    $username = [$this->currentUsername()];

    $eventinstance_id = $this->eventInstanceId;
    $eventinstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($eventinstance_id);
    $eventseries = $eventinstance->getEventSeries();
    $grant = $eventseries->get('field_event_allocation_grant')->value;

    if ($grant == 0) {
      return;
    }

    \Drupal::service('access_events.XsedeApi')->removeGrantedUsers($grant, $username);

  }

  /**
   * Get current username.
   */
  private function currentUsername() {
    $account = \Drupal::currentUser();
    $user = User::load($account->id());
    $username = $user->getAccountName();
    $username = str_replace('@access-ci.org', '', $username);
    return $username;
  }

  /**
   * Approved Email.
   */
  private function registerApproveEmail() {
    $event_instance_id = $this->eventInstanceId;
    // Entity load eventinctance by id.
    $event_instance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($event_instance_id);
    $series = $event_instance->getEventSeries();
    $series_title = $series->get('title')->value;
    $series_location = $series->get('field_location')->value;
    $location = $series_location ? $series_location : '';
    $og_start_date = $event_instance->get('date')->start_date->__toString();
    $end_date = $event_instance->get('date')->end_date->__toString();
    $start_date = date('F j, Y', strtotime($og_start_date));
    $event_start_time = date('g:iA', strtotime($og_start_date));
    $event_end_time = date('g:iA T', strtotime($end_date));

    // Turn $series_title into a link to the event.
    $series_title_url = "<a href='/events/$event_instance_id'>$series_title</a>";

    $policy = 'access_misc';
    $policy_subtype = 'registration_approved';

    // Get list of unique emails.
    $variables = [
      'title' => $series_title,
      'title_link' => $series_title_url,
      'start_date' => $start_date,
      'event_start_time' => $event_start_time,
      'event_end_time' => $event_end_time,
      'name' => '',
      'location' => $location,
    ];

    foreach ($this->registrantIds as $registrant_id) {
      $registrant = $this->entityTypeManager->getStorage('registrant')->load($registrant_id);
      $email = $registrant->get('email')->getValue();
      $first_name = $registrant->get('field_first_name')->getValue();
      $last_name = $registrant->get('field_last_name')->getValue();
      $variables['name'] = $first_name[0]['value'] . ' ' . $last_name[0]['value'];

      \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $email[0]['value'], $variables);
    }

  }

  /**
   * Set status.
   */
  private function status($status) {
    $eventinstance_id = is_numeric($this->eventInstanceId) ? $this->eventInstanceId : 0;

    $url = $this->redirectDestination->get();
    if (strpos($url, '?')) {
      $query = explode('?', $url);
      $query = explode('=', $query[1]);
      $query = [
        'reg_id' => $query[1],
      ];
    }

    $reg_id = 0;

    if (strpos($url, '?')) {
      if (array_key_exists('reg_id', $query)) {
        $reg_id = is_numeric($query['reg_id']) ? $query['reg_id'] : 0;
      }
    }

    $opposite_status = $status === 1 ? 0 : 1;

    // Entity query get all registrant id with 'eventseries_id' that equals
    // to $eventinstance_id.
    $registrant_entity = $this->entityTypeManager->getStorage('registrant');
    $entity_query = $registrant_entity->getQuery()
      ->condition('eventinstance_id', $eventinstance_id)
      ->condition('status', $opposite_status)
      ->accessCheck(FALSE);
    if ($reg_id) {
      $entity_query->condition('id', $reg_id);
    }
    $this->registrantIds = $entity_query->execute();

    foreach ($this->registrantIds as $registrant_id) {
      $registrant = $this->entityTypeManager->getStorage('registrant')->load($registrant_id);
      $waitlist = $registrant->get('waitlist')->getValue()[0];

      if ($waitlist['value'] == 1) {
        $registrant->set('waitlist', 0);
      }

      $registrant->set('status', $status);
      $registrant->save();
    }

    // Invalidate cache on Events Facet view.
    $cache_tags = ['config:views.view.events_facet'];
    Cache::invalidateTags($cache_tags);
  }

  /**
   * Give access to author or administrator.
   */
  public function isAuthor() {
    $account = \Drupal::currentUser();
    // Get current uri.
    $current_uri = \Drupal::service('path.current')->getPath();
    $url_bits = explode('/', $current_uri);
    $event_id = is_numeric($url_bits[2]) ? $url_bits[2] : 0;

    $eventinstance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($event_id);
    $eventseries = $eventinstance->getEventSeries();
    // Get author of event series.
    $author = $eventseries->getOwner();

    if ($author->id() == $account->id()) {
      return AccessResult::allowed();
    }

    if ($account->hasPermission('administer registrant types')) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

}
