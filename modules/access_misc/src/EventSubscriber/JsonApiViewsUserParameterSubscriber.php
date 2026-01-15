<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves X-Acting-User header to user ID for JSON:API views.
 */
class JsonApiViewsUserParameterSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a JsonApiViewsUserParameterSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 99],
    ];
  }

  /**
   * Resolves X-Acting-User to user ID in JSON:API views requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only process JSON:API views requests.
    if (strpos($request->getPathInfo(), '/jsonapi/views/') !== 0) {
      return;
    }

    // Only process GET requests.
    if ($request->getMethod() !== 'GET') {
      return;
    }

    // Check for X-Acting-User header.
    $acting_user = $request->headers->get('X-Acting-User');
    if (!$acting_user) {
      return;
    }

    // Resolve to user ID.
    $uid = $this->getUserIdByEmailOrUsername($acting_user);
    if (!$uid) {
      return;
    }

    // Set views-argument[0] to override the default current_user argument.
    // We always set it even if already present, as the header takes precedence.
    $query = $request->query->all();
    $query['views-argument'][0] = $uid;
    $request->query->replace($query);
  }

  /**
   * Gets user ID by email address or username.
   *
   * @param string $identifier
   *   The email address or username.
   *
   * @return int|null
   *   The user ID or NULL if not found.
   */
  protected function getUserIdByEmailOrUsername(string $identifier): ?int {
    try {
      // Try email first.
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['mail' => $identifier]);

      // Try username if email didn't match.
      if (empty($users)) {
        $users = $this->entityTypeManager
          ->getStorage('user')
          ->loadByProperties(['name' => $identifier]);
      }

      if (!empty($users)) {
        $user = reset($users);
        return (int) $user->id();
      }
    }
    catch (\Exception $e) {
      // Log error silently.
    }

    return NULL;
  }

}
