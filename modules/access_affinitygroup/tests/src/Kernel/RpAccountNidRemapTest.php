<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Covers the update hook that repoints rows at resource nodes that still exist.
 *
 * @group access_affinitygroup
 */
class RpAccountNidRemapTest extends KernelTestBase {

  protected static $modules = [
    'access', 'access_affinitygroup', 'user', 'system', 'node', 'field', 'text', 'filter', 'key',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    $this->installConfig(['filter']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'RP'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();

    require_once __DIR__ . '/../../../access_affinitygroup.install';
  }

  private function makeResourceNode(string $title, ?string $globalId = NULL, bool $published = TRUE): Node {
    $values = [
      'type' => 'access_active_resources_from_cid',
      'title' => $title,
      'status' => $published ? 1 : 0,
    ];
    if ($globalId !== NULL) {
      $values['field_access_global_resource_id'] = $globalId;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  private function insertRow(int $uid, int $rpNid, int $resourceId, string $grant): void {
    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => $uid,
      'rp_nid' => $rpNid,
      'grant_number' => $grant,
      'project_id' => 4699,
      'resource_id' => $resourceId,
      'rp_username' => 'someone',
      'account_state' => 'active',
      'is_expired' => 0,
      'synced_at' => 1750000000,
    ])->execute();
  }

  private function runHook(): string {
    $sandbox = [];
    $message = '';
    do {
      $message = access_affinitygroup_update_10015($sandbox) ?: $message;
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    return $message;
  }

  /**
   * A row pointing at a deleted node is repointed at the live one.
   *
   * This is the case that hid resources from users: the row survives every
   * refresh (the prune keys on grant_number) and the controller drops the
   * whole group because the nid does not resolve.
   */
  public function testDanglingRowIsRemappedOntoTheLiveNode(): void {
    $live = $this->makeResourceNode('Anvil CPU', 'anvil.purdue.access-ci.org');
    $deadNid = (int) $live->id() + 5000;

    // A healthy row elsewhere is what lets the resolver identify the resource.
    $this->insertRow(100, (int) $live->id(), 3097, 'CDA080011');
    $this->insertRow(101, $deadNid, 3097, 'CDA080011');

    $this->runHook();

    $rpNid = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 101)
      ->execute()
      ->fetchField();

    $this->assertEquals((int) $live->id(), (int) $rpNid);
  }

  /**
   * The user-visible symptom, asserted end to end.
   *
   * A dangling row is invisible to any reader that joins to the node table,
   * which is what the RP accounts endpoint does. Before the hook the user's
   * resource cannot be resolved at all; after it, it can.
   */
  public function testResourceBecomesResolvableForTheUser(): void {
    $live = $this->makeResourceNode('Bridges-2 RM', 'bridges2-rm.psc.access-ci.org');
    $this->insertRow(200, (int) $live->id(), 2900, 'CDA080011');
    $this->insertRow(202, (int) $live->id() + 5000, 2900, 'CDA080011');

    $this->assertSame(0, $this->resolvableRowCount(202), 'Row should be unresolvable before the hook runs.');

    $this->runHook();

    $this->assertSame(1, $this->resolvableRowCount(202), 'Row should resolve to a live node after the hook.');
  }

  /**
   * A resource id with no live node anywhere is reported, never guessed at.
   */
  public function testUnresolvableResourceIsLeftAloneAndReported(): void {
    $orphanNid = 99001;
    $this->insertRow(303, $orphanNid, 7777, 'CDA080011');

    $message = $this->runHook();

    $rpNid = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 303)
      ->execute()
      ->fetchField();

    $this->assertEquals($orphanNid, (int) $rpNid, 'An unresolvable row must not be rewritten.');
    $this->assertStringContainsString('7777', $message);
  }

