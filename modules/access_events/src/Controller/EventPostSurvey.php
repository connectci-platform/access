<?php

namespace Drupal\access_events\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;

/**
 * Controller for forwarding post survey clicks.
 */
class EventPostSurvey extends ControllerBase {

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
   * Route to forward to post survey.
   */
  public function clicked(AccountInterface $user, Request $request) {
    $entity_id = is_numeric($request->attributes->get('entity')) ? $request->attributes->get('entity') : 0;
    $user_id = is_numeric($request->attributes->get('user')->id()) ? $request->attributes->get('user')->id() : 0;

    $event_instance = $this->entityTypeManager->getStorage('eventinstance')->load($entity_id);
    $entity_series = $event_instance->getEventSeries();
    $post_survey_url = $entity_series->field_post_survey_url->uri;

    // Check registration exists.
    $entity_query = \Drupal::entityQuery('registrant'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $entity_query->accessCheck(FALSE);
    $entity_query->condition('eventinstance_id', $entity_id);
    $entity_query->condition('user_id', $user_id);
    $registrants = $entity_query->execute();

    // Get id of registrant, there should only be one.
    $registrant_id = reset($registrants);

    if ($registrants) {
      $registrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrant_id); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $registrant->set('field_post_survey_reminder_sent', \Drupal::time()->getRequestTime()); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $registrant->save();
    }
    else {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('You are not registered for this event.'),
      ];
    }

    return new TrustedRedirectResponse($post_survey_url);
  }

}
