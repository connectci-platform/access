<?php

namespace Drupal\access_affinitygroup\Controller;

use Drupal\access_affinitygroup\Service\ProfileService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/1.0/profile/consent — persist the acting user's consent choice.
 *
 * Accepts only 'allow' or 'deny'. The ephemeral "allow once" is agent-side and
 * never persisted, so it is not a valid body here.
 */
class ProfileConsentController extends ControllerBase {

  /**
   * POST /api/1.0/profile/consent.
   *
   * Body {"consent":"allow"|"deny"} writes the field and returns
   * {"consent":"<value>"}. Any other value, non-JSON body, or missing key
   * returns 400.
   */
  public function post(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return new JsonResponse(['error' => 'No acting user.'], 403);
    }
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      throw new NotFoundHttpException();
    }

    $data = json_decode((string) $request->getContent(), TRUE);
    $value = is_array($data) ? ($data['consent'] ?? NULL) : NULL;
    if (!in_array($value, ['allow', 'deny'], TRUE)) {
      return new JsonResponse(['error' => "consent must be 'allow' or 'deny'"], 400);
    }

    $user->set(ProfileService::CONSENT_FIELD, $value);
    $user->save();

    return (new JsonResponse(['consent' => $value]))->setPrivate()->setMaxAge(0);
  }

}