  /**
   * Rows already pointing at a live node are not touched.
   *
   * Guards against a remap that flattens every row for a resource onto one
   * node, which would rewrite healthy data.
   */
  public function testHealthyRowsAreNotRewritten(): void {
    $live = $this->makeResourceNode('Delta GPU', 'delta-gpu.ncsa.access-ci.org');

    // Healthy row, already on the live node.
    $this->insertRow(404, (int) $live->id(), 3032, 'CDA080011');
    // Broken row for the same resource, different user.
    $this->insertRow(405, (int) $live->id() + 5000, 3032, 'CDA080011');

    $this->runHook();

    $healthy = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 404)
      ->execute()
      ->fetchField();
    $repaired = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 405)
      ->execute()
      ->fetchField();

    $this->assertEquals((int) $live->id(), (int) $healthy, 'A row on a live node must be left alone.');
    $this->assertEquals((int) $live->id(), (int) $repaired);
  }

  /**
   * A resource reachable via two live nodes is left alone, not assigned one.
   *
   * The table-based fallback must not break a tie it cannot judge. Picking
   * the first match would silently move users onto an arbitrary resource
   * node, which is worse than leaving the row visibly broken.
   */
  public function testAmbiguousResourceIsNotGuessed(): void {
    // Two live nodes both legitimately serving resource 4242, and no
    // field_rp_xdmod_resource_id on either, so only the fallback can answer.
    $a = $this->makeResourceNode('Shared resource A', 'shared-a.example.access-ci.org');
    $b = $this->makeResourceNode('Shared resource B', 'shared-b.example.access-ci.org');

    $this->insertRow(606, (int) $a->id(), 4242, 'CDA080011');
    $this->insertRow(607, (int) $b->id(), 4242, 'CDA080011');

    $brokenNid = (int) $b->id() + 5000;
    $this->insertRow(608, $brokenNid, 4242, 'CDA080011');

    $message = $this->runHook();

    $stillBroken = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 608)
      ->execute()
      ->fetchField();

    $this->assertEquals(
      $brokenNid,
      (int) $stillBroken,
      'An ambiguous resource must be left untouched rather than assigned an arbitrary node.'
    );
    $this->assertStringContainsString('4242', $message);
  }

  /**
   * The same user holding one grant on both a dead and a live node.
   *
   * The primary key is (uid, rp_nid, grant_number), so naively rewriting
   * rp_nid here produces a key that already exists and MySQL aborts the
   * update mid-deploy. On production this shape covers 2,661 of the 6,267
   * affected rows, so it is the common case, not an edge case.
   */
  public function testSameUserOnDeadAndLiveNodeDoesNotCollide(): void {
    $live = $this->makeResourceNode('Anvil CPU', 'anvil.purdue.access-ci.org');
    $deadNid = (int) $live->id() + 5000;

    $this->insertRow(700, (int) $live->id(), 3097, 'CDA080011');
    $this->insertRow(700, $deadNid, 3097, 'CDA080011');

    // Must not throw a duplicate-key violation.
    $this->runHook();

    $rows = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 700)
      ->execute()
      ->fetchCol();

    $this->assertCount(1, $rows, 'The redundant duplicate must be removed, not duplicated.');
    $this->assertEquals((int) $live->id(), (int) reset($rows));
  }

  /**
   * A second run is a genuine no-op, asserted against work actually done.
   *
   * Comparing the table to itself would pass even if the hook did nothing, so
   * this pins that the first run repaired the row and the second changed
   * nothing further.
   */
  public function testSecondRunChangesNothingAfterARealRepair(): void {
    $live = $this->makeResourceNode('Expanse CPU', 'expanse.sdsc.access-ci.org');
    $this->insertRow(800, (int) $live->id(), 2899, 'CDA080011');
    $this->insertRow(801, (int) $live->id() + 5000, 2899, 'CDA080011');

    $this->assertSame(0, $this->resolvableRowCount(801), 'Precondition: the row starts broken.');

    $this->runHook();
    $this->assertSame(1, $this->resolvableRowCount(801), 'First run must actually repair the row.');
    $afterFirst = $this->allRows();

    $this->runHook();

    $this->assertEquals($afterFirst, $this->allRows(), 'Second run must change nothing.');
  }

  /**
   * An unpublished node is not a valid remap target.
   *
   * Unpublishing is a reversible editorial act; treating it as evidence would
   * let the hook move users onto a resource that is deliberately hidden.
   */
  public function testUnpublishedNodeIsNotUsedAsATarget(): void {
    $hidden = $this->makeResourceNode('Hidden resource', 'hidden.example.access-ci.org', FALSE);
    $brokenNid = (int) $hidden->id() + 5000;

    $this->insertRow(900, (int) $hidden->id(), 5555, 'CDA080011');
    $this->insertRow(901, $brokenNid, 5555, 'CDA080011');

    $this->runHook();

    $stillBroken = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 901)
      ->execute()
      ->fetchField();

    $this->assertEquals(
      $brokenNid,
      (int) $stillBroken,
      'An unpublished node must not be used as a remap target.'
    );
  }

  /**
   * Rows for an unrelated resource are untouched by a pass.
   */
  public function testOtherResourcesAreNotAffected(): void {
    $a = $this->makeResourceNode('Anvil CPU', 'anvil.purdue.access-ci.org');
    $b = $this->makeResourceNode('Delta GPU', 'delta-gpu.ncsa.access-ci.org');

    $this->insertRow(1000, (int) $a->id(), 3097, 'CDA080011');
    $this->insertRow(1001, (int) $a->id() + 5000, 3097, 'CDA080011');

    $healthyB = (int) $b->id();
    $this->insertRow(1002, $healthyB, 3032, 'TRA240016');

    $this->runHook();

    $untouched = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['rp_nid'])
      ->condition('uid', 1002)
      ->execute()
      ->fetchField();

    $this->assertEquals($healthyB, (int) $untouched);
  }

  /**
   * The before-image is recorded so the repair can be audited and reversed.
   */
  public function testBeforeImageIsRecorded(): void {
    $live = $this->makeResourceNode('Bridges-2 RM', 'bridges2-rm.psc.access-ci.org');
    $deadNid = (int) $live->id() + 5000;

    $this->insertRow(1100, (int) $live->id(), 2900, 'CDA080011');
    $this->insertRow(1101, $deadNid, 2900, 'CDA080011');

    $this->runHook();

    $logged = \Drupal::database()->select('access_user_rp_account_remap_log', 'l')
      ->fields('l', ['uid', 'rp_nid'])
      ->condition('uid', 1101)
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($logged, 'The pre-repair state must be recorded.');
    $this->assertEquals($deadNid, (int) $logged['rp_nid'], 'The log must hold the OLD nid.');
  }

  private function resolvableRowCount(int $uid): int {
    $query = \Drupal::database()->select('access_user_rp_account', 'a');
    $query->innerJoin('node_field_data', 'n', 'n.nid = a.rp_nid');
    return (int) $query
      ->condition('a.uid', $uid)
      ->condition('n.type', 'access_active_resources_from_cid')
      ->condition('n.status', 1)
      ->countQuery()->execute()->fetchField();
  }

  private function allRows(): array {
    return \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['uid', 'rp_nid', 'resource_id'])
      ->orderBy('uid')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

}
