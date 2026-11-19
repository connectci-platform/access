<?php

/**
 * @file
 * Deploy hooks for the access_cilink module.
 *
 * Deploy hooks run during "drush deploy", after config import.
 */

use Drupal\search_api\Entity\Index;

/**
 * Reindexes ci_links so the new approved/private fields are populated.
 *
 * Search API marks the index for reindexing when its fields or processors
 * change during config import, but with a low cron limit the items would
 * trickle in over several cron runs. Items not yet indexed match neither the
 * approved = 1 nor the private = 0 filter, so the Knowledge Base resources
 * listing would be partial. Index everything synchronously instead.
 */
function access_cilink_deploy_reindex_ci_links_approved_private(): string {
  $index = Index::load('ci_links');
  if (!$index) {
    return 'Search API index ci_links not found; nothing to reindex.';
  }

  $index->reindex();
  $indexed = $index->indexItems(-1);

  return sprintf('Reindexed ci_links: %d items indexed.', $indexed);
}

/**
 * Reindexes ci_links so role_access reflects the new resource view access.
 *
 * The role_access processor stores per-role view access at index time. View
 * access to resource submissions changed in D8-3101 (view_any is restricted to
 * administrators and KB PMs, with public resources granted by
 * hook_webform_submission_access), so the stored values are stale. Index
 * everything synchronously.
 */
function access_cilink_deploy_reindex_ci_links_role_access(): string {
  $index = Index::load('ci_links');
  if (!$index) {
    return 'Search API index ci_links not found; nothing to reindex.';
  }

  $index->reindex();
  $indexed = $index->indexItems(-1);

  return sprintf('Reindexed ci_links: %d items indexed.', $indexed);
}
