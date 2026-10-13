<?php

namespace Drupal\access_cilink\Controller;

use Drupal\access_cilink\KbResourceRepository;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\domain\Entity\Domain;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles GET /api/1.0/kb-resources.
 */
final class KbResourceApiController extends ControllerBase {

  const CACHE_MAX_AGE = 900;

  public function __construct(
    protected KbResourceRepository $repository,
    protected DomainNegotiatorInterface $domainNegotiator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('access_cilink.kb_resource_repository'),
      $container->get('domain.negotiator'),
    );
  }

  /**
   * Returns the public KB resources of the requesting site as JSON.
   *
   * The response ignores the current user: everyone gets the approved,
   * non-private set for the active domain.
   */
  public function index(): CacheableJsonResponse {
    $domain_class = $this->repository->activeDomainClass();
    $sids = $this->repository->getPublicSids($domain_class);

    $entries = [];
    if ($sids) {
      $submissions = $this->entityTypeManager()->getStorage('webform_submission')->loadMultiple($sids);
      foreach ($sids as $sid) {
        if (isset($submissions[$sid])) {
          $entries[] = $this->repository->buildEntry($submissions[$sid]);
        }
      }
    }

    $cache = new CacheableMetadata();
    // Bundle-scoped tags, so unrelated submissions and term saves don't flush
    // the cache.
    $cache->addCacheTags([
      'webform_submission_list:resource',
      'flagging_list:upvote',
      'taxonomy_term_list:tags',
      'taxonomy_term_list:skill_level',
      'taxonomy_term_list:region',
    ]);
    $domain = $this->domainNegotiator->getActiveDomain();
    if ($domain instanceof Domain) {
      // A domain rename changes the domain class.
      $cache->addCacheableDependency($domain);
    }
    $cache->setCacheContexts(['url.site']);
    $cache->setCacheMaxAge(self::CACHE_MAX_AGE);

    $response = new CacheableJsonResponse([
      'version' => 1,
      'generated_at' => date('c'),
      'domain' => $domain_class,
      'kb_resources' => $entries,
    ]);
    $response->addCacheableDependency($cache);

    return $response;
  }

}
