<?php

namespace Drupal\access_content_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\domain_access\DomainAccessManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared eligibility + URL logic for the content API.
 *
 * Single source of truth for which domain and view mode the API serves, so the
 * per-id, per-path, and index endpoints (and the layout walker) cannot drift.
 */
class ContentEligibility {

  /**
   * Fallback used when config is unpopulated (e.g. before the update hook runs
   * on an existing site). Matches config/install/access_content_api.settings.
   */
  const DEFAULT_SUPPORT_DOMAIN_ID = 'amp_cyberinfrastructure_org';
  const DEFAULT_TEXT_VIEW_MODE = 'text';

  /**
   * Text bundles left out of the content index (still served by id/path).
   */
  const INDEX_EXCLUDED_BUNDLES = ['access_news'];

  /**
   * Per-bundle state allowlists applied to the content index.
   *
   * Keyed by bundle; 'field' is the state field and 'values' the allowed
   * values. Nodes outside the list stay reachable by id/path.
   */
  const INDEX_STATE_ALLOWLIST = [
    'match_engagement' => [
      'field' => 'field_status',
      'values' => ['in_progress', 'complete'],
    ],
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the configured support domain machine name.
   */
  public function getSupportDomainId(): string {
    return (string) ($this->settings()->get('support_domain_id') ?: self::DEFAULT_SUPPORT_DOMAIN_ID);
  }

  /**
   * Returns the configured view mode rendered for text extraction.
   */
  public function getTextViewMode(): string {
    return (string) ($this->settings()->get('text_view_mode') ?: self::DEFAULT_TEXT_VIEW_MODE);
  }

  /**
   * Returns TRUE if the bundle has the configured text view mode.
   */
  public function hasTextViewMode(string $bundle): bool {
    $modes = $this->entityDisplayRepository->getViewModeOptionsByBundle('node', $bundle);
    return isset($modes[$this->getTextViewMode()]);
  }

  /**
   * Returns the node bundles that have the configured text view mode.
   *
   * @return string[]
   *   The bundle machine names.
   */
  public function getTextBundles(): array {
    $bundles = [];
    $ids = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    foreach ($ids as $bundle) {
      if ($this->hasTextViewMode((string) $bundle)) {
        $bundles[] = (string) $bundle;
      }
    }
    return $bundles;
  }

  /**
   * Returns the text bundles that are listed in the content index.
   *
   * @return string[]
   *   The bundle machine names.
   */
  public function getIndexBundles(): array {
    return array_values(array_diff($this->getTextBundles(), self::INDEX_EXCLUDED_BUNDLES));
  }

  /**
   * Returns TRUE if the node may be listed in the content index.
   *
   * Fails closed: a bundle with a state allowlist requires the node to have
   * the field with a value in the list.
   */
  public function isIndexable(NodeInterface $node): bool {
    $bundle = $node->bundle();
    if (in_array($bundle, self::INDEX_EXCLUDED_BUNDLES, TRUE)) {
      return FALSE;
    }
    if (isset(self::INDEX_STATE_ALLOWLIST[$bundle])) {
      $rule = self::INDEX_STATE_ALLOWLIST[$bundle];
      if (!$node->hasField($rule['field']) || $node->get($rule['field'])->isEmpty()) {
        return FALSE;
      }
      return in_array($node->get($rule['field'])->value, $rule['values'], TRUE);
    }
    return TRUE;
  }

  /**
   * Returns TRUE if the node is a private affinity group.
   */
  public function isPrivate(NodeInterface $node): bool {
    return $node->hasField('field_ag_private')
      && (int) $node->get('field_ag_private')->value === 1;
  }

  /**
   * Returns TRUE if the node is in scope for the given domain.
   *
   * A node qualifies if it is flagged "all affiliates" (domain_access grants
   * such nodes a site-wide view) or explicitly assigned to the domain.
   */
  public function isOnDomain(NodeInterface $node, string $domain_id): bool {
    if (DomainAccessManager::getAllValue($node)) {
      return TRUE;
    }
    // getAccessValues() returns an array keyed by domain machine name.
    return array_key_exists($domain_id, DomainAccessManager::getAccessValues($node));
  }

  /**
   * Returns TRUE if the node is in scope for the support-domain API.
   *
   * The content index (the RAG corpus definition) stays pinned to the support
   * domain; the render endpoints are domain-aware via isOnDomain().
   */
  public function isOnSupportDomain(NodeInterface $node): bool {
    return $this->isOnDomain($node, $this->getSupportDomainId());
  }

  /**
   * Builds an absolute URL on the support domain for a site-relative path.
   *
   * RAG consumers store this metadata away from the site, so URLs must be
   * self-describing. Derives the host/scheme from the support domain entity
   * (e.g. https://support.access-ci.org) rather than the active request.
   */
  public function supportDomainUrl(string $path): string {
    return $this->domainUrl($this->getSupportDomainId(), $path);
  }

  /**
   * Builds an absolute URL on the given domain for a site-relative path.
   */
  public function domainUrl(string $domain_id, string $path): string {
    $domain = $this->entityTypeManager->getStorage('domain')
      ->load($domain_id);
    if (!$domain) {
      // Misconfiguration: the configured support domain entity is missing. Fall
      // back to the relative path but log it, since RAG consumers expect an
      // absolute URL and this silently degrades citation quality.
      $this->logger->warning('Domain "@id" not found; emitting relative URL "@path".', [
        '@id' => $domain_id,
        '@path' => $path,
      ]);
      return $path;
    }
    return $domain->buildUrl($path);
  }

  /**
   * Returns the immutable settings config.
   */
  private function settings() {
    return $this->configFactory->get('access_content_api.settings');
  }

}
