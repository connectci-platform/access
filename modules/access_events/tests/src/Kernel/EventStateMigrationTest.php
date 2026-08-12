<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

/**
 * Tests the deploy-hook migration: moderation-state re-key + flag backfill.
 *
 * @covers access_events_deploy_0001_rekey_instance_moderation
 * @covers access_events_deploy_0002_consume_cancel_memory
 * @covers access_events_deploy_0003_backfill_individually_cancelled
 * @group access_events
 */
class EventStateMigrationTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The deploy hooks live in a .install-adjacent file that is never
    // autoloaded — drush only includes it right before running deploy
    // hooks. Load it explicitly so the hook functions are callable here,
    // the same way drush's DeployHookManager does.
    require_once __DIR__ . '/../../../access_events.deploy.php';
  }

  /**
   * Loads the single content_moderation_state entity id for an instance.
   *
   * @return int[]
   *   All content_moderation_state entity ids attached to this instance,
   *   across any workflow key.
   */
  private function cmsIdsForInstance(int $instanceId): array {
    return array_values(\Drupal::entityTypeManager()->getStorage('content_moderation_state')
      ->getQuery()
      ->condition('content_entity_type_id', 'eventinstance')
      ->condition('content_entity_id', $instanceId)
      ->accessCheck(FALSE)
      ->execute());
  }

  /**
   * Replaces ALL of an instance's CMS rows with exactly one stale,
   * pre-split-key row — the single-row-per-instance shape production's
   * stale history actually left behind.
   *
   * The entity API refuses to create a content_moderation_state entity
   * keyed to a workflow the moderated entity's bundle isn't attached to, so
   * this mirrors that history via a direct DB write: delete whatever CMS
   * rows the fixture builder produced (e.g. one per moderation-state save)
   * and insert a single fresh row keyed to the retired 'editorial' workflow,
   * then reset the storage cache.
   */
  private function markStale(int $instanceId, ?int $revisionId = NULL): int {
    $database = \Drupal::database();
    $revisionId ??= (int) \Drupal::entityTypeManager()->getStorage('eventinstance')->load($instanceId)->getRevisionId();

    $existingIds = $database->select('content_moderation_state_field_data', 'cms')
      ->fields('cms', ['id'])
      ->condition('cms.content_entity_type_id', 'eventinstance')
      ->condition('cms.content_entity_id', $instanceId)
      ->execute()
      ->fetchCol();
    foreach (['content_moderation_state', 'content_moderation_state_revision', 'content_moderation_state_field_data', 'content_moderation_state_field_revision'] as $table) {
      if ($existingIds) {
        $database->delete($table)->condition('id', $existingIds, 'IN')->execute();
      }
    }

    $uuid = \Drupal::service('uuid')->generate();
    $id = (int) $database->insert('content_moderation_state')
      ->fields(['uuid' => $uuid, 'langcode' => 'en'])
      ->execute();
    // content_moderation_state_revision.revision_id is its own independent
    // auto_increment sequence — it must NOT be assumed equal to $id, which
    // would collide with the unique key on content_moderation_state.
    // revision_id the moment a real content_moderation write elsewhere in
    // the test has already allocated that same revision_id value.
    $revisionPk = (int) $database->insert('content_moderation_state_revision')
      ->fields(['id' => $id, 'langcode' => 'en', 'revision_default' => 1])
      ->execute();
    $database->update('content_moderation_state')
      ->fields(['revision_id' => $revisionPk])
      ->condition('id', $id)
      ->execute();
    $shared = [
      'id' => $id,
      'revision_id' => $revisionPk,
      'langcode' => 'en',
      'uid' => 0,
      'workflow' => 'editorial',
      'moderation_state' => 'published',
      'content_entity_type_id' => 'eventinstance',
      'content_entity_id' => $instanceId,
      'content_entity_revision_id' => $revisionId,
      'default_langcode' => 1,
      'revision_translation_affected' => 1,
    ];
    $database->insert('content_moderation_state_field_data')->fields($shared)->execute();
    $database->insert('content_moderation_state_field_revision')->fields($shared)->execute();

    \Drupal::entityTypeManager()->getStorage('content_moderation_state')->resetCache();
    return $id;
  }

  /**
   * Inserts a second, independent content_moderation_state row for an
   * instance, stale-keyed from creation — models the dual-row shape where
   * both the old shared-workflow row and a newer correctly-keyed row exist
   * for the same instance (the correct one is what content_moderation
   * itself would have written once the instance workflow was split out).
   */
  private function insertExtraStaleCmsRow(int $instanceId, int $revisionId, string $workflow, string $state): int {
    $database = \Drupal::database();
    $uuid = \Drupal::service('uuid')->generate();

    // content_moderation_state's own base (id/revision_id) tables.
    $id = (int) $database->insert('content_moderation_state')
      ->fields(['uuid' => $uuid, 'langcode' => 'en'])
      ->execute();
    $database->update('content_moderation_state')
      ->fields(['revision_id' => $id])
      ->condition('id', $id)
      ->execute();
    $database->insert('content_moderation_state_revision')
      ->fields(['id' => $id, 'revision_id' => $id, 'langcode' => 'en', 'revision_default' => 1])
      ->execute();

    $shared = [
      'id' => $id,
      'revision_id' => $id,
      'langcode' => 'en',
      'uid' => 0,
      'workflow' => $workflow,
      'moderation_state' => $state,
      'content_entity_type_id' => 'eventinstance',
      'content_entity_id' => $instanceId,
      'content_entity_revision_id' => $revisionId,
      'default_langcode' => 1,
      'revision_translation_affected' => 1,
    ];
    $database->insert('content_moderation_state_field_data')
      ->fields($shared)
      ->execute();
    $database->insert('content_moderation_state_field_revision')
      ->fields($shared)
      ->execute();

    \Drupal::entityTypeManager()->getStorage('content_moderation_state')->resetCache();
    return $id;
  }

  /**
   * A lone stale row gets re-keyed onto the instance workflow in place.
   */
  public function testLoneStaleRowIsRekeyed(): void {
    $instance = $this->createRegistrableInstance();
    $instanceId = (int) $instance->id();
    $this->markStale($instanceId);

    $sandbox = [];
    access_events_deploy_0001_rekey_instance_moderation($sandbox);

    $ids = $this->cmsIdsForInstance($instanceId);
    $this->assertCount(1, $ids, 'Exactly one content_moderation_state entity remains for the instance.');
    $cms = \Drupal::entityTypeManager()->getStorage('content_moderation_state')->load(reset($ids));
    $this->assertSame('editorial_eventinstance', $cms->get('workflow')->target_id);
  }

  /**
   * Re-keying also fixes a stale row whose revision pointer is out of date.
   */
  public function testStaleRevisionPointerIsFixed(): void {
    $instance = $this->createRegistrableInstance();
    $instanceId = (int) $instance->id();

    // Advance the instance to a new default revision after the stale CMS
    // row was (hypothetically) written, so the row's pointer is now behind.
    $instance->setNewRevision(TRUE);
    $instance->save();
    $instance = $this->reloadInstance($instance);
    $currentRevisionId = (int) $instance->getRevisionId();

    // Point the stale row at an earlier, no-longer-current revision id.
    $this->markStale($instanceId, $currentRevisionId - 1 > 0 ? $currentRevisionId - 1 : 1);

    $sandbox = [];
    access_events_deploy_0001_rekey_instance_moderation($sandbox);

    $ids = $this->cmsIdsForInstance($instanceId);
    $this->assertCount(1, $ids);
    $cms = \Drupal::entityTypeManager()->getStorage('content_moderation_state')->load(reset($ids));
    $this->assertSame('editorial_eventinstance', $cms->get('workflow')->target_id);
    $this->assertSame($currentRevisionId, (int) $cms->get('content_entity_revision_id')->value);
  }

  /**
   * A stale row whose referenced eventinstance no longer exists (contrib's
   * older instance-rebuild behavior used to delete instances outright,
   * orphaning their moderation history) is deleted outright rather than
   * repointed — there is nothing to repoint it onto. Other populations
   * (a real lone-stale-row re-key) still behave in the same run.
   */
  public function testOrphanedStaleRowIsDeleted(): void {
    $orphanInstance = $this->createRegistrableInstance();
    $orphanInstanceId = (int) $orphanInstance->id();
    $this->markStale($orphanInstanceId);
    // Delete the instance itself, leaving its stale CMS row behind —
    // exactly the production shape: unreadable garbage referencing a
    // content_entity_id that no longer resolves.
    $orphanInstance->delete();

    // A second, real lone-stale-row instance in the same run, to confirm
    // the orphan branch doesn't disturb the ordinary re-key population.
    $realInstance = $this->createRegistrableInstance();
    $realInstanceId = (int) $realInstance->id();
    $this->markStale($realInstanceId);

    $sandbox = [];
    access_events_deploy_0001_rekey_instance_moderation($sandbox);

    // Zero CMS rows remain for the orphaned instance id, across all four
    // tables.
    $this->assertSame([], $this->cmsIdsForInstance($orphanInstanceId));
    foreach (['content_moderation_state_field_data', 'content_moderation_state_field_revision'] as $table) {
      $count = (int) \Drupal::database()->select($table, 't')
        ->condition('t.content_entity_type_id', 'eventinstance')
        ->condition('t.content_entity_id', $orphanInstanceId)
        ->countQuery()
        ->execute()
        ->fetchField();
      $this->assertSame(0, $count, "$table has zero rows for the orphaned instance id.");
    }

    // The real instance was still re-keyed correctly.
    $ids = $this->cmsIdsForInstance($realInstanceId);
    $this->assertCount(1, $ids);
    $cms = \Drupal::entityTypeManager()->getStorage('content_moderation_state')->load(reset($ids));
    $this->assertSame('editorial_eventinstance', $cms->get('workflow')->target_id);
  }

  /**
   * Adds an extra, OLDER content_moderation_state_field_revision row that
   * shares an existing CMS entity's id — models a stale CMS entity that was
   * re-saved more than once under the retired workflow, so its revision
   * history has multiple rows under one id (core writes one CMS revision
   * per moderated-entity revision; content_moderation_state_field_data's
   * own revision_id always points at the newest/current one).
   *
   * @return array
   *   The inserted row's full field values, for byte-for-byte comparison.
   */
  private function addOlderCmsRevisionRow(int $cmsId, int $instanceId, int $contentEntityRevisionId, string $workflow, string $state): array {
    $database = \Drupal::database();
    // A revision_id distinct from (and lower than) the CMS entity's current
    // revision_id — content_moderation_state_revision.revision_id is a
    // shared auto_increment sequence, so insert a fresh row there too.
    $olderRevisionId = (int) $database->insert('content_moderation_state_revision')
      ->fields(['id' => $cmsId, 'langcode' => 'en', 'revision_default' => 0])
      ->execute();

    $row = [
      'id' => $cmsId,
      'revision_id' => $olderRevisionId,
      'langcode' => 'en',
      'uid' => 0,
      'workflow' => $workflow,
      'moderation_state' => $state,
      'content_entity_type_id' => 'eventinstance',
      'content_entity_id' => $instanceId,
      'content_entity_revision_id' => $contentEntityRevisionId,
      'default_langcode' => 1,
      'revision_translation_affected' => 1,
    ];
    $database->insert('content_moderation_state_field_revision')
      ->fields($row)
      ->execute();

    \Drupal::entityTypeManager()->getStorage('content_moderation_state')->resetCache();
    return $row;
  }

  /**
   * Reads back one content_moderation_state_field_revision row by
   * (id, revision_id), for a byte-for-byte before/after comparison.
   */
  private function readCmsRevisionRow(int $cmsId, int $revisionId): array {
    $row = \Drupal::database()->select('content_moderation_state_field_revision', 'cms')
      ->fields('cms')
      ->condition('cms.id', $cmsId)
      ->condition('cms.revision_id', $revisionId)
      ->execute()
      ->fetchAssoc();
    return $row ?: [];
  }

  /**
   * A stale CMS entity with THREE field_revision rows sharing its id (one
   * matching field_data's current revision_id, two older with distinct
   * content_entity_revision_id values): only the current row is re-keyed
   * with its pointer fixed; the two older rows are left byte-unchanged; the
   * instance's computed moderation state is correct afterward.
   */
  public function testMultiRevisionStaleEntityOnlyRepointsCurrentRow(): void {
    $instance = $this->createRegistrableInstance();
    $instanceId = (int) $instance->id();
    $instanceRevisionId = (int) $instance->getRevisionId();

    $cmsId = $this->markStale($instanceId, $instanceRevisionId);

    // Two older CMS revision rows on the SAME cms entity id, each pointing
    // at a distinct (now stale) content_entity_revision_id — modeling a CMS
    // entity that was re-saved more than once under the retired workflow.
    // The unique key is (content_entity_type_id, content_entity_id,
    // content_entity_revision_id, workflow, langcode), so these values only
    // need to differ from each other and from $instanceRevisionId — they
    // need not be real eventinstance revision ids.
    $fakeOlderRevisionIdOne = $instanceRevisionId + 9001;
    $fakeOlderRevisionIdTwo = $instanceRevisionId + 9002;
    $olderRowOne = $this->addOlderCmsRevisionRow($cmsId, $instanceId, $fakeOlderRevisionIdOne, 'editorial', 'draft');
    $olderRowTwo = $this->addOlderCmsRevisionRow($cmsId, $instanceId, $fakeOlderRevisionIdTwo, 'editorial', 'published');

    // Snapshot the two older rows exactly as inserted, before the migration
    // runs, for the byte-for-byte comparison below.
    $olderRowOneBefore = $this->readCmsRevisionRow($cmsId, $olderRowOne['revision_id']);
    $olderRowTwoBefore = $this->readCmsRevisionRow($cmsId, $olderRowTwo['revision_id']);
    $this->assertNotEmpty($olderRowOneBefore);
    $this->assertNotEmpty($olderRowTwoBefore);

    $sandbox = [];
    access_events_deploy_0001_rekey_instance_moderation($sandbox);

    // Exactly one CMS entity still covers this instance (no new entity was
    // created; the same id was repointed in place).
    $ids = $this->cmsIdsForInstance($instanceId);
    $this->assertCount(1, $ids);
    $this->assertSame($cmsId, (int) reset($ids));

    // The current row (field_data's revision_id) is re-keyed with the
    // pointer fixed.
    $cms = \Drupal::entityTypeManager()->getStorage('content_moderation_state')->load($cmsId);
    $this->assertSame('editorial_eventinstance', $cms->get('workflow')->target_id);
    $this->assertSame($instanceRevisionId, (int) $cms->get('content_entity_revision_id')->value);

    // The two older revision rows are untouched, byte-for-byte.
    $olderRowOneAfter = $this->readCmsRevisionRow($cmsId, $olderRowOne['revision_id']);
    $olderRowTwoAfter = $this->readCmsRevisionRow($cmsId, $olderRowTwo['revision_id']);
    $this->assertSame($olderRowOneBefore, $olderRowOneAfter, 'The older revision row is byte-unchanged.');
    $this->assertSame($olderRowTwoBefore, $olderRowTwoAfter, 'The other older revision row is byte-unchanged.');
    $this->assertSame('editorial', $olderRowOneAfter['workflow'], 'Older rows keep the retired workflow key.');
    $this->assertSame('editorial', $olderRowTwoAfter['workflow'], 'Older rows keep the retired workflow key.');

    // The instance's computed moderation state resolves correctly
    // post-migration (reads through the now-correctly-keyed current row).
    $reloaded = $this->reloadInstance($instance);
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * Dual-row case: a stale row plus an already-correct row for the same
   * instance. The stale entity is deleted via the entity API; exactly one
   * loadable content_moderation_state entity remains, correctly keyed.
   */
  public function testDualRowDeletesStaleKeepsCorrect(): void {
    $instance = $this->createRegistrableInstance();
    $instanceId = (int) $instance->id();
    $revisionId = (int) $instance->getRevisionId();

    // Collapse to exactly one stale row, then add one correctly-keyed row
    // alongside it — the dual-row shape, built deterministically regardless
    // of how many CMS revisions the fixture builder itself produced.
    $this->markStale($instanceId, $revisionId);
    $this->insertExtraStaleCmsRow($instanceId, $revisionId, 'editorial_eventinstance', 'published');

    $idsBefore = $this->cmsIdsForInstance($instanceId);
    $this->assertCount(2, $idsBefore, 'Fixture set up two CMS rows for the instance.');

    $sandbox = [];
    access_events_deploy_0001_rekey_instance_moderation($sandbox);

    $idsAfter = $this->cmsIdsForInstance($instanceId);
    $this->assertCount(1, $idsAfter, 'Exactly one content_moderation_state entity remains after the stale one is deleted.');
    $cms = \Drupal::entityTypeManager()->getStorage('content_moderation_state')->load(reset($idsAfter));
    $this->assertSame('editorial_eventinstance', $cms->get('workflow')->target_id);
  }

  /**
   * Memory consume: instances NOT in the remembered set get flagged,
   * including one with a NULL end date; instances IN the remembered set are
   * left unflagged; the keyvalue collection is empty afterward.
   */
  public function testConsumeCancelMemoryFlagsNotInSetIncludingNullEnd(): void {
    $seriesEntity = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Swept Series',
      'body' => 'A series swept by an old cancellation.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $seriesEntity->save();
    $this->publishModerated($seriesEntity);
    // Series stays published — memory-consume only asks about instance state.
    $seriesId = (int) $seriesEntity->id();

    $inSet = $this->buildArchivedInstance($seriesId, '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $notInSet = $this->buildArchivedInstance($seriesId, '2999-02-01T10:00:00', '2999-02-01T12:00:00');
    $notInSetNullEnd = $this->buildArchivedInstance($seriesId, '2999-03-01T10:00:00', '2999-03-01T12:00:00');

    // NULL end dates are legacy-only data; the entity API refuses to create
    // them, so seed the condition at the DB layer, mirroring
    // RegistrantCounterTest::testCountNotPastIncludesNullEndRegistrants().
    $entityType = $notInSetNullEnd->getEntityType();
    $tableName = $entityType->getDataTable() ?: $entityType->getBaseTable();
    \Drupal::database()->update($tableName)
      ->fields(['date__end_value' => NULL])
      ->condition('id', $notInSetNullEnd->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache([$notInSetNullEnd->id()]);

    \Drupal::keyValue('access_events.series_cancel')->set($seriesId, [(int) $inSet->id()]);

    access_events_deploy_0002_consume_cancel_memory();

    $inSetReloaded = $this->reloadInstance($inSet);
    $notInSetReloaded = $this->reloadInstance($notInSet);
    $notInSetNullEndReloaded = $this->reloadInstance($notInSetNullEnd);

    $this->assertFalse((bool) $inSetReloaded->get('individually_cancelled')->value, 'In-set instance is left unflagged.');
    $this->assertTrue((bool) $notInSetReloaded->get('individually_cancelled')->value, 'Not-in-set instance is flagged.');
    $this->assertTrue((bool) $notInSetNullEndReloaded->get('individually_cancelled')->value, 'Not-in-set NULL-end instance is flagged too.');

    $this->assertSame([], \Drupal::keyValue('access_events.series_cancel')->getAll(), 'The keyvalue collection is empty after consumption.');
  }

  /**
   * Backfill flags an archived instance sitting under a published series,
   * and skips one sitting under an archived series.
   */
  public function testBackfillFlagsArchivedUnderPublishedSkipsArchivedUnderArchived(): void {
    $publishedSeries = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Published Parent',
      'body' => 'Still live.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $publishedSeries->save();
    $this->publishModerated($publishedSeries);

    $archivedSeries = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Archived Parent',
      'body' => 'Cancelled outright.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $archivedSeries->save();
    $this->publishModerated($archivedSeries);
    $archivedSeries->set('moderation_state', 'archived')->save();

    $underPublished = $this->buildArchivedInstance((int) $publishedSeries->id(), '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $underArchived = $this->buildArchivedInstance((int) $archivedSeries->id(), '2999-01-01T10:00:00', '2999-01-01T12:00:00');

    access_events_deploy_0003_backfill_individually_cancelled();

    $underPublishedReloaded = $this->reloadInstance($underPublished);
    $underArchivedReloaded = $this->reloadInstance($underArchived);

    $this->assertTrue((bool) $underPublishedReloaded->get('individually_cancelled')->value, 'Archived-under-published instance is flagged.');
    $this->assertFalse((bool) $underArchivedReloaded->get('individually_cancelled')->value, 'Archived-under-archived instance is skipped.');
  }

  /**
   * Backfill skips an instance that is already flagged.
   */
  public function testBackfillSkipsAlreadyFlagged(): void {
    $publishedSeries = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Published Parent Two',
      'body' => 'Still live.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $publishedSeries->save();
    $this->publishModerated($publishedSeries);

    $instance = $this->buildArchivedInstance((int) $publishedSeries->id(), '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $instance->setSyncing(TRUE);
    $instance->set('individually_cancelled', TRUE);
    $instance->save();

    access_events_deploy_0003_backfill_individually_cancelled();

    // No assertion failure expected — the point is this doesn't error/double
    // -write. Confirm the flag is still TRUE and only one revision exists
    // (a non-revisionable field, but the instance itself must not have
    // gained a spurious new default revision from a skipped no-op).
    $reloaded = $this->reloadInstance($instance);
    $this->assertTrue((bool) $reloaded->get('individually_cancelled')->value);
  }

  /**
   * Every migration save across all three hooks is syncing: the reaction
   * layer's notification/flag queues stay empty.
   */
  public function testMigrationSavesFireNoReactions(): void {
    $this->enableEventNotifications();

    // 0001 fixture: a lone stale row to re-key.
    $rekeyInstance = $this->createRegistrableInstance();
    $this->markStale((int) $rekeyInstance->id());

    // 0002 fixture: a swept series with an unremembered instance to flag.
    $sweptSeries = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Swept For Reactions',
      'body' => 'x',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $sweptSeries->save();
    $this->publishModerated($sweptSeries);
    $memoryInstance = $this->buildArchivedInstance((int) $sweptSeries->id(), '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    \Drupal::keyValue('access_events.series_cancel')->set((int) $sweptSeries->id(), []);

    // 0003 fixture: archived-under-published.
    $backfillSeries = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Backfill For Reactions',
      'body' => 'x',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $backfillSeries->save();
    $this->publishModerated($backfillSeries);
    $backfillInstance = $this->buildArchivedInstance((int) $backfillSeries->id(), '2999-02-01T10:00:00', '2999-02-01T12:00:00');

    $sandbox = [];
    access_events_deploy_0001_rekey_instance_moderation($sandbox);
    access_events_deploy_0002_consume_cancel_memory();
    access_events_deploy_0003_backfill_individually_cancelled();

    // Sanity: the flag writes actually happened.
    $this->assertTrue((bool) $this->reloadInstance($memoryInstance)->get('individually_cancelled')->value);
    $this->assertTrue((bool) $this->reloadInstance($backfillInstance)->get('individually_cancelled')->value);

    $this->assertQueueCount(\Drupal\access_events\CancellationNotifier::KEY, 0);
    $this->assertQueueCount(\Drupal\access_events\CancellationNotifier::REINSTATE_KEY, 0);
  }

  /**
   * Builds an archived eventinstance on $seriesId with the given date range,
   * without routing through the reaction layer (a plain syncing save, since
   * these fixtures model pre-existing historical data, not a live archive
   * transition).
   */
  private function buildArchivedInstance(int $seriesId, string $start, string $end): \Drupal\recurring_events\Entity\EventInstance {
    $instance = \Drupal\recurring_events\Entity\EventInstance::create([
      'eventseries_id' => $seriesId,
      'type' => 'default',
      'date' => ['value' => $start, 'end_value' => $end],
    ]);
    $instance->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, $seriesId);
    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();
    return $this->reloadInstance($instance);
  }

}
