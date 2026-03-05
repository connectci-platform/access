<?php

namespace Drupal\cssn\Plugin\Util;

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
   */
  public function setFlag($flag_id, $entity, $user) {
    $flag = $this->flagService->getFlagById($flag_id);
    $flagStatus = $this->flagService->getFlagging($flag, $entity, $user);
    if (!$flagStatus) {
      $this->flagService->flag($flag, $entity, $user);
      $flag->save();
      // Return 1 if set.
      return 1;
    }
    // Return 0 if not set.
    return 0;
  }

}
