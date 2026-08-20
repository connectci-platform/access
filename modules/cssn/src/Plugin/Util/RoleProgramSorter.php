<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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

  use StringTranslationTrait;

  /**
   * The account being sorted.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private AccountInterface $storedUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private MessengerInterface $messenger;

  /**
   * Constructs a RoleProgramSorter.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The account whose roles and regions are sorted.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(AccountInterface $user, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $this->storedUser = $user;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * Loads the stored user account.
   *
   * @return \Drupal\user\UserInterface
   *   The loaded user.
   */
  private function loadAccount() {
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($this->storedUser->id());
    return $account;
  }

  /**
   * Add role to user.
   *
   * @param string $role
   *   The role id to add.
   */
  public function addRole(string $role): void {
    $account = $this->loadAccount();
    if (!$account->hasRole($role)) {
      $account->addRole($role);
      $account->save();
    }
  }

  /**
   * Remove role from user.
   *
   * @param string $role
   *   The role id to remove.
   */
  public function removeRole(string $role): void {
    $account = $this->loadAccount();
    if ($account->hasRole($role)) {
      $account->removeRole($role);
      $account->save();
    }
  }

  /**
   * Lookup if region is set.
   *
   * @param int|string $region
   *   The taxonomy term id of the region.
   *
   * @return bool
   *   TRUE when the user already references the region.
   */
  public function lookupRegion($region): bool {
    $account = $this->loadAccount();
    // Check if region already exists and if not add it.
    $values = $account->get('field_region')->getValue();
    foreach ($values as $value) {
      if ($value['target_id'] == $region) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Add item to field_region.
   *
   * @param int|string $region
   *   The taxonomy term id of the region.
   */
  public function addFieldRegion($region): void {
    $account = $this->loadAccount();
    $program_set = $this->lookupRegion($region);
    if (!$program_set) {
      $account->get('field_region')->appendItem($region);
      $account->save();
    }
    $this->messenger->addMessage($this->t('Thanks for updating your CSSN membership.'));
  }

  /**
   * Remove item from field_region.
   *
   * @param int|string $region
   *   The taxonomy term id of the region.
   */
  public function removeFieldRegion($region): void {
    $account = $this->loadAccount();
    $values = $account->get('field_region')->getValue();
    foreach ($values as $key => $value) {
      if ($value['target_id'] == $region) {
        unset($values[$key]);
        $this->messenger->addMessage($this->t('Thanks for updating your CSSN membership.'));
      }
    }
    $account->get('field_region')->setValue($values);
    $account->save();
  }

}
