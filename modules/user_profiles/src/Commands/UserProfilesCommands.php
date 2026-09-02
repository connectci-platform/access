<?php

namespace Drupal\user_profiles\Commands;

use Drupal\user_profiles\UserMerger;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile to migrate profile data from one user to another.
 */
class UserProfilesCommands extends DrushCommands {

  public function __construct(
    protected UserMerger $userMerger,
  ) {
    parent::__construct();
  }

  /**
   * Migrate user data from one user to another (merge + block source).
   *
   * @param string $from_user_id
   *   Id of user to merge from (will be blocked, not deleted).
   * @param string $to_user_id
   *   Id of user to merge to.
   *
   * @command user_profiles:mergeUser
   * @aliases mergeUser
   * @usage user_profiles:mergeUser 21849 17646
   */
  public function mergeUser(string $from_user_id, string $to_user_id): void {
    $this->io()->writeln(sprintf('Merging user %d into %d and blocking user %d...', (int) $from_user_id, (int) $to_user_id, (int) $from_user_id));
    $summary = $this->userMerger->mergeAndBlock((int) $from_user_id, (int) $to_user_id);
    $this->io()->writeln('Merge complete: ' . json_encode($summary));
  }

}
