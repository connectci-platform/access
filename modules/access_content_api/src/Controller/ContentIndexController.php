<?php

namespace Drupal\access_content_api\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles /.well-known/content-index.json.
 */
final class ContentIndexController extends ControllerBase {

  const SUPPORT_DOMAIN_ID = 'amp_cyberinfrastructure_org';
  const TEXT_VIEW_MODE = 'text';
  const CACHE_MAX_AGE = 900;

  public function __construct(
    protected AliasManagerInterface $aliasManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('path_alias.manager'),
      $container->get('entity_display.repository'),
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
      ->condition('field_domain_access', self::SUPPORT_DOMAIN_ID)
      ->sort('nid', 'ASC');

    $nids = $query->execute();

    $pages = [];
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$this->hasTextViewMode($node->bundle())) {
        continue;
      }
      $nid = $node->id();
      $alias = $this->aliasManager->getAliasByPath('/node/' . $nid);
      $pages[] = [
        'title' => $node->label(),
        'path' => $alias,
        'content_url' => '/api/1.0/content/' . $nid,
        'last_modified' => date('c', $node->getChangedTime()),
        'content_type' => $node->bundle(),
      ];
    }

    // Stable sort by path alias ASC.
    usort($pages, fn($a, $b) => strcmp($a['path'], $b['path']));

    $data = [
      'version' => 1,
      'generated_at' => date('c'),
      'pages' => $pages,
    ];

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['node_list:page']);
    $cacheMetadata->setCacheMaxAge(self::CACHE_MAX_AGE);
    $cacheMetadata->setCacheContexts(['user.roles:anonymous']);

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cacheMetadata);

    return $response;
  }

  /**
   * Checks whether a bundle has the text view mode configured.
   */
  private function hasTextViewMode(string $bundle): bool {
    $modes = $this->entityDisplayRepository->getViewModeOptionsByBundle('node', $bundle);
    return isset($modes[self::TEXT_VIEW_MODE]);
  }

}
