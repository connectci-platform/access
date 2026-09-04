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

  /**
   * Remediate bug-victim series: regenerate unregistered victims' instances.
   *
   * The scoped post-migration step. The boundary migration fixes a victim's
   * stored dates but leaves its instances on the slid dates; this command
   * takes the victim list the migration persisted, re-counts registrants
   * fresh, and regenerates instances through the normal creation service for
   * every PUBLISHED victim with zero registrants. Registered and unpublished
   * victims are never touched — they are listed (and stay pending) for the
   * operator playbook, unpublished ones because publishing them later will
   * not rebuild their instances on its own.
   * Run it after reviewing the migration's victim log; safe to re-run.
   *
   * @command access-events:remediate-recur-victims
   * @aliases access-events-recur-remediate
   * @usage drush access-events:remediate-recur-victims
   *   Regenerate instances for unregistered bug-victim series.
   */
  public function remediateRecurVictims(): void {
    $victims = $this->migrator->pendingVictims();
    if ($victims === []) {
      $this->output()->writeln('No bug-victim series are pending remediation.');
      return;
    }

    $report = $this->migrator->remediate($victims);

    foreach ($report['regenerated'] as $entry) {
      $this->output()->writeln(sprintf('Regenerated instances for series %d ("%s") from its corrected boundaries.', $entry['id'], $entry['title']));
    }
    foreach ($report['registered'] as $entry) {
      $this->output()->writeln(sprintf('LEFT UNTOUCHED: series %d ("%s") has %d not-past registrant(s) — operator playbook decision; it stays pending.', $entry['id'], $entry['title'], $entry['registrants']));
    }
    foreach ($report['unpublished'] as $entry) {
      $this->output()->writeln(sprintf('LEFT UNTOUCHED: series %d ("%s") is unpublished — publishing it will NOT realign its instances, so it stays pending for an explicit decision.', $entry['id'], $entry['title']));
    }
    foreach ($report['missing'] as $id) {
      $this->output()->writeln(sprintf('Skipped series %d: no longer exists.', $id));
    }
  }

}
