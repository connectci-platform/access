<?php

namespace Drupal\access_affinitygroup\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block of Top Tags from Ask.CI.
 *
 * @Block(
 *   id = "Top Tags from Ask.CI",
 *   admin_label = "Top Tags from Ask.CI",
 * )
 */
class TopAskCiTags extends BlockBase implements
  ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Invoke renderer.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatchInterface;

  /**
   * The http_client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Container pulled in.
   * @param array $configuration
   *   Configuration added.
   * @param string $plugin_id
   *   Plugin_id added.
   * @param mixed $plugin_definition
   *   Plugin_definition added.
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('http_client'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Construct object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match_interface,
    Client $http_client,
    LoggerChannelFactoryInterface $logger_interface,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatchInterface = $route_match_interface;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_interface->get('access_askci');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $html = [];
    // Api call for grabbing the latest from Ask.CI Discourse.
    $latest = "https://ask.cyberinfrastructure.org/latest.json";
    $client = $this->httpClient;
    try {
      $request = $client->get($latest);
      $result = $request->getBody()->getContents();
    }
    catch (RequestException $e) {
      $this->loggerFactory->error($e);
    }
    $result = json_decode($result);
    $tags = $result->topic_list->top_tags;
    $tags = array_slice($tags, 0, 10);
    $output = '<ul class="flex flex-wrap">';
    foreach ($tags as $tag) {
      $options = [
        'attributes' => [
          'class' => [
            'me-2', 'md--me-4', 'mb-2', 'px-2', 'py-1', 'font-normal', 'no-underline', 'border', 'border-black', 'border-solid', 'hover--border-dark-teal', 'hover--text-dark-teal', 'w-fit', 'h-fit', 'leading-tight',
          ],
        ],
      ];
      $url = Url::fromUri("https://ask.cyberinfrastructure.org/tag/$tag", $options);
      $external_link = Link::fromTextAndUrl($tag, $url)->toString();
      $output .= "<li class='ps-0 mt-0 list-none'>$external_link</li>";
    }
    $output .= '</ul>';
    $ask_title = $this->t('Popular tags on Ask.CI');
    $ask_title = "<h4 class='mt-8'>$ask_title</h4>";

    $html['ask-ci'] = [
      '#prefix' => $ask_title,
      '#markup' => $output,
      // Expire in one day in seconds.
      '#cache' => ["max-age" => 86400],
    ];

    return $html;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if ($node = $this->routeMatchInterface->getParameter('node')) {
      return Cache::mergeTags(parent::getCacheTags(), ['node:' . $node->id()]);
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
