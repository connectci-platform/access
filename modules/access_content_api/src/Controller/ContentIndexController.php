<?php

namespace Drupal\access_content_api\Controller;

use Drupal\access_content_api\ContentEligibility;
use Drupal\access_content_api\RenderHash;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
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
    );
  }

  /**
   * Returns the content discovery index as JSON.
   */
  public function index(): CacheableJsonResponse {
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    $query = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'page')
      ->condition('status', 1)
      ->sort('nid', 'ASC');

    // Match isOnSupportDomain(): a node qualifies if it is assigned to the
    // support domain OR flagged "all affiliates" (site-wide public). The index
    // must agree with the per-id endpoint, or the two will disagree.
    $domainOrAffiliates = $query->orConditionGroup()
      ->condition('field_domain_access', $this->eligibility->getSupportDomainId())
      ->condition('field_domain_all_affiliates', 1);
    $query->condition($domainOrAffiliates);

    $nids = $query->execute();

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['node_list:page']);
    $cacheMetadata->setCacheMaxAge(self::CACHE_MAX_AGE);
    $cacheMetadata->setCacheContexts(['user.roles:anonymous']);

    $pages = [];
    $start = hrtime(TRUE);
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$this->eligibility->hasTextViewMode($node->bundle())) {
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
        '@ms' => round($elapsedMs), '@count' => count($pages),
      ]);
    }

    // Stable sort by path alias ASC.
    usort($pages, fn($a, $b) => strcmp($a['path'], $b['path']));

    $data = [
      'version' => 1,
      'generated_at' => date('c'),
      'pages' => $pages,
    ];

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cacheMetadata);

    return $response;
  }

}
