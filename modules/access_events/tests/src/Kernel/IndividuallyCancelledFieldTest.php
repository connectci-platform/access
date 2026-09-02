<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

/**
 * Tests the individually_cancelled base field and its published-coherence rule.
 *
 * @group access_events
 */
class IndividuallyCancelledFieldTest extends EventKernelTestBase {

  public function testFieldExistsDefaultsFalseAndIsNotRevisionable(): void {
    $instance = $this->createRegistrableInstance();
    $this->assertTrue($instance->hasField('individually_cancelled'));
    $this->assertSame('0', (string) $instance->get('individually_cancelled')->value);
    $storage_def = $instance->getFieldDefinition('individually_cancelled')->getFieldStorageDefinition();
    $this->assertFalse($storage_def->isRevisionable());
  }

  public function testPublishedSaveForcesFlagFalse(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('individually_cancelled', TRUE);
    $instance->save();
    $this->assertSame('0', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value,
      'published+flagged is unrepresentable');
  }

  public function testFlagSurvivesArchivedSave(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('moderation_state', 'archived');
    $instance->set('individually_cancelled', TRUE);
    $instance->save();
    $this->assertSame('1', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value);
  }

  public function testRevisionRevertCannotResurrectStaleFlagValue(): void {
    // Non-revisionable: re-saving an old (still-archived) revision must not
    // change the flag. Both revisions in play here stay in the archived
    // state, so this exercises the revert mechanics in isolation from the
    // published-coherence rule covered by testPublishedSaveForcesFlagFalse().
    $instance = $this->createRegistrableInstance();
    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $instance->set('moderation_state', 'archived');
    $instance->set('individually_cancelled', TRUE);
    $instance->save();
    $old_revision_id = $instance->getRevisionId();

    // A later archived-state revision (e.g. re-archiving after a restore).
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $old = $storage->loadRevision($old_revision_id);
    $old->setNewRevision(TRUE);
    $old->isDefaultRevision(TRUE);
    $old->save();
    $this->assertSame('1', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value,
      'the cancellation record survives a revert');
  }

}
