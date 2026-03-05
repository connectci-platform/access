<?php

namespace Drupal\access_misc\Plugin\Util;

/**
 * Get role label from role machine name.
 *
 * @RolesLabelLookup(
 *   id = "roles_label_lookup",
 *   title = @Translation("Lookup a role label"),
 *   description = @Translation("Get role label from role machine name.")
 * )
 */
class RolesLabelLookup {
  /**
   * Stores roles by label.
   *
   * @var string
   */
  private $roleLabels;

  /**
   * Lookup label of roles given.
   */
  public function __construct($roles) {
    // For each role get the label.
    foreach ($roles as $key => $role) {
      if (\Drupal::entityTypeManager()->getStorage('user_role')->load($role)) {
        $roles[$key] = \Drupal::entityTypeManager()->getStorage('user_role')->load($role)->label();
      }
    }
    $this->roleLabels = $roles;
  }

  /**
   * Get the role labels in array.
   */
  public function getRoleLabels() {
    return $this->roleLabels;
  }

  /**
   * Get the role labels in string.
   */
  public function getRoleLabelsString($implode = '<br />') {
    return implode($implode, $this->roleLabels);
  }

}
