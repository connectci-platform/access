<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\user\Entity\User;

/**
 * Sort Users.
 *
 * @RoleProgramSorter(
 *   id = "role_program_sorter",
 *   title = @Translation("CSSN Role Program Sorter"),
 *   description = @Translation("Sorts users by CSSN role and program.")
 * )
 */
class RoleProgramSorter {

  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  /**
   * Store user object.
   *
   * @var object
   */
  private $storedUser;

  /**
   * Function to return matching nodes.
   */
  public function __construct($user) {
    $this->storedUser = $user;
  }

  /**
   * Add role to user.
   */
  public function addRole($role) {
    $account = User::load($this->storedUser->id());
    if (!$account->hasRole($role)) {
      $account->addRole($role);
      $account->save();
    }
  }

  /**
   * Remove role from user.
   */
  public function removeRole($role) {
    $account = User::load($this->storedUser->id());
    if ($account->hasRole($role)) {
      $account->removeRole($role);
      $account->save();
    }
  }

  /**
   * Lookup if region is set.
   */
  public function lookupRegion($region) {
    $account = User::load($this->storedUser->id());
    // Check if region already exists and if not add it.
    $values = $account->field_region->getValue();
    // While loop through values to check if target_id equals 780.
    $i = 0;
    $region_exists = FALSE;
    while ($i < count($values)) {
      if ($values[$i]['target_id'] == $region) {
        $i = count($values);
        $region_exists = TRUE;
      }
      $i++;
    }
    return $region_exists;
  }

  /**
   * Add item to field_region.
   */
  public function addFieldRegion($region) {
    $account = User::load($this->storedUser->id());
    $program_set = $this->lookupRegion($region);
    if (!$program_set) {
      $account->field_region->appendItem($region);
      $account->save();
    }
    \Drupal::messenger()->addMessage($this->t('Thanks for updating your CSSN membership.'));
  }

  /**
   * Remove item to field_region.
   */
  public function removeFieldRegion($region) {
    $account = User::load($this->storedUser->id());
    $values = $account->field_region->getValue();
    foreach ($values as $key => $value) {
      if ($value['target_id'] == $region) {
        unset($values[$key]);
        \Drupal::messenger()->addMessage($this->t('Thanks for updating your CSSN membership.'));
      }
    }
    $account->field_region->setValue($values);
    $account->save();
  }

}
