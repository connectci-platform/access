<?php

/**
 * @file
 * Deploy hooks for the cssn module.
 *
 * Deploy hooks run after config is imported (drush deploy: updatedb ->
 * config:import -> deploy:hook), so they can reindex an index whose fields
 * were just added or changed by a config import.
 */

use Drupal\search_api\Entity\Index;

/**
 * Reindex cssn_directory to populate the new user_organization field.
 *
 * D8-2735 adds a user_organization field to the cssn_directory index (the
 * organization name reached through the field_access_organization entity
 * reference) so the people search box matches on organization. A new index
 * field stays empty until its items are reindexed, so mark the index for
 * reindexing here, after config:import has added the field.
 *
 * We call reindex() only, not indexItems(). cssn_directory tracks ~68k users;
 * indexing them synchronously would make the deploy hang. reindex() just resets
 * the tracker (fast) and cron drains the queue over the following runs.
 */
function cssn_deploy_reindex_user_organization() {
  $index = Index::load('cssn_directory');
  if (!$index) {
    return 'cssn_directory index not found; skipped reindex. Reindex manually if the people organization search is empty.';
  }
  $index->reindex();
  return 'cssn_directory marked for reindexing to populate user_organization; cron will process the ~68k items.';
}
