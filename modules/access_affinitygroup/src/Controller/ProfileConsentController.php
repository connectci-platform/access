<?php

namespace Drupal\access_affinitygroup\Controller;

use Drupal\access_affinitygroup\Service\ProfileService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/1.0/profile/consent: records the acting user's consent answer.
 *
 * Accepts 'allow', 'deny', or 'not_now'. Allow-once is the assistant's own
 * per-conversation state and is never stored, so it is not a value here.
 */
final class ProfileConsentController extends ControllerBase {

  public function __construct(
    private readonly ProfileService $profile,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('access_affinitygroup.profile'),
      $container->get('lock'),
    );
  }

  /**
   * POST /api/1.0/profile/consent.
   *
   * Body {"consent": "allow"|"deny"} stores the choice. {"consent": "not_now"}
   * records that the user was asked and leaves consent unset; it is a 409
   * when the user has already chosen allow or deny. Any other body is a 400.
   * Responds with the effective {"consent", "asked_at"}.
   */
  public function post(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return new JsonResponse(['error' => 'No acting user.'], 403);
    }

    $data = json_decode((string) $request->getContent(), TRUE);
    $value = is_array($data) ? ($data['consent'] ?? NULL) : NULL;
    if (!in_array($value, ['allow', 'deny', 'not_now'], TRUE)) {
      return new JsonResponse(['error' => "consent must be 'allow', 'deny', or 'not_now'"], 400);
    }

    // One answer at a time per user, read fresh inside the lock: a not_now
    // checked against a stale copy could otherwise overwrite a deny saved
    // in between.
    $name = 'access_affinitygroup_consent:' . $uid;
    if (!$this->lock->acquire($name, 10.0)) {
      // wait() is TRUE when the lock is still held after waiting.
      if ($this->lock->wait($name, 10) || !$this->lock->acquire($name, 10.0)) {
        return (new JsonResponse(['error' => 'Another consent change is in progress.'], 503))
          ->setPrivate()->setMaxAge(0);
      }
    }
    try {
      $user = $this->entityTypeManager()->getStorage('user')->loadUnchanged($uid);
      if (!$user instanceof UserInterface) {
        throw new NotFoundHttpException();
      }
      if ($value === 'not_now') {
        if ($this->profile->consentState($user) !== 'unset') {
          return (new JsonResponse(['error' => 'Consent has already been chosen.'], 409))
            ->setPrivate()->setMaxAge(0);
        }
        $this->profile->recordNotNow($user);
      }
      else {
        $this->profile->recordChoice($user, $value);
      }
      return (new JsonResponse([
        'consent' => $this->profile->consentState($user),
        'asked_at' => $this->profile->askedAt($user),
      ]))->setPrivate()->setMaxAge(0);
    }
    finally {
      $this->lock->release($name);
    }
  }

}
