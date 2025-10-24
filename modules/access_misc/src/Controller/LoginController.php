<?php

namespace Drupal\access_misc\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Match.
 */
class LoginController extends ControllerBase {

  /**
   * Check user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Perform redirect.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * Page cache kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * Constructs request stuff.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   Used to get current active user.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   Kill switch.
   */
  public function __construct(AccountProxyInterface $current_user,
                              KillSwitch $kill_switch,
                              RedirectDestinationInterface $redirect_destination
  ) {
    $this->currentUser = $current_user;
    $this->redirectDestination = $redirect_destination;
    $this->killSwitch = $kill_switch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('page_cache_kill_switch'),
      $container->get('redirect.destination')
    );
  }

  /**
   * Route user to login.
   */
  public function login() {
    // Entity query on 'entityseries' where 'field_pre_survey_url' is not empty.
    $entity_query = \Drupal::entityQuery('eventinstance');
    $entity_query->accessCheck(FALSE);
    $entity_query->condition('field_post_survey_sent', 0);
    $result = $entity_query->execute();

    foreach ($result as $entity_id) {
      $event_instance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($entity_id);

      $end_date = $event_instance->date->end_value;
      $end_date = strtotime($end_date);
      $before_end = $end_date - (30 * 60);
      $now = time();

      //if ($before_end <= $now) {
      if ($before_end >= $now) {
        $policy = 'access_misc';

        $entity_query = \Drupal::entityQuery('registrant');
        $entity_query->accessCheck(FALSE);
        $entity_query->condition('eventinstance_id', $entity_id);
        $registrants = $entity_query->execute();

        $series = $event_instance->getEventSeries();
        $series_title = $series->get('title')->value;
        $series_title_url = \Drupal::service('access_misc.sitetools')->getEventCurrentDomainUrl($entity_id);
        $series_post_survey_url = $series->get('field_post_survey_url')->uri;
        $series_post_survey_text = $series->get('field_post_survey_email_text')->value;
        kint($series_title_url);

        foreach ($registrants as $registrant_id) {
          $registrant = \Drupal::entityTypeManager()->getStorage('registrant')->load($registrant_id);

          if ($registrant->field_post_survey_sent->value == 1) {
            continue;
          }

          $name = $registrant->field_first_name->value . ' ' . $registrant->field_last_name->value;
          $email = $registrant->title->value;

          // Get list of unique emails.
          $variables = [
            'title' => $series_title,
            'name' => $name,
            'title_link' => $series_title_url,
            'post_survey_text' => $series_post_survey_text,
            'post_survey_url' => $series_post_survey_url,
          ];

          $policy_subtype = 'post_survey';
          try {
            \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $email, $variables);
          }
          catch (\Exception $e) {
            \Drupal::logger('access_misc')
              ->error('Error sending post survey email to ' . $email . ': ' . $e->getMessage());
          }

          // Mark Registrant as survey sent.
          $registrant->field_post_survey_sent->value = 1;
          $registrant->save();

        }

        // Mark Event Instance as survey sent.
        $event_instance->field_post_survey_sent->value = 1;
        $event_instance->save();
      }
    }

    kint( $result );
    die();
    $this->killSwitch->trigger();
    // Check if user is logged in.
    if ($this->currentUser->isAuthenticated()) {
      // Get redirect destination from url.
      $destination = $this->redirectDestination->get() ? Xss::filter($this->redirectDestination->get()) : '';
      if (empty($destination) || str_starts_with($destination, '/login')) {
        // Get destination url query.
        $query = \Drupal::request()->query->all();
        $redirect = $query['redirect'] ?? '';
        $destination = '/';

        if (!empty($redirect)) {
          // If redirect is set, use it.
          $destination = Xss::filter($redirect);
        }
      }
      // Redirect to destination.
      $response = new RedirectResponse($destination);
      $response->send();
      return [
        '#type' => 'markup',
        '#markup' => "👋 " . $this->t("You shouldn't see this."),
      ];
    }
    return [
      '#type' => 'markup',
      '#markup' => "👋 " . $this->t("You shouldn't see this."),
    ];
  }

}
