<?php

namespace Drupal\access_badges\Commands;

use Drupal\access_badges\Service\BadgeSorter;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for access_badges.
 */
class BadgesCommands extends DrushCommands {

  public function __construct(
    protected BadgeSorter $badgeSorter,
  ) {
    parent::__construct();
  }

  /**
   * Sort field_user_badges for all users to match badges taxonomy order.
   *
   * @command badges:sort
   * @aliases badges-sort
   * @usage drush badges:sort
   */
  public function sortBadges(): void {
    $this->output()->writeln('Sorting badges for all users...');
    $count = $this->badgeSorter->sortAllUsers();
    $this->output()->writeln("Done. Resaved $count user(s).");
  }

}
