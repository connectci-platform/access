<?php

namespace Drupal\access_misc\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\jsonapi\Events\JsonApiEvents;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves user email to UUID in JSON:API requests.
 */
class JsonApiEmailToUuidSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a JsonApiEmailToUuidSubscriber object.
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
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  /**
   * Resolves email to UUID in JSON:API POST/PATCH requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only process JSON:API requests.
    if (strpos($request->getPathInfo(), '/jsonapi/') !== 0) {
      return;
    }

    // Only process POST and PATCH requests.
    if (!in_array($request->getMethod(), ['POST', 'PATCH'])) {
      return;
    }

    $content = $request->getContent();
    if (empty($content)) {
      return;
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data'])) {
      return;
    }

    $modified = FALSE;

    // Check for uid relationship with email instead of UUID.
    if (isset($data['data']['relationships']['uid']['data'])) {
      $uid_data = &$data['data']['relationships']['uid']['data'];

      if (isset($uid_data['mail']) && !isset($uid_data['id'])) {
        $uuid = $this->getUserUuidByEmail($uid_data['mail']);
        if ($uuid) {
          $uid_data['id'] = $uuid;
          unset($uid_data['mail']);
          $modified = TRUE;
        }
      }
    }

    // Also check X-Acting-User header as fallback.
    $acting_user_email = $request->headers->get('X-Acting-User-Email');
    if ($acting_user_email && !isset($data['data']['relationships']['uid'])) {
      $uuid = $this->getUserUuidByEmail($acting_user_email);
      if ($uuid) {
        $data['data']['relationships']['uid'] = [
          'data' => [
            'type' => 'user--user',
            'id' => $uuid,
          ],
        ];
        $modified = TRUE;
      }
    }

    // Update the request content if modified.
    if ($modified) {
      $request->initialize(
        $request->query->all(),
        $request->request->all(),
        $request->attributes->all(),
        $request->cookies->all(),
        $request->files->all(),
        $request->server->all(),
        json_encode($data)
      );
    }
  }

  /**
   * Gets user UUID by email address.
   *
   * @param string $email
   *   The email address.
   *
   * @return string|null
   *   The user UUID or NULL if not found.
   */
  protected function getUserUuidByEmail(string $email): ?string {
    try {
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['mail' => $email]);

      if (!empty($users)) {
        $user = reset($users);
        return $user->uuid();
      }
    }
    catch (\Exception $e) {
      // Log error silently.
    }

    return NULL;
  }

}
