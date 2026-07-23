<?php

namespace Drupal\access_misc\Plugin\Util;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Utility\Token;

/**
 * Notify people by roles.
 */
class SiteTools {

  /**
   * Domain ID constants for consistent reference across modules.
   */
  const DOMAIN_ACCESS_SUPPORT = 'amp_cyberinfrastructure_org';
  const DOMAIN_CAMPUS_CHAMPIONS = 'campuschampions_cyberinfrastructure_org';
  const DOMAIN_CCMNET = 'ccmnet_org';
  const DOMAIN_COCO = 'coco_cyberinfrastructure_org';
  const DOMAIN_OPENONDEMAND = 'openondemand_cyberinfrastructure_org';
  const DOMAIN_CURRENT = 'current'; // Special value to use current domain

  /**
   * Run Entity Query.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Construct object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Token $token) {
    $this->entityTypeManager = $entity_type_manager;
    $this->token = $token;
  }

  /**
   * Get Current Domain.
   */
  public function getDomain() {
    $domainName = "[domain:name]";
    $current_domain_name = Html::getClass($this->token->replace($domainName));

    return $current_domain_name;
  }

  /**
   * Get Current Domain ID.
   */
  public function getDomainId() {
    $domain_negotiator = \Drupal::service('domain.negotiator');
    $active_domain = $domain_negotiator->getActiveDomain();

    return $active_domain->id();
  }

  /**
   * Get current program based on domain.
   */
  public function getProgram() {
    $current_domain = $this->getDomain();
    $program = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_region_connected_domain', $current_domain)
      ->condition('vid', 'region')
      ->execute();

    return $program;
  }

  /**
   * Get primary url for set domain.
   */
  public function getDomainUrl($domain_id) {
    $domain = $this->entityTypeManager->getStorage('domain')->load($domain_id);
    if ($domain) {
      return $domain->buildUrl('');
    }
    return 'https://support.access-ci.org'; // Fallback URL if domain not found.
  }

  /**
   * Get current event domain URL.
   */
  public function getEventCurrentDomainUrl($event_instance_id) {
    try {
      // Load the event instance.
      $event_instance = \Drupal::entityTypeManager()
        ->getStorage('eventinstance')
        ->load($event_instance_id);
      if (!$event_instance) {
        return '/events/' . $event_instance_id; // Fallback to relative URL.
      }

      // Get associated domains from the event instance.
      $instance_domains = $event_instance->get('domain_access')->getValue();

      // If instance doesn't have domains, get from the series.
      if (empty($instance_domains)) {
        $series = $event_instance->getEventSeries();
        if ($series) {
          $instance_domains = $series->get('domain_access')->getValue();
        }
      }

      // Generate domain-specific URL if domains are found.
      if (!empty($instance_domains)) {
        $domain_id = $instance_domains[0]['target_id'];
        $domain = \Drupal::entityTypeManager()
          ->getStorage('domain')
          ->load($domain_id);
        if ($domain) {
          return $domain->buildUrl('/events/' . $event_instance_id);
        }
      }

      // Fallback to current domain if no specific domain found.
      $current_domain = \Drupal::service('domain.negotiator')->getActiveDomain();
      if ($current_domain) {
        return $current_domain->buildUrl('/events/' . $event_instance_id);
      }

      // Final fallback to relative URL.
      return '/events/' . $event_instance_id;
    }
    catch (\Exception $e) {
      \Drupal::logger('access_misc')
        ->error('Error generating event domain URL: ' . $e->getMessage());
      return '/events/' . $event_instance_id;
    }
  }

  /**
   * Build an absolute URL for an entity, respecting its domain_access field.
   *
   * Uses the entity's first assigned domain to construct the URL.
   * Falls back to the current domain, then to support.access-ci.org.
   *
   * @param string $relative_path
   *   The relative path (e.g., '/events/9018', '/announcements/my-title').
   *   May also be an absolute URL (Domain Source rewrites canonical URLs of
   *   cross-domain entities); absolute URLs are returned unchanged.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity to get the domain from, or NULL to use current domain.
   *
   * @return string
   *   The absolute URL.
   */
  public function buildDomainAwareUrl($relative_path, $entity = NULL) {
    // Under Domain Source, a canonical URL passed in may already be an
    // absolute URL. Return it unchanged so the domain host is not prepended
    // a second time (e.g. https://ccmnet.orghttps://ccmnet.org/...).
    if (UrlHelper::isExternal($relative_path)) {
      return $relative_path;
    }
    try {
      // Try to get domain from entity's domain_access field.
      if ($entity && $entity->hasField('domain_access')) {
        $domains = $entity->get('domain_access')->getValue();

        // For event instances, fall back to series if no domains.
        if (empty($domains) && method_exists($entity, 'getEventSeries')) {
          $series = $entity->getEventSeries();
          if ($series) {
            $domains = $series->get('domain_access')->getValue();
          }
        }

        if (!empty($domains)) {
          $domain = $this->entityTypeManager->getStorage('domain')
            ->load($domains[0]['target_id']);
          if ($domain) {
            return $domain->buildUrl($relative_path);
          }
        }
      }

      // Fall back to current domain.
      $current_domain = \Drupal::service('domain.negotiator')->getActiveDomain();
      if ($current_domain) {
        return $current_domain->buildUrl($relative_path);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('access_misc')
        ->error('Error building domain-aware URL: ' . $e->getMessage());
    }

    // Final fallback.
    return 'https://support.access-ci.org' . $relative_path;
  }

  /**
   * Get noreply email address for a domain.
   *
   * @param string|null $domain
   *   The domain name (e.g., 'pa-science', 'ccmnet').
   *   If NULL, uses the current domain.
   *
   * @return string
   *   The noreply email address for the domain.
   */
  public function getNoreplyEmail($domain = NULL) {
    if ($domain === NULL) {
      $domain = $this->getDomain();
    }

    // Map domains to their noreply addresses.
    $domain_emails = [
      'pa-science' => 'noreply@pasciencedmz.connectci.org',
      'ccmnet' => 'noreply@ccmnet.org',
      'access-ci' => 'noreply@access-ci.org',
    ];

    // Return domain-specific email or default.
    return $domain_emails[$domain] ?? 'noreply@support.access-ci.org';
  }

  /**
   * Get manager role for a program/region.
   *
   * @param int|string $program_id
   *   The taxonomy term ID for the region/program.
   *
   * @return string|null
   *   The manager role name, or NULL if not configured.
   */
  public function getManagerRole($program_id) {
    // Map program IDs to manager roles.
    $program_roles = [
      323 => 'careers_sc',         // CAREERS
      933 => 'pascience_manager',  // PA Science
      // Add other programs here as they are configured.
    ];

    return $program_roles[$program_id] ?? NULL;
  }

}
