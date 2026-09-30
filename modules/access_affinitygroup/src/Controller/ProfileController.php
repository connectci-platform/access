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
class ProfileController extends ControllerBase {

  public function __construct(
    private readonly ProfileService $profile,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('access_affinitygroup.profile'));
  }

  /**
   * GET /api/1.0/profile.
   *
   * Returns {"consent": "allow", ...prime fields} when the acting user has
   * consented; otherwise just {"consent": "unset"|"deny"}. Named get (not
   * getProfile) to mirror RpAccountController's single-action naming.
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
    $payload = ['consent' => $consent];
    if ($consent === 'allow') {
      $payload += $this->profile->primeProfile($user);
    }

    return (new JsonResponse($payload))->setPrivate()->setMaxAge(0);
  }

}
