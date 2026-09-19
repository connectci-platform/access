<?php

namespace Drupal\access_events\Commands;

use Drupal\access_events\EventTimezoneBackfill;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the access_events module.
 */
class AccessEventsCommands extends DrushCommands {

  /**
   * The timezone backfill service.
   *
   * @var \Drupal\access_events\EventTimezoneBackfill
   */
  protected EventTimezoneBackfill $backfill;

  public function __construct(EventTimezoneBackfill $backfill) {
    parent::__construct();
    $this->backfill = $backfill;
  }

  /**
   * Backfill event timezones from each series author's account zone.
   *
   * This is the re-runnable path. It is idempotent: a series that already has
   * a timezone is skipped, so a human correction survives a re-run.
   *
   * Inheritance onto instances needs no repair. field_inheritance 3.x resolves
   * from a base field whose entities[<type>:<bundle>] entry covers every
   * inheritance from that source, and recurring_events_update_103000() writes
   * that entry for every existing instance.
   *
   * @command access-events:backfill-timezones
   * @aliases access-events-tz
   * @usage drush access-events:backfill-timezones
   *   Write missing timezones and report the rows that need review.
   */
  public function backfillTimezones(): void {
    $result = $this->backfill->backfill();

    $this->logger()->success(dt('Timezones written: @author from the author\'s account zone, @site from the site default. @skipped series already had one.', [
      '@author' => $result['author_zone'],
      '@site' => $result['site_default'],
      '@skipped' => $result['skipped'],
    ]));

    if (empty($result['review'])) {
      $this->logger()->notice(dt('No series flagged for review.'));
      return;
    }

    // These are reported rather than corrected. The zone written is a sound
    // default, not a proof, and these are the rows where the data itself
    // disagrees with it.
    $this->logger()->warning(dt('@count series need a human decision:', [
      '@count' => count($result['review']),
    ]));
    foreach ($result['review'] as $row) {
      $this->output()->writeln(sprintf(
        '  %-6s %-44s wrote %-22s %s',
        $row['id'],
        mb_strimwidth($row['title'], 0, 43, '…'),
        $row['written'],
        $row['reason']
      ));
    }
  }

}
