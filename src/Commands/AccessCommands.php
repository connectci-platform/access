<?php

namespace Drupal\access\Commands;

use Drush\Commands\DrushCommands;

/**
 * Various utility commands for Access.
 */
class AccessCommands extends DrushCommands {

  /**
   * Ingest ACCESS organizations.
   *
   * @command access:ingest-orgs
   * @aliases ingest-organizations
   * @options limit The number of records to process
   */
  public function ingestOrganizations($verbose = FALSE) {
    \Drupal::service('access_misc.import_access_orgs')->ingest($verbose);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
  }

}
