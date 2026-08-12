<?php

/**
 * @file
 * One-time deploy hooks for the event moderation-state migration.
 *
 * Before the instance workflow was split out (editorial_eventinstance), every
 * eventinstance's moderation history was written under the shared editorial
 * workflow key. Those old content_moderation_state rows are now mis-keyed:
 * they carry workflow = editorial, and their content_entity_revision_id may
 * no longer point at the instance's current default revision. The two
 * flag-related hooks below then re-derive individually_cancelled from the
 * data shapes that existed before the flag itself did: a keyvalue collection
 * that used to remember which series-wide cancellations were NOT meant to
 * also cancel each of their occurrences individually, and instances that sit
 * archived underneath a series that is itself published (which, under the
 * current model, only happens when that instance was individually
 * cancelled).
 */

/**
 * Re-keys stale eventinstance moderation-state rows onto the instance workflow.
 *
 * Walks content_moderation_state_field_data rows for
 * content_entity_type_id = 'eventinstance' AND workflow = 'editorial' (the
 * pre-split key). For each such instance: if a correctly-keyed
 * (workflow = 'editorial_eventinstance') content_moderation_state entity
 * already exists for it, the stale row is redundant history — delete it via
 * the entity storage's delete(), never save(), because
 * ContentModerationState::save() redirects into saving the related
 * moderated entity (a full eventinstance save, complete with the reaction
 * layer this migration must not trigger). If the referenced eventinstance
 * no longer exists at all (contrib's older instance-rebuild behavior used
 * to delete instances outright, orphaning their moderation history), the
 * stale row is unreadable garbage with nothing to repoint onto — delete it
 * the same way. Otherwise the stale row is the only history this instance
 * has, so it is repointed in place: workflow set to 'editorial_eventinstance'
 * and content_entity_revision_id set to the instance's current default
 * revision id (the stale value may predate revisions the instance has since
 * accrued).
 *
 * A content_moderation_state entity that was re-saved under the old
 * workflow can have MULTIPLE content_moderation_state_field_revision rows
 * sharing its id (core writes one CMS revision per moderated-entity
 * revision). The repoint UPDATE only touches the single row matching
 * content_moderation_state_field_data's revision_id for that id — the
 * entity's current/default CMS revision — and deliberately leaves older
 * revision rows keyed to the retired workflow as-is: they are historical
 * point-in-time records, current/default state is the only thing this
 * migration is responsible for fixing, and a moderated entity's older
 * revisions already fall back to computed defaults when their own CMS
 * revision row is inconsistent.
 */
