<?php

namespace Drupal\access_match_engagement\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Match.
 */
final class MatchController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The page cache kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * Constructs a MatchController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   The page cache kill switch.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    KillSwitch $kill_switch,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->killSwitch = $kill_switch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('messenger'),
      $container->get('logger.factory'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Build content to display on page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response back to the node.
   */
  public function interestedContent() {
    $nid = $this->routeMatch->getRawParameter('node');
    // Load entity node using node id.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if ($node->getType() == 'match_engagement' || $node->getType() == 'mentorship_engagement') {
      $current_user = $this->currentUser->id();
      $interested_users = $node->get('field_match_interested_users')->getValue();
      if (array_search($current_user, array_column($interested_users, 'target_id')) !== FALSE) {
        foreach ($interested_users as $key => $interested_user) {
          if ($interested_user['target_id'] == $current_user) {
            unset($interested_users[$key]);
          }
        }
        $node->set('field_match_interested_users', $interested_users);
        $node->save();
        $this->messenger->addStatus($this->t("You have been removed from the interested list"));
      }
      else {
        $interested_users[] = ['target_id' => $current_user];
        // Get current user.
        $current_user = $this->currentUser;
        $this->loggerFactory->get('access_match_engagement')->notice('User @current_user added to interested list', ['@current_user' => $current_user->getAccountName()]);
        if ($node->getType() == 'match_engagement') {
          $config = $this->configFactory->getEditable('access_match_engagement.settings');
          $config->set('interested', 1);
          $config->save();
        }
        if ($node->getType() == 'mentorship_engagement') {
          $interested_list = $this->state->get('access_mentorship_interested');
          $create_list = [];
          if (!empty($interested_list) && $interested_list !== '0') {
            $decoded = json_decode($interested_list, TRUE);
            if ($decoded !== NULL && is_array($decoded)) {
              $create_list = $decoded;
            }
          }
          if (!in_array($nid, $create_list)) {
            $create_list[] = $nid;
          }
          $interested_list = json_encode($create_list);
          $this->state->set('access_mentorship_interested', $interested_list);
        }
        // Update node field.
        $node->set('field_match_interested_users', $interested_users);
        $node->save();
        $this->messenger->addStatus($this->t("You have been added to the interested list"));
      }
    }
    $this->killSwitch->trigger();
    // Redirect to node.
    $url = Url::fromRoute('entity.node.canonical', ['node' => $nid]);
    return new RedirectResponse($url->toString());
  }

}
