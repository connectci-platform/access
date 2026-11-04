<?php

namespace Drupal\access_misc\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keeps non-public files out of JSON:API.
 *
 * The file--file resource is allowlisted so media logos and image fields
 * resolve. Core's file "view" access for a private file follows the entities
 * that reference it: user field_cv_resume files are viewable by anyone with
 * "access user profiles", anonymous included. Through JSON:API that would let
 * /jsonapi/file/file enumerate private resume filenames and URLs.
 *
 * Nothing served over JSON:API needs a non-public file, so on JSON:API routes
 * "view" of any file not on public:// is forbidden. Downloads (the "download"
 * operation) and rendered pages are unaffected.
 */
class JsonApiPrivateFileAccess {

  /**
   * Route default JSON:API sets on all of its routes.
   *
   * Mirrors \Drupal\jsonapi\Routing\Routes::JSON_API_ROUTE_FLAG_KEY, as a
   * literal so this class does not depend on the jsonapi module.
   */
  const JSON_API_ROUTE_FLAG_KEY = '_is_jsonapi';

  public function __construct(
    protected RequestStack $requestStack,
  ) {}

  /**
   * Checks access to a file entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param string $operation
   *   The operation.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Forbidden for "view" of a non-public file on a JSON:API route, else
   *   neutral.
   */
  public function access(FileInterface $file, string $operation): AccessResultInterface {
    $result = AccessResult::neutral()->addCacheContexts(['route']);
    if ($operation !== 'view') {
      return $result;
    }
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->attributes->get(self::JSON_API_ROUTE_FLAG_KEY)) {
      return $result;
    }
    if (StreamWrapperManager::getScheme($file->getFileUri()) === 'public') {
      return $result;
    }
    return AccessResult::forbidden('Non-public files are not exposed over JSON:API.')
      ->addCacheContexts(['route'])
      ->addCacheableDependency($file);
  }

}
