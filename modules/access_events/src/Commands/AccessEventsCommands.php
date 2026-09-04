<?php

namespace Drupal\access_events\Commands;

use Drupal\access_events\RecurBoundaryMigrator;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the access_events module.
 */
class AccessEventsCommands extends DrushCommands {

  protected RecurBoundaryMigrator $migrator;

  public function __construct(RecurBoundaryMigrator $migrator) {
    parent::__construct();
    $this->migrator = $migrator;
  }

  /**
   * Re-run the recurrence-boundary normalization.
   *
   * The initial normalization runs once via hook_update_N during updb. This
   * command re-runs the same idempotent transform for rows written after
   * that run — e.g. during a Drupal rollback window, or by a form that was
   * opened pre-migration and submitted post-migration. Safe at any time:
   * already-anchored rows are skipped silently.
   *
   * @command access-events:migrate-recur-boundaries
   * @aliases access-events-recur-migrate
   * @usage drush access-events:migrate-recur-boundaries
   *   Normalize any recurrence boundary rows that are not yet anchored.
   */
  public function migrateRecurBoundaries(): void {
    $report = $this->migrator->migrate(FALSE);

    $this->output()->writeln(sprintf(
      'Rewrote %d data rows and %d revision rows (columns: %d T00, %d bare-date, %d wall-clock; %d anchored skipped, %d unrecognized left untouched).',
      $report['updated']['eventseries_field_data'],
      $report['updated']['eventseries_field_revision'],
      $report['shapes']['t00'],
      $report['shapes']['bare'],
      $report['shapes']['wall_clock'],
      $report['shapes']['anchored'],
      $report['shapes']['unrecognized'],
    ));

    foreach ($report['victims'] as $victim) {
      $this->output()->writeln(sprintf(
        'Bug-victim series %d ("%s", %d not-past registrants): instances were generated from slid dates — remediate (regenerate if unregistered; operator decision if registered).',
        $victim['id'],
        $victim['title'],
        $victim['registrants'],
      ));
    }
  }

}
