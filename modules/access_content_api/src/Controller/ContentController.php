<?php

namespace Drupal\access_content_api\Controller;

use Drupal\access_content_api\TextExtractor;
use Drupal\access_content_api\LayoutWalker;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles /api/1.0/content endpoints.
 */
final class ContentController extends ControllerBase {

  const SUPPORT_DOMAIN_ID = 'amp_cyberinfrastructure_org';
  const TEXT_VIEW_MODE = 'text';
  const CACHE_MAX_AGE = 3600;

  public function __construct(
    protected AliasManagerInterface $aliasManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected TextExtractor $textExtractor,
    protected LayoutWalker $layoutWalker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('path_alias.manager'),
      $container->get('entity_display.repository'),
      $container->get('access_content_api.text_extractor'),
      $container->get('access_content_api.layout_walker'),
    );
  }

  /**
   * Returns content JSON for a node by numeric ID.
   */
  public function byId(Request $request, int $id): Response {
    $node = $this->entityTypeManager()->getStorage('node')->load($id);
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
    return $this->buildResponse($request, $node);
  }

  /**
   * Builds a cacheable JSON response for a node, or a 404.
   */
  private function buildResponse(Request $request, mixed $node): Response {
    if (!$node instanceof NodeInterface) {
      return $this->notFound();
    }

    if (!$node->isPublished()) {
      return $this->notFound();
    }

    if (!$this->hasTextViewMode($node->bundle())) {
      return $this->notFound();
    }

    if (!$this->isOnSupportDomain($node)) {
      return $this->notFound();
    }

    $nid = $node->id();
    $changed = $node->getChangedTime();
    $etag = '"' . $nid . '-' . $changed . '"';

    if ($request->headers->get('If-None-Match') === $etag) {
      $response = new Response('', 304);
      $response->headers->set('ETag', $etag);
      return $response;
    }

    $alias = $this->aliasManager->getAliasByPath('/node/' . $nid);
    $html = $this->layoutWalker->render($node);
    $text = $this->textExtractor->extract($html);

    $data = [
      'version' => 1,
      'id' => (int) $nid,
      'title' => $node->label(),
      'path' => $alias,
      'content_type' => $node->bundle(),
      'last_modified' => date('c', $changed),
      'text' => $text,
    ];

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['node:' . $nid]);
    $cacheMetadata->setCacheMaxAge(self::CACHE_MAX_AGE);
    $cacheMetadata->setCacheContexts(['user.roles:anonymous']);

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cacheMetadata);
    $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $changed) . ' GMT');
    $response->headers->set('ETag', $etag);

    return $response;
  }

  /**
   * Checks whether a bundle has the text view mode configured.
   */
  private function hasTextViewMode(string $bundle): bool {
    $modes = $this->entityDisplayRepository->getViewModeOptionsByBundle('node', $bundle);
    return isset($modes[self::TEXT_VIEW_MODE]);
  }

  /**
   * Returns TRUE if the node is assigned to the support domain.
   */
  private function isOnSupportDomain(NodeInterface $node): bool {
    if (!$node->hasField('field_domain_access')) {
      return FALSE;
    }
    foreach ($node->get('field_domain_access') as $item) {
      if ($item->getValue()['target_id'] === self::SUPPORT_DOMAIN_ID) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns a 404 JSON response.
   */
  private function notFound(): Response {
    return new Response('Not Found', 404, ['Content-Type' => 'application/json']);
  }

}
