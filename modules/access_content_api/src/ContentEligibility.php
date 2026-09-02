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
