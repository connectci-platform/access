<?php

namespace Drupal\access_badges\Controller;

use Drupal\access_badges\Plugin\BadgeTools;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for badge pending action routes.
 */
class BadgePendingController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The badge tools service.
   *
   * @var \Drupal\access_badges\Plugin\BadgeTools
   */
  protected $badgeTools;

  /**
   * Constructs a BadgePendingController.
   */
  public function __construct(Connection $database, BadgeTools $badge_tools) {
    $this->database = $database;
    $this->badgeTools = $badge_tools;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('access_badges.badgeTools')
    );
  }

  /**
   * Deletes a pending row.
   */
  public function delete($id) {
    $this->database->delete('access_badges_pending')
      ->condition('id', $id)
      ->execute();
    $this->messenger()->addStatus($this->t('Pending row deleted.'));
    return new RedirectResponse(Url::fromRoute('access_badges.pending')->toString());
  }

  /**
   * Assigns a badge from an inline possible match.
   */
  public function assign($uid, $badge_tid, $vocabulary, $email) {
    $assigned = $this->badgeTools->assignBadgeToUser($uid, $badge_tid, $vocabulary);
    if ($assigned) {
      $this->messenger()->addStatus($this->t('Badge assigned successfully.'));
    }
    else {
      $this->messenger()->addStatus($this->t('User already has this badge.'));
    }

    // Remove any pending row for this email + badge.
    $this->database->delete('access_badges_pending')
      ->condition('email', $email)
      ->condition('badge_tid', $badge_tid)
      ->execute();

    return new RedirectResponse(Url::fromRoute('access_badges.upload')->toString());
  }

  /**
   * Sends an inline possible match to the pending queue.
   */
  public function sendToPending($email, $first_name, $last_name, $organization = '') {
    // This route requires badge_tid and vocabulary from the query string.
    $request = \Drupal::request();
    $badge_tid = $request->query->get('badge_tid', 0);
    $vocabulary = $request->query->get('vocabulary', 'badges');

    if (!empty($email) && !empty($badge_tid)) {
      // Check for duplicate.
      $exists = $this->database->select('access_badges_pending', 'p')
        ->condition('p.email', $email)
        ->condition('p.badge_tid', $badge_tid)
        ->countQuery()
        ->execute()
        ->fetchField();

      if (!$exists) {
        $this->database->insert('access_badges_pending')
          ->fields([
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'organization' => $organization,
            'badge_tid' => $badge_tid,
            'vocabulary' => $vocabulary,
            'created' => \Drupal::time()->getRequestTime(),
            'status' => 'pending',
          ])
          ->execute();
        $this->messenger()->addStatus($this->t('Added to pending queue.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Already in pending queue.'));
      }
    }

    return new RedirectResponse(Url::fromRoute('access_badges.upload')->toString());
  }

}
