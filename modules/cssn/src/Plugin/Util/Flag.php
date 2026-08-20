<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlagServiceInterface;

/**
 * Lookup connected Match+ nodes.
 *
 * @Flag(
 *   id = "flag",
 *   title = @Translation("Flag entity"),
 *   description = @Translation("Flag entity, specifically used for flagging
 *   CSSN affinity groups for users signing up at /communuty/cssn.")
 * )
 */
class Flag {

  /**
   * The flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * Constructor.
   *
   * @param \Drupal\flag\FlagServiceInterface $flag_service
   *   The flag service.
   */
  public function __construct(FlagServiceInterface $flag_service) {
    $this->flagService = $flag_service;
  }

  /**
   * Flags entity for user.
   *
   * @param string $flag_id
   *   The id of the flag to set.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to flag.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to flag the entity for.
   *
   * @return int
   *   1 when the flag was newly set, 0 when it was already set.
   */
  public function setFlag(string $flag_id, EntityInterface $entity, AccountInterface $user): int {
    $flag = $this->flagService->getFlagById($flag_id);
    $flag_status = $this->flagService->getFlagging($flag, $entity, $user);
    if (!$flag_status) {
      $this->flagService->flag($flag, $entity, $user);
      $flag->save();
      // Return 1 if set.
      return 1;
    }
    // Return 0 if not set.
    return 0;
  }

}
