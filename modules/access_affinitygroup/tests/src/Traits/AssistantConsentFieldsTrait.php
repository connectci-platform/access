<?php

namespace Drupal\Tests\access_affinitygroup\Traits;

/**
 * Creates the assistant consent fields the way a deployed site gets them.
 *
 * Runs the real update hook rather than hand-built fixtures, so the tests use
 * the same field definitions that updb creates on a site where the module is
 * already installed. installConfig(['access_affinitygroup']) is not usable in
 * kernel tests for this module (it throws SchemaIncompleteException).
 */
trait AssistantConsentFieldsTrait {

  /**
   * Creates the consent, consent version, and consent asked user fields.
   */
  protected function installConsentField(): void {
    \Drupal::moduleHandler()->loadInclude('access_affinitygroup', 'install');
    access_affinitygroup_update_10017();
  }

}
