<?php

namespace Drupal\access_affinitygroup\Controller;

use Drupal\user\Entity\User;
use Drupal\access_affinitygroup\Plugin\SimpleListsApi;
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
class SimpleListController extends ControllerBase {

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
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   Kill switch.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    KillSwitch $kill_switch,
    RedirectDestinationInterface $redirect_destination,
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
   * Route to actions on Simplelist.
   */
  public function simplelist() {

    // Get last part of url.
    $path = \Drupal::service('path.current')->getPath(); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $path = explode('/', $path);
    $path = end($path);
    $param = \Drupal::request()->query->all(); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $node_id = explode('/', $param['redirect']);
    $node_id = end($node_id);
    $uid = $this->currentUser->id();

    // Invalidate cache for block.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['node:' . $node_id . ':user:' . $uid]); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

    // Setup SimpleListsApi.
    $simpleListsApi = new SimpleListsApi();
    $msg = "";
    $listName = $param['slug'] ? Xss::filter($param['slug']) : '';
    // Get current user email.
    $userEmail = $this->currentUser->getEmail();
    $user = User::load($this->currentUser->id()); // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $firstName = $user->get('field_user_first_name')->value;
    $lastName = $user->get('field_user_last_name')->value;
    if ($param['current'] == 'none') {
      // User is joining the list.
      $addSuccess = FALSE;
      $simplelistsId = $simpleListsApi->getUserIdFromEmail($userEmail, $msg);
      if ($simplelistsId) {
        $addCurrentUser = $simpleListsApi->updateUserToList($simplelistsId, $listName, $msg);
        if ($addCurrentUser) {
          \Drupal::messenger()->addStatus($msg); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          $addSuccess = TRUE;
        }
        else {
          \Drupal::messenger()->addError($msg ?: 'Failed to add you to the list.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        }
      }
      else {
        $addUser = $simpleListsApi->addUser($uid, $userEmail, $firstName, $lastName, $listName, $msg);
        if ($addUser) {
          \Drupal::messenger()->addStatus($msg); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          $addSuccess = TRUE;
        }
        else {
          \Drupal::messenger()->addError($msg ?: 'Failed to add you to the list.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        }
      }
      // Only set digest if user was successfully added to the list.
      if ($addSuccess && $path == 'daily') {
        $digest = 1;
        $set_digest = $simpleListsApi->setUserDigest($listName, $userEmail, $digest, $msg);
        if ($set_digest) {
          \Drupal::messenger()->addStatus($msg); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        }
        else {
          \Drupal::messenger()->addError($msg ?: 'Failed to set digest preference.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        }
      }
    }
    elseif ($path == 'none') {
      // User is leaving the list.
      $removeUser = $simpleListsApi->removeUserFromList($userEmail, $listName, $msg);
      if ($removeUser) {
        \Drupal::messenger()->addStatus($msg); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      }
      else {
        \Drupal::messenger()->addError($msg ?: 'Failed to remove you from the list.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      }
    }
    else {
      // User is changing digest settings.
      if ($path == 'daily') {
        $digest = 1;
      }
      if ($path == 'full') {
        $digest = 0;
      }

      // First, make sure user is subscribed to the list.
      $userStatus = $simpleListsApi->getUserListStatus($listName, $userEmail, $msg);
      if ($userStatus === 'none') {
        // User is not subscribed - subscribe them first.
        $addSuccess = FALSE;
        $simplelistsId = $simpleListsApi->getUserIdFromEmail($userEmail, $msg);
        if ($simplelistsId) {
          $addCurrentUser = $simpleListsApi->updateUserToList($simplelistsId, $listName, $msg);
          if ($addCurrentUser) {
            $addSuccess = TRUE;
          }
        }
        else {
          $addUser = $simpleListsApi->addUser($uid, $userEmail, $firstName, $lastName, $listName, $msg);
          if ($addUser) {
            $addSuccess = TRUE;
          }
        }

        if (!$addSuccess) {
          \Drupal::messenger()->addError($msg ?: 'Failed to subscribe you to the list.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          $this->killSwitch->trigger();
          $destination = $param['redirect']
            ? Xss::filter($param['redirect']) : '/';
          return new RedirectResponse($destination);
        }
      }

      // Now set the digest preference.
      $set_digest = $simpleListsApi->setUserDigest($listName, $userEmail, $digest, $msg);
      if ($set_digest) {
        \Drupal::messenger()->addStatus($msg); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      }
      else {
        \Drupal::messenger()->addError($msg ?: 'Failed to update digest preference.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      }
    }
    $this->killSwitch->trigger();
    // Get redirect destination from url.
    $destination = $param['redirect']
      ? Xss::filter($param['redirect']) : '/';
    // Redirect to destination - return the response so Drupal handles
    // it properly
    // This ensures messages are saved to session before redirect.
    return new RedirectResponse($destination);
  }

}
