<?php

namespace Drupal\access_affinitygroup\Controller;

use Drupal\access_affinitygroup\Service\ProfileService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/1.0/profile — the consented PRIME profile for the acting user.
 */
final class ProfileController extends ControllerBase {

  public function __construct(
    private readonly ProfileService $profile,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('access_affinitygroup.profile'));
  }

  /**
   * GET /api/1.0/profile[?once=1].
   *
   * Always returns the effective {"consent", "asked_at"}. The profile fields
   * are added when consent is allow, or when once=1 and the user has not
   * chosen yet (the assistant's one-time use). once=1 never overrides deny.
   * Named get (not getProfile) to mirror RpAccountController's single-action
   * naming.
   */
  public function get(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return new JsonResponse(['error' => 'No acting user.'], 403);
    }
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      throw new NotFoundHttpException();
    }

    $consent = $this->profile->consentState($user);
    $payload = [
      'consent' => $consent,
      'asked_at' => $this->profile->askedAt($user),
    ];
    $once = $request->query->get('once') === '1';
    if ($consent === 'allow' || ($once && $consent === 'unset')) {
      $payload += $this->profile->primeProfile($user);
    }

    return (new JsonResponse($payload))->setPrivate()->setMaxAge(0);
  }

}
