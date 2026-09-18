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
      'field_name' => 'field_rp_xdmod_resource_id',
      'entity_type' => 'node',
      'type' => 'integer',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_rp_xdmod_resource_id',
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();

    require_once __DIR__ . '/../../../access_affinitygroup.install';
  }

  private function makeResourceNode(string $title, ?int $xdmodId): Node {
    $values = ['type' => 'access_active_resources_from_cid', 'title' => $title, 'status' => 1];
    if ($xdmodId !== NULL) {
      $values['field_rp_xdmod_resource_id'] = $xdmodId;
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
    $live = $this->makeResourceNode('Anvil CPU', 3097);
    $deadNid = (int) $live->id() + 5000;

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
    $live = $this->makeResourceNode('Bridges-2 RM', 2900);
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
    $live = $this->makeResourceNode('Delta GPU', 3032);
    $other = $this->makeResourceNode('Delta GPU alternate', NULL);

    // Healthy row on a different live node for the same resource.
    $this->insertRow(404, (int) $other->id(), 3032, 'CDA080011');
    // Broken row for the same resource.
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

    $this->assertEquals((int) $other->id(), (int) $healthy, 'A row on a live node must be left alone.');
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
    $a = $this->makeResourceNode('Shared resource A', NULL);
    $b = $this->makeResourceNode('Shared resource B', NULL);

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
   * Running the hook twice changes nothing the second time.
   */
  public function testHookIsIdempotent(): void {
    $live = $this->makeResourceNode('Expanse CPU', 2899);
    $this->insertRow(505, (int) $live->id() + 5000, 2899, 'CDA080011');

    $this->runHook();
    $afterFirst = $this->allRows();

    $this->runHook();

    $this->assertEquals($afterFirst, $this->allRows());
  }

  /**
   * Counts rows a reader could actually resolve to a resource node.
   *
   * Mirrors resolveRpNidsToResourceInfo(): inner join plus the bundle and
   * published conditions. Without those an unpublished or wrong-bundle node
   * would count as resolvable here while the controller still dropped it.
   */
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