function access_events_deploy_0001_rekey_instance_moderation(&$sandbox) {
  $database = \Drupal::database();

  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = $database->select('content_moderation_state_field_data', 'cms')
      ->fields('cms', ['id'])
      ->condition('cms.content_entity_type_id', 'eventinstance')
      ->condition('cms.workflow', 'editorial')
      ->execute()
      ->fetchCol();
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['processed'] = 0;
    $sandbox['deleted'] = 0;
    $sandbox['repointed'] = 0;
    $sandbox['orphaned'] = 0;

    if ($sandbox['total'] === 0) {
      $sandbox['#finished'] = 1;
      return t('No stale eventinstance moderation-state rows found.');
    }
  }

  $storage = \Drupal::entityTypeManager()->getStorage('content_moderation_state');
  $batch = array_splice($sandbox['ids'], 0, 50);

  foreach ($batch as $staleId) {
    $staleRow = $database->select('content_moderation_state_field_data', 'cms')
      ->fields('cms', ['id', 'revision_id', 'content_entity_id'])
      ->condition('cms.id', $staleId)
      ->execute()
      ->fetchAssoc();
    if (!$staleRow) {
      continue;
    }
    $instanceId = (int) $staleRow['content_entity_id'];
    $currentCmsRevisionId = (int) $staleRow['revision_id'];

    $currentRevisionId = $database->select('eventinstance', 'ei')
      ->fields('ei', ['vid'])
      ->condition('ei.id', $instanceId)
      ->execute()
      ->fetchField();

    if ($currentRevisionId === FALSE) {
      // The instance itself no longer exists (contrib's older instance-
      // rebuild behavior used to delete instances outright rather than
      // archiving them) — this stale row is unreadable garbage with nothing
      // to repoint onto. Delete it via the entity storage, same delete-not-
      // save discipline as the redundant-row branch below.
      _access_events_delete_stale_cms_row($storage, $database, $staleId);
      $sandbox['orphaned']++;
      $sandbox['processed']++;
      continue;
    }

    $correctId = $database->select('content_moderation_state_field_data', 'cms')
      ->fields('cms', ['id'])
      ->condition('cms.content_entity_type_id', 'eventinstance')
      ->condition('cms.content_entity_id', $instanceId)
      ->condition('cms.workflow', 'editorial_eventinstance')
      ->execute()
      ->fetchField();

    if ($correctId !== FALSE) {
      // A correctly-keyed entity already covers this instance — the stale
      // row is redundant. Delete the entity (never save it).
      _access_events_delete_stale_cms_row($storage, $database, $staleId);
      $sandbox['deleted']++;
    }
    else {
      $database->update('content_moderation_state_field_data')
        ->fields([
          'workflow' => 'editorial_eventinstance',
          'content_entity_revision_id' => $currentRevisionId,
        ])
        ->condition('id', $staleId)
        ->execute();
      // Scoped to the single row matching field_data's revision_id — the
      // CMS entity's current/default revision. Older CMS revision rows
      // sharing this id are deliberately left keyed to the retired
      // workflow; see this function's docblock.
      $database->update('content_moderation_state_field_revision')
        ->fields([
          'workflow' => 'editorial_eventinstance',
          'content_entity_revision_id' => $currentRevisionId,
        ])
        ->condition('id', $staleId)
        ->condition('revision_id', $currentCmsRevisionId)
        ->execute();
      $sandbox['repointed']++;
    }

    $sandbox['processed']++;
  }

  $sandbox['#finished'] = empty($sandbox['ids']) ? 1 : ($sandbox['processed'] / $sandbox['total']);

  if ($sandbox['#finished'] === 1) {
    $storage->resetCache();
    return t('Re-keyed @total eventinstance moderation-state rows: @deleted redundant stale rows deleted, @repointed rows repointed onto the instance workflow, @orphaned orphaned rows for deleted instances removed.', [
      '@total' => $sandbox['total'],
      '@deleted' => $sandbox['deleted'],
      '@repointed' => $sandbox['repointed'],
      '@orphaned' => $sandbox['orphaned'],
    ]);
  }
}

/**
 * Deletes one stale content_moderation_state row via the entity storage.
 *
 * Never ->save() (see this file's docblocks above for why) — delete() is
 * always the right operation for a row this migration is retiring outright,
 * whether it is redundant (a correctly-keyed entity already covers the
 * instance) or orphaned (the instance itself no longer exists). Falls back
 * to direct deletes across all four content_moderation_state tables for
 * this id when the entity fails to load — an orphaned row's OWN data can be
 * inconsistent enough (e.g. a content_entity_id the storage's own internal
 * lookups choke on) that entity-API deletion isn't guaranteed to succeed,
 * and retiring the row is the point either way.
 */
function _access_events_delete_stale_cms_row($storage, $database, int $staleId): void {
  $staleEntity = $storage->load($staleId);
  if ($staleEntity) {
    $storage->delete([$staleEntity]);
    return;
  }
  foreach (['content_moderation_state_field_revision', 'content_moderation_state_field_data', 'content_moderation_state_revision', 'content_moderation_state'] as $table) {
    $database->delete($table)->condition('id', $staleId)->execute();
  }
}

/**
 * Consumes the retired series-cancel memory into the individually_cancelled flag.
 *
 * Before individually_cancelled existed, a series-wide cancellation
 * remembered — in the access_events.series_cancel keyvalue collection,
 * keyed by series id — which of that series' occurrences were NOT meant to
 * be treated as individually cancelled once the series was later restored
 * (i.e. everything else in the series at the time of the sweep WAS
 * individually cancelled in spirit, even though no flag existed yet to
 * record it). This hook is the collection's last reader: for every
 * remembered series, any of its still-archived, not-verifiably-past
 * instances whose id is absent from the remembered set gets the flag
 * (written via a syncing save, so no revision is added and the reaction
 * layer — whose guards all skip syncing saves — stays inert). The
 * collection is deleted once every series has been consumed, so this hook
 * is a no-op on any later run.
 */
