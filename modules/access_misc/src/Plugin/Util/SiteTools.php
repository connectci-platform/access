<?php

namespace Drupal\access_misc\Plugin\Util;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Utility\Token;

/**
 * Notify people by roles.
 */
class SiteTools {

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

}
