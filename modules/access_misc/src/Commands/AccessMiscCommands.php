<?php

namespace Drupal\access_misc\Commands;

use Drupal\access_misc\Services\AllocationsUserCreator;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the access_misc module.
 */
class AccessMiscCommands extends DrushCommands {

  protected AllocationsUserCreator $userCreator;

  public function __construct(AllocationsUserCreator $user_creator) {
    parent::__construct();
    $this->userCreator = $user_creator;
  }

  /**
   * Create a Drupal user from an ACCESS allocations-API username.
   *
   * Use this when an ACCESS staff member (e.g., a prospective affinity group
   * coordinator) needs a Drupal account for tagging or role assignment but
   * has no active allocation, so the normal cron sync skips them.
   *
   * The resulting Drupal account uses `{username}@access-ci.org` as the
   * username — this matches the CILogon `sub` claim, so when the user later
   * logs in via ACCESS CI their session links to this existing account.
   *
   * @param string $username
   *   Bare ACCESS username (e.g. "jsill"), without the @access-ci.org suffix.
   *
   * @command access:create-user-from-api
   * @aliases access-create-user
   * @usage drush access:create-user-from-api jsill
   *   Fetch jsill's profile from the allocations API and create a Drupal user.
   */
  public function createUserFromApi(string $username): void {
    try {
      $user = $this->userCreator->createFromUsername($username);
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return;
    }

    $this->output()->writeln(sprintf(
      'uid=%d name=%s mail=%s',
      $user->id(),
      $user->getAccountName(),
      $user->getEmail() ?? '(none)'
    ));
  }

}
