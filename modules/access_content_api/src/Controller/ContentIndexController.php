<?php

namespace Drupal\access_content_api\Controller;

use Drupal\access_content_api\ContentEligibility;
use Drupal\access_content_api\RenderHash;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles /.well-known/content-index.json.
 */
final class ContentIndexController extends ControllerBase {

  const CACHE_MAX_AGE = 900;

  /**
   * Warn above this render duration (ms); promote to a container parameter if
   * per-environment tuning is ever needed. Trigger for the hash-on-save work.
   */
  const RENDER_WARN_MS = 3000;

  public function __construct(
    protected AliasManagerInterface $aliasManager,
    protected ContentEligibility $eligibility,
    protected RenderHash $renderHash,
    protected LoggerInterface $logger,
    protected AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('path_alias.manager'),
      $container->get('access_content_api.eligibility'),
      $container->get('access_content_api.render_hash'),
      $container->get('logger.channel.access_content_api'),
      $container->get('account_switcher'),
    );
  }

  /**
   * Returns the content discovery index as JSON.
   */
  public function index(): CacheableJsonResponse {
    $bundles = $this->eligibility->getIndexBundles();

    $cacheMetadata = new CacheableMetadata();
    // Adding or removing a text display changes which bundles are listed.
    $cacheMetadata->addCacheTags(['config:entity_view_display_list']);
    foreach ($bundles as $bundle) {
      $cacheMetadata->addCacheTags(['node_list:' . $bundle]);
    }
    $cacheMetadata->setCacheMaxAge(self::CACHE_MAX_AGE);

    if (empty($bundles)) {
      return $this->buildResponse([], $cacheMetadata);
    }

    $pages = [];
    // The output only ever reflects what an anonymous visitor can see, so the
    // query, access checks and rendering all run as anonymous.
    $anonymous = new AnonymousUserSession();
    $this->accountSwitcher->switchTo($anonymous);
    try {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');

      $query = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', $bundles, 'IN')
        ->condition('status', 1)
        ->sort('nid', 'ASC');

      // Match isOnSupportDomain(): a node qualifies if it is assigned to the
      // support domain OR flagged "all affiliates" (site-wide public). The
      // index must agree with the per-id endpoint, or the two will disagree.
      $domainOrAffiliates = $query->orConditionGroup()
        ->condition('field_domain_access', $this->eligibility->getSupportDomainId())
        ->condition('field_domain_all_affiliates', 1);
      $query->condition($domainOrAffiliates);

      $nids = $query->execute();

      $start = hrtime(TRUE);
      foreach ($nodeStorage->loadMultiple($nids) as $node) {
        if (!$this->eligibility->hasTextViewMode($node->bundle())
          || !$this->eligibility->isIndexable($node)
          || $this->eligibility->isPrivate($node)
          || !$node->access('view', $anonymous)) {
          continue;
        }
        $nid = $node->id();
        $alias = $this->aliasManager->getAliasByPath('/node/' . $nid);
        $nodeCacheMetadata = new CacheableMetadata();
        $pages[] = [
          'title' => $node->label(),
          'path' => $this->eligibility->supportDomainUrl($alias),
          'content_url' => $this->eligibility->supportDomainUrl('/api/1.0/content/' . $nid),
          'last_modified' => date('c', $node->getChangedTime()),
          'content_hash' => $this->renderHash->contentHash($node, $nodeCacheMetadata),
          'content_type' => $node->bundle(),
        ];
        $cacheMetadata->addCacheableDependency($nodeCacheMetadata);
      }
      $elapsedMs = (hrtime(TRUE) - $start) / 1e6;
      if ($elapsedMs > self::RENDER_WARN_MS) {
        $this->logger->warning('Content index render took @ms ms for @count pages; consider hash-on-save (see plan B1 trigger).', [
          '@ms' => round($elapsedMs),
          '@count' => count($pages),
        ]);
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    // Stable sort by path alias ASC.
    usort($pages, fn($a, $b) => strcmp($a['path'], $b['path']));

    return $this->buildResponse($pages, $cacheMetadata);
  }

  /**
   * Builds the cacheable index response.
   *
   * @param array<int, array<string, string>> $pages
   *   The index entries.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheMetadata
   *   The cache metadata to attach.
   */
  private function buildResponse(array $pages, CacheableMetadata $cacheMetadata): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'version' => 1,
      'generated_at' => date('c'),
      'pages' => $pages,
    ]);
    $response->addCacheableDependency($cacheMetadata);
    return $response;
  }

}
