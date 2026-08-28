<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Finds the community personas that belong in a given domain's XML sitemap.
 *
 * Personas are not entities of their own: /community-persona/{uid} is a custom
 * route rendering a user. They are scoped to a domain the same way the
 * directory listings are, through the region taxonomy: a region term stores the
 * machine name of the domain it belongs to in field_region_connected_domain.
 */
class PersonaSitemapLookup {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PersonaSitemapLookup object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Returns the region term IDs connected to a domain.
   *
   * @param string $domain_id
   *   The domain entity ID, e.g. 'ccmnet_org'.
   *
   * @return array<int, string>
   *   Region term IDs. Empty when the domain has no directory of its own, as
   *   is the case for the Connect CI hub, CoCo and USRSE.
   */
  public function getRegionTermIds($domain_id) {
    $domain = $this->entityTypeManager->getStorage('domain')->load($domain_id);
    if ($domain === NULL) {
      return [];
    }

    // Region terms store the domain as its label run through Html::getClass(),
    // the same value SiteTools::getProgram() looks up for the active domain.
    $connected_domain = Html::getClass($domain->label());

    return array_values($this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'region')
      ->condition('field_region_connected_domain', $connected_domain)
      ->execute());
  }

  /**
   * Returns the users whose public persona page should be indexed.
   *
   * Mirrors the conditions CommunityPersonaController::communityPersonaPublic()
   * renders a page under, plus a check on the account being active.
   *
   * @param array<int, string> $region_tids
   *   Region term IDs to limit the users to.
   *
   * @return array<int, string>
   *   User IDs, sorted by ID.
   */
  public function getPersonaUserIds(array $region_tids) {
    if (empty($region_tids)) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('field_region', $region_tids, 'IN')
      ->sort('uid');

    // The field is optional, so an unset value counts as not hidden.
    $query->condition($query->orConditionGroup()
      ->notExists('field_hide_community_profile')
      ->condition('field_hide_community_profile', 0));

    return array_values($query->execute());
  }

}
