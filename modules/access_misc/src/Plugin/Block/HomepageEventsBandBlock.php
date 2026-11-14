<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\access_misc\Services\OfficeHoursLookup;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the ACCESS Support homepage "Training & events" band.
 *
 * @Block(
 *   id = "access_homepage_events_band",
 *   admin_label = @Translation("Homepage events band (Training & events)"),
 *   category = @Translation("Custom"),
 * )
 */
class HomepageEventsBandBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Longest time the "Next:" office hours dates may be cached, in seconds.
   */
  const MAX_AGE = 3600;

  /**
   * Constructs the block.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\access_misc\Services\OfficeHoursLookup $officeHours
   *   The office hours lookup.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected OfficeHoursLookup $officeHours, protected TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // @phpstan-ignore new.static
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('access_misc.office_hours_lookup'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array for the homepage-events-band component.
   */
  public function build() {
    $sessions = $this->officeHours->getNext(2);

    // The "Next:" dates go stale once the first session starts, so expire the
    // cache then (capped at an hour).
    $maxAge = self::MAX_AGE;
    if ($sessions) {
      $maxAge = max(1, min($maxAge, $sessions[0]['start'] - $this->time->getRequestTime()));
    }

    return [
      '#type' => 'component',
      '#component' => 'access_misc:homepage-events-band',
      '#props' => [
        'events_url' => Url::fromUserInput('/events')->toString(),
        'announcements_url' => Url::fromUserInput('/announcements')->toString(),
        'post_announcement_url' => Url::fromUserInput('/node/add/access_news')->toString(),
        'office_hours_url' => Url::fromUserInput('/access-and-nairr-pilot-office-hours')->toString(),
        'office_hours_next' => array_column($sessions, 'label'),
        // Same destinations as the "Office Hours" menu.
        'open_ondemand_url' => Url::fromUserInput('/events', ['query' => ['f' => ['affinity_group:Open OnDemand']]])->toString(),
        'pegasus_url' => Url::fromUserInput('/events', ['query' => ['f' => ['affinity_group:Pegasus']]])->toString(),
        'webinars_url' => Url::fromUserInput('/knowledge-base/nairr-pilot-webinars')->toString(),
        'video_learning_center_url' => Url::fromUserInput('/video-learning-center')->toString(),
      ],
      '#slots' => [
        // The displays are NULL (render nothing) until they exist.
        'events' => views_embed_view('recurring_events_event_instances', 'homepage_events') ?? [],
        'announcements' => views_embed_view('access_news', 'homepage_announcements') ?? [],
      ],
      '#cache' => [
        'tags' => ['eventinstance_list', 'eventseries_list'],
        'max-age' => $maxAge,
      ],
    ];
  }

}
