<?php

namespace Drupal\access_badges\Controller;

use Drupal\access_badges\Plugin\BadgeTools;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for badge pending action routes.
 */
final class BadgePendingController extends ControllerBase {

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
   * Constructs a BadgePendingController.
   */
  public function __construct(Connection $database, BadgeTools $badge_tools, TimeInterface $time, RequestStack $request_stack) {
    $this->database = $database;
    $this->badgeTools = $badge_tools;
    $this->time = $time;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('access_badges.badgeTools'),
      $container->get('datetime.time'),
      $container->get('request_stack')
    );
  }

  /**
   * Redirects the Badge Assignments landing page to the Pending sub-tab.
   */
  public function badgeAssignments(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('access_badges.pending')->toString());
  }

  /**
   * Deletes a pending row.
   */
  public function delete(string $id): RedirectResponse {
    $this->database->delete('access_badges_pending')
      ->condition('id', $id)
      ->execute();
    $this->messenger()->addStatus($this->t('Pending row deleted.'));
    return new RedirectResponse(Url::fromRoute('access_badges.pending')->toString());
  }

  /**
   * Assigns a badge from an inline possible match.
   */
  public function assign(string $uid, string $badge_tid, string $vocabulary, string $email): RedirectResponse {
    $assigned = $this->badgeTools->assignBadgeToUser((int) $uid, (int) $badge_tid, $vocabulary);
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
  public function sendToPending(string $email, string $first_name, string $last_name, string $organization = ''): RedirectResponse {
    // This route requires badge_tid and vocabulary from the query string.
    $request = $this->requestStack->getCurrentRequest();
    $badge_tid = $request ? $request->query->get('badge_tid', 0) : 0;
    $vocabulary = $request ? $request->query->get('vocabulary', 'badges') : 'badges';

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
            'created' => $this->time->getRequestTime(),
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
