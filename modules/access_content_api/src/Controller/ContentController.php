<?php

namespace Drupal\access_content_api\Controller;

use Drupal\access_content_api\ContentEligibility;
use Drupal\access_content_api\TextExtractor;
use Drupal\access_content_api\LayoutWalker;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles /api/1.0/content endpoints.
 */
final class ContentController extends ControllerBase {

  const CACHE_MAX_AGE = 3600;

  public function __construct(
    protected AliasManagerInterface $aliasManager,
    protected TextExtractor $textExtractor,
    protected LayoutWalker $layoutWalker,
    protected ContentEligibility $eligibility,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('path_alias.manager'),
      $container->get('access_content_api.text_extractor'),
      $container->get('access_content_api.layout_walker'),
      $container->get('access_content_api.eligibility'),
    );
  }

  /**
   * Returns content JSON for a node by numeric ID.
   */
  public function byId(Request $request, int $id): Response {
    $node = $this->entityTypeManager()->getStorage('node')->load($id);
    // The {id} route param is already part of the page cache key, so no extra
    // cache context is needed to keep per-node responses distinct.
    return $this->buildResponse($request, $node);
  }

  /**
   * Returns content JSON for a node looked up by path alias query param.
   */
  public function byPath(Request $request): Response {
    $path = $request->query->get('path', '');
    if (!$path) {
      return $this->notFound();
    }

    $systemPath = $this->aliasManager->getPathByAlias($path);
    if ($systemPath === $path && !str_starts_with($path, '/node/')) {
      return $this->notFound();
    }

    if (!preg_match('#^/node/(\d+)$#', $systemPath, $m)) {
      return $this->notFound();
    }

    $node = $this->entityTypeManager()->getStorage('node')->load((int) $m[1]);
    // This route reads the node from the ?path query arg, which is NOT part of
    // the default page cache key, so the response must vary by it or the cache
    // would serve one path's content for another.
    return $this->buildResponse($request, $node, ['url.query_args:path']);
  }

  /**
   * Builds a cacheable JSON response for a node, or a 404.
   *
   * @param string[] $extraCacheContexts
   *   Additional cache contexts the calling route requires.
   */
  private function buildResponse(Request $request, mixed $node, array $extraCacheContexts = []): Response {
    if (!$node instanceof NodeInterface) {
      return $this->notFound();
    }

    if (!$node->isPublished()) {
      return $this->notFound();
    }

    if (!$this->eligibility->hasTextViewMode($node->bundle())) {
      return $this->notFound();
    }

    if (!$this->eligibility->isOnSupportDomain($node)) {
      return $this->notFound();
    }

    // Enforce node-access grants as the anonymous user, so this endpoint never
    // serves content that an anonymous visitor (and the access-checked index)
    // could not see. load() does not access-check, so this must be explicit.
    if (!$node->access('view', $this->anonymousUser())) {
      return $this->notFound();
    }

    $nid = $node->id();
    $changed = $node->getChangedTime();
    $etag = '"' . $nid . '-' . $changed . '"';

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['node:' . $nid]);
    $cacheMetadata->setCacheMaxAge(self::CACHE_MAX_AGE);
    $cacheMetadata->setCacheContexts(array_merge(['user.roles:anonymous'], $extraCacheContexts));

    if ($request->headers->get('If-None-Match') === $etag) {
      // Carry the same cache metadata as the 200 so cache layers vary the 304
      // identically (notably url.query_args:path on the byPath route).
      $response = new CacheableResponse('', 304);
      $response->headers->set('ETag', $etag);
      $response->addCacheableDependency($cacheMetadata);
      return $response;
    }

    $alias = $this->aliasManager->getAliasByPath('/node/' . $nid);
    $url = $this->eligibility->supportDomainUrl($alias);

    $html = $this->layoutWalker->render($node, $cacheMetadata);
    $text = $this->textExtractor->extract($html);

    $data = [
      'version' => 1,
      'id' => (int) $nid,
      'title' => $node->label(),
      'path' => $url,
      'content_type' => $node->bundle(),
      'last_modified' => date('c', $changed),
      // Hash of the extracted text so consumers can skip re-embedding pages
      // whose content is unchanged even when last_modified shifts.
      'content_hash' => hash('sha256', $text),
      'text' => $text,
    ];

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cacheMetadata);
    $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $changed) . ' GMT');
    $response->headers->set('ETag', $etag);

    return $response;
  }

  /**
   * Returns the anonymous user account for access checks.
   */
  private function anonymousUser(): AccountInterface {
    return new AnonymousUserSession();
  }

  /**
   * Returns a 404 JSON response.
   */
  private function notFound(): Response {
    return new Response(json_encode(['error' => 'Not Found']), 404, ['Content-Type' => 'application/json']);
  }

}