function access_events_deploy_0002_consume_cancel_memory() {
  $keyValue = \Drupal::keyValue('access_events.series_cancel');
  $remembered = $keyValue->getAll();

  if (!$remembered) {
    return t('No series-cancel memory to consume.');
  }

  $etm = \Drupal::entityTypeManager();
  $storage = $etm->getStorage('eventinstance');
  $now = \Drupal::time()->getRequestTime();
  $flagged = 0;

  foreach ($remembered as $seriesId => $notIndividuallyCancelledIds) {
    $notIndividuallyCancelledIds = is_array($notIndividuallyCancelledIds) ? $notIndividuallyCancelledIds : [];

    $instanceIds = $storage->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE)
      ->execute();
    if (!$instanceIds) {
      continue;
    }

    foreach ($storage->loadMultiple($instanceIds) as $instance) {
      if ($instance->get('moderation_state')->value !== 'archived') {
        continue;
      }
      $end = $instance->get('date')->end_value;
      if (!\Drupal\access_events\RegistrantCounter::endIsNotVerifiablyPast($end, $now)) {
        continue;
      }
      if (in_array((int) $instance->id(), array_map('intval', $notIndividuallyCancelledIds), TRUE)) {
        continue;
      }

      $instance->setSyncing(TRUE);
      $instance->set('individually_cancelled', TRUE);
      $instance->save();
      $flagged++;
    }
  }

  $keyValue->deleteAll();

  return t('Consumed series-cancel memory for @series series: flagged @flagged instances as individually cancelled.', [
    '@series' => count($remembered),
    '@flagged' => $flagged,
  ]);
}

/**
 * Backfills individually_cancelled for archived instances under a published series.
 *
 * Under the current model, an eventinstance can only be archived while its
 * series is published if it was individually cancelled — a series-wide
 * cancel sweep archives every occurrence AND takes the series itself out of
 * published, so archived-under-published is otherwise unreachable. Any
 * instance in that shape predates the flag and never got 0002's memory-
 * based backfill (its series was never swept, or was restored before the
 * flag existed), so it is caught here directly: query content_moderation_
 * state ENTITIES for archived eventinstance rows (moderation_state is a
 * content_moderation computed field, not something an eventinstance entity
 * query can filter on), then check each one's series default revision.
 * Already-flagged instances are left untouched. Flag writes are syncing
 * saves, matching 0002.
 */
function access_events_deploy_0003_backfill_individually_cancelled() {
  $etm = \Drupal::entityTypeManager();
  $cmsStorage = $etm->getStorage('content_moderation_state');
  $instanceStorage = $etm->getStorage('eventinstance');
  $seriesStorage = $etm->getStorage('eventseries');

  $archivedIds = $cmsStorage->getQuery()
    ->condition('content_entity_type_id', 'eventinstance')
    ->condition('workflow', 'editorial_eventinstance')
    ->condition('moderation_state', 'archived')
    ->accessCheck(FALSE)
    ->execute();

  if (!$archivedIds) {
    return t('No archived eventinstance moderation-state rows found.');
  }

  $instanceIds = [];
  foreach ($cmsStorage->loadMultiple($archivedIds) as $cmsEntity) {
    $instanceIds[] = (int) $cmsEntity->get('content_entity_id')->value;
  }
  $instanceIds = array_unique($instanceIds);

  $flagged = 0;
  foreach ($instanceStorage->loadMultiple($instanceIds) as $instance) {
    if ((bool) $instance->get('individually_cancelled')->value) {
      continue;
    }
    $seriesId = (int) $instance->get('eventseries_id')->target_id;
    $series = $seriesId ? $seriesStorage->load($seriesId) : NULL;
    if (!$series || !$series->isPublished()) {
      continue;
    }

    $instance->setSyncing(TRUE);
    $instance->set('individually_cancelled', TRUE);
    $instance->save();
    $flagged++;
  }

  return t('Backfilled individually_cancelled on @flagged archived-under-published instances.', [
    '@flagged' => $flagged,
  ]);
}
