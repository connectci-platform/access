<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Service\AllocationsClient;
use Drupal\access_affinitygroup\Service\RpAccountService;
use Drupal\access_affinitygroup\Service\XdusageClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * @group access_affinitygroup
 */
class RpAccountServiceTest extends KernelTestBase {

  use ProphecyTrait;

  protected static $modules = [
    'access_affinitygroup', 'user', 'system', 'node', 'field', 'text', 'filter', 'key',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    $this->installConfig(['filter']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'RP'])->save();

    \Drupal\field\Entity\FieldStorageConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    \Drupal\field\Entity\FieldConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();

    foreach (['field_xdusage_person_id' => 'integer', 'field_xdusage_person_synced' => 'timestamp'] as $f => $t) {
      \Drupal\field\Entity\FieldStorageConfig::create([
        'field_name' => $f, 'entity_type' => 'user', 'type' => $t,
      ])->save();
      \Drupal\field\Entity\FieldConfig::create([
        'field_name' => $f, 'entity_type' => 'user', 'bundle' => 'user',
      ])->save();
    }
  }

  protected function makeService($alloc, $xd): RpAccountService {
    return new RpAccountService(
      \Drupal::database(),
      \Drupal::entityTypeManager(),
      $alloc,
      $xd,
      \Drupal::cache(),
      \Drupal::service('logger.factory'),
      \Drupal::service('datetime.time'),
      \Drupal::service('lock'),
    );
  }

  public function testRefreshUserRpAccountsHappyPath(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();

    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
    ]);
    $user->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willReturn([
      ['grant_number' => 'PHY250173', 'title' => 'Halo finding', 'allocation_type' => 'Accelerate', 'grant_type' => 'Diss.'],
    ]);
    $alloc->getResourcesForUser('aaadhavan')->willReturn([
      ['cider_resource_id' => 'delta-cpu.ncsa.access-ci.org', 'billable_unit_type' => 'Core-hours'],
    ]);

    $xd = $this->prophesize(XdusageClient::class);
    $xd->lookupPerson('aaadhavan')->willReturn(['status' => 'found', 'person' => ['person_id' => 297776]]);
    $xd->getProjectsMap()->willReturn([
      'PHY250173' => [
        'delta-cpu.ncsa.access-ci.org' => [
          'project_id' => 66897,
          'resource_id' => 3031,
          'project_balance' => '245119.85',
          'project_end' => '2026-07-09',
          'project_state' => 'active',
          'is_expired' => FALSE,
          'billable_unit' => 'Core-hours',
        ],
      ],
    ]);
    $xd->getAccountForUser(66897, 3031, 297776)->willReturn([
      'portal_username' => 'aaadhavan',
      'account_state' => 'active',
    ]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    $row = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a')
      ->condition('uid', (int) $user->id())
      ->execute()
      ->fetchAssoc();
    $this->assertNotEmpty($row);
    $this->assertSame('aaadhavan', $row['rp_username']);
    $this->assertSame('active', $row['account_state']);
    $this->assertSame('PHY250173', $row['grant_number']);
    $this->assertSame('Halo finding', $row['grant_title']);
    $this->assertEquals(66897, $row['project_id']);
    $this->assertSame('Core-hours', $row['billable_unit']);

    $reloaded = User::load($user->id());
    $this->assertEquals(297776, $reloaded->get('field_xdusage_person_id')->value);

    $marker = \Drupal::cache()->get('rp_account:user_synced:' . $user->id());
    $this->assertNotFalse($marker);
  }

  /**
   * A bare (non-@access-ci.org) account name is used as the ACCESS username
   * as-is. The gate is the person lookup: a name that is not a real ACCESS user
   * (e.g. "localadmin") resolves to no person, so no grants are fetched and no
   * rows are written. (The negative-cache marker behavior is covered separately
   * by testRefreshNegativeCachesConfirmedAbsentPerson.)
   */
  public function testRefreshSkipsBareNameWithNoAccessPerson(): void {
    $user = User::create(['name' => 'localadmin', 'mail' => 'a@example.com']);
    $user->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    // Grants are only fetched after a person resolves, so never for this name.
    $alloc->getProjectsForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $xd = $this->prophesize(XdusageClient::class);
    // The bare name IS passed to the person lookup — it just returns nothing.
    $xd->lookupPerson('localadmin')->willReturn(['status' => 'absent', 'person' => NULL]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    $rows = \Drupal::database()->select('access_user_rp_account', 'a')
      ->condition('uid', (int) $user->id())
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $rows, 'A non-ACCESS name must write no rows.');
  }

  /**
   * When the person lookup AUTHORITATIVELY reports no such ACCESS person
   * (a successful call that returns an empty result, not a transport error),
   * the user is marked synced so we don't re-query the ACCESS API for them
   * every refresh window. Negative-cache to protect the upstream endpoint.
   */
  public function testRefreshNegativeCachesConfirmedAbsentPerson(): void {
    $user = User::create(['name' => 'notanaccessuser', 'mail' => 'a@example.com']);
    $user->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $xd = $this->prophesize(XdusageClient::class);
    // AUTHORITATIVE absent: the lookup succeeded and returned no person.
    $xd->lookupPerson('notanaccessuser')->willReturn(['status' => 'absent', 'person' => NULL]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    // Marker set → a subsequent refresh window will NOT re-query the API.
    $this->assertNotFalse(
      \Drupal::cache()->get(RpAccountService::SYNC_MARKER_PREFIX . $user->id()),
      'A confirmed-absent person must be negative-cached (marker set).'
    );
  }

  /**
   * When the person lookup FAILS (endpoint down / timeout / 5xx — a transport
   * error, NOT an authoritative empty result), the user must NOT be marked
   * synced, so the refresh retries next window. An outage must never brand a
   * (possibly real) ACCESS user as non-ACCESS.
   */
  public function testRefreshDoesNotNegativeCacheOnLookupFailure(): void {
    $user = User::create(['name' => 'apasquale', 'mail' => 'a@example.com']);
    $user->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $xd = $this->prophesize(XdusageClient::class);
    // TRANSPORT FAILURE: indistinguishable-from-outside, but reported as 'error'.
    $xd->lookupPerson('apasquale')->willReturn(['status' => 'error', 'person' => NULL]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    // NO marker → next window retries. Outage must not suppress a real user.
    $this->assertFalse(
      \Drupal::cache()->get(RpAccountService::SYNC_MARKER_PREFIX . $user->id()),
      'A lookup failure must NOT be negative-cached.'
    );
  }

  /**
   * A bare account name that IS a valid ACCESS username (e.g. "apasquale",
   * with no @access-ci.org suffix) must sync just like a suffixed name.
   * Regression test for the suffix guard that starved ~1,652 bare-named users.
   */
  public function testRefreshSyncsBareAccessUsername(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();

    $user = User::create(['name' => 'apasquale', 'mail' => 'a@example.com']);
    $user->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('apasquale')->willReturn([
      ['grant_number' => 'PHY250173', 'title' => 'Halo finding', 'allocation_type' => 'Accelerate', 'grant_type' => 'Diss.'],
    ]);
    $alloc->getResourcesForUser('apasquale')->willReturn([
      ['cider_resource_id' => 'delta-cpu.ncsa.access-ci.org', 'billable_unit_type' => 'Core-hours'],
    ]);

    $xd = $this->prophesize(XdusageClient::class);
    $xd->lookupPerson('apasquale')->willReturn(['status' => 'found', 'person' => ['person_id' => 110387]]);
    $xd->getProjectsMap()->willReturn([
      'PHY250173' => [
        'delta-cpu.ncsa.access-ci.org' => [
          'project_id' => 66897,
          'resource_id' => 3031,
          'project_balance' => '100.0',
          'project_end' => '2026-07-09',
          'project_state' => 'active',
          'is_expired' => FALSE,
          'billable_unit' => 'Core-hours',
        ],
      ],
    ]);
    $xd->getAccountForUser(66897, 3031, 110387)->willReturn([
      'portal_username' => 'apasquale',
      'account_state' => 'active',
    ]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    $row = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a')
      ->condition('uid', (int) $user->id())
      ->execute()
      ->fetchAssoc();
    $this->assertNotEmpty($row, 'A bare ACCESS username must sync rows.');
    $this->assertSame('apasquale', $row['rp_username']);
    $this->assertSame('PHY250173', $row['grant_number']);

    $reloaded = User::load($user->id());
    $this->assertEquals(110387, $reloaded->get('field_xdusage_person_id')->value);
  }

  public function testGetAccountsForUserAndRpReturnsRowsFreshState(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    $user = User::create(['name' => 'aaadhavan@access-ci.org', 'mail' => 'a@example.com']);
    $user->save();

    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => (int) $user->id(),
      'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173',
      'project_id' => 66897,
      'resource_id' => 3031,
      'grant_title' => 'Halo finding',
      'rp_username' => 'aaadhavan',
      'account_state' => 'active',
      'project_balance' => '245119.85',
      'project_end' => '2026-07-09',
      'project_state' => 'active',
      'is_expired' => 0,
      'billable_unit' => 'Core-hours',
      'synced_at' => time(),
    ])->execute();
    \Drupal::cache()->set('rp_account:user_synced:' . $user->id(), time(), time() + 86400);

    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $xd = $this->prophesize(XdusageClient::class);
    $xd->getProjectsMap()->shouldNotBeCalled();

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());

    $result = $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id());
    $this->assertSame('rows_fresh', $result['state']);
    $this->assertCount(1, $result['rows']);
    $this->assertSame('aaadhavan', $result['rows'][0]['rp_username']);
  }

  public function testRefreshPrunesGrantsNotInIdentityResponse(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();

    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();

    // Pre-existing row for an old grant the user no longer has.
    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => (int) $user->id(),
      'rp_nid' => (int) $rp->id(),
      'grant_number' => 'OLD123',
      'project_id' => 1,
      'resource_id' => 1,
      'rp_username' => 'old',
      'account_state' => 'active',
      'is_expired' => 0,
      'synced_at' => time() - 90000,
    ])->execute();

    // Identity API now reports only PHY250173 (no OLD123).
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willReturn([
      ['grant_number' => 'PHY250173', 'title' => 'Halo', 'allocation_type' => 'Accelerate', 'grant_type' => 'Diss.'],
    ]);
    $alloc->getResourcesForUser(\Prophecy\Argument::any())->willReturn([]);

    $xd = $this->prophesize(XdusageClient::class);
    $xd->lookupPerson(\Prophecy\Argument::any())->shouldNotBeCalled(); // person_id already on user
    $xd->getProjectsMap()->willReturn([
      'PHY250173' => [
        'delta-cpu.ncsa.access-ci.org' => [
          'project_id' => 66897,
          'resource_id' => 3031,
          'project_balance' => '100',
          'project_end' => '2027-01-01',
          'project_state' => 'active',
          'is_expired' => FALSE,
          'billable_unit' => 'Core-hours',
        ],
      ],
    ]);
    $xd->getAccountForUser(66897, 3031, 297776)->willReturn([
      'portal_username' => 'aaadhavan',
      'account_state' => 'active',
    ]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    // OLD123 row should be pruned, PHY250173 row should be present.
    $rows = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['grant_number'])
      ->condition('uid', (int) $user->id())
      ->execute()
      ->fetchCol();
    $this->assertEquals(['PHY250173'], $rows);
  }

  public function testRefreshDoesNotPruneWhenGrantInIdentityButMissingFromProjectsMap(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();

    // Pre-existing row for grant PHY250173.
    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => (int) $user->id(),
      'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173',
      'project_id' => 66897,
      'resource_id' => 3031,
      'rp_username' => 'aaadhavan',
      'account_state' => 'active',
      'is_expired' => 0,
      'synced_at' => time() - 90000,
    ])->execute();

    // Identity API still reports PHY250173, but the projects map is empty
    // (transient upstream gap).
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willReturn([
      ['grant_number' => 'PHY250173', 'title' => 'Halo', 'allocation_type' => 'Accelerate', 'grant_type' => 'Diss.'],
    ]);
    $alloc->getResourcesForUser(\Prophecy\Argument::any())->willReturn([]);
    $xd = $this->prophesize(XdusageClient::class);
    $xd->lookupPerson(\Prophecy\Argument::any())->shouldNotBeCalled();
    $xd->getProjectsMap()->willReturn([]); // empty map
    $xd->getAccountForUser(\Prophecy\Argument::cetera())->shouldNotBeCalled();

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    // Row should still be there (NOT pruned despite missing-from-map).
    $rows = \Drupal::database()->select('access_user_rp_account', 'a')
      ->fields('a', ['grant_number'])
      ->condition('uid', (int) $user->id())
      ->execute()
      ->fetchCol();
    $this->assertEquals(['PHY250173'], $rows);
  }

  public function testRowsStaleStateWhenRefreshFailsButRowsExist(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();

    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => (int) $user->id(),
      'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173',
      'project_id' => 66897,
      'resource_id' => 3031,
      'rp_username' => 'aaadhavan',
      'account_state' => 'active',
      'project_balance' => '100',
      'is_expired' => 0,
      'synced_at' => time() - 90000,
    ])->execute();
    // No fresh sync marker → triggers refresh.

    // AllocationsClient returns NULL (transient failure).
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willReturn(NULL);
    $xd = $this->prophesize(XdusageClient::class);
    $xd->getProjectsMap()->shouldNotBeCalled();
    $xd->getAccountForUser(\Prophecy\Argument::cetera())->shouldNotBeCalled();

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $result = $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id());

    // Pre-existing row preserved; state is 'rows_stale' because refresh aborted before marker.
    $this->assertSame('rows_stale', $result['state']);
    $this->assertCount(1, $result['rows']);
    $this->assertSame('PHY250173', $result['rows'][0]['grant_number']);
  }

  public function testNoRowsUnknownStateWhenNoMarkerAndNoExistingRows(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();
    // No DB rows. No fresh marker.

    // The read path NEVER blocks on a refresh, so even if AllocationsClient
    // would throw at shutdown phase, the read returns immediately with
    // 'no_rows_unknown'. The shutdown phase isn't observable in this test.
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willThrow(new \RuntimeException('boom'));
    $xd = $this->prophesize(XdusageClient::class);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $result = $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id());

    $this->assertSame('no_rows_unknown', $result['state']);
    $this->assertSame([], $result['rows']);
  }

  public function testRefreshDeletesAllRowsWhenIdentityReturnsEmptySuccess(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();

    // Pre-existing rows.
    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => (int) $user->id(),
      'rp_nid' => (int) $rp->id(),
      'grant_number' => 'OLD123',
      'project_id' => 1,
      'resource_id' => 1,
      'rp_username' => 'old',
      'account_state' => 'active',
      'is_expired' => 0,
      'synced_at' => time() - 90000,
    ])->execute();

    // Identity API succeeds with truly zero projects.
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willReturn([]);
    $alloc->getResourcesForUser(\Prophecy\Argument::any())->willReturn([]);
    $xd = $this->prophesize(XdusageClient::class);
    $xd->getProjectsMap()->willReturn([]);
    $xd->getAccountForUser(\Prophecy\Argument::cetera())->shouldNotBeCalled();

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    $count = (int) \Drupal::database()->select('access_user_rp_account', 'a')
      ->condition('uid', (int) $user->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, $count, 'All rows deleted when identity returns empty success.');

    // Marker IS set (this was a successful sync).
    $marker = \Drupal::cache()->get('rp_account:user_synced:' . $user->id());
    $this->assertNotFalse($marker);
  }

  public function testRefreshAbortsWithoutWritesWhenIdentityReturnsNull(): void {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();

    // Pre-existing rows that should NOT be touched.
    \Drupal::database()->insert('access_user_rp_account')->fields([
      'uid' => (int) $user->id(),
      'rp_nid' => (int) $rp->id(),
      'grant_number' => 'OLD123',
      'project_id' => 1,
      'resource_id' => 1,
      'rp_username' => 'old',
      'account_state' => 'active',
      'is_expired' => 0,
      'synced_at' => time() - 90000,
    ])->execute();

    // AllocationsClient signals transient failure.
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('aaadhavan')->willReturn(NULL);
    $xd = $this->prophesize(XdusageClient::class);
    $xd->getProjectsMap()->shouldNotBeCalled();
    $xd->getAccountForUser(\Prophecy\Argument::cetera())->shouldNotBeCalled();

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $svc->refreshUserRpAccounts((int) $user->id());

    // Pre-existing row preserved.
    $count = (int) \Drupal::database()->select('access_user_rp_account', 'a')
      ->condition('uid', (int) $user->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count);

    // No sync marker.
    $this->assertFalse(\Drupal::cache()->get('rp_account:user_synced:' . $user->id()));
  }

  public function testResolveGlobalResourceIdToNidReturnsNidForPublishedResource(): void {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta',
      'field_access_global_resource_id' => 'delta.ncsa.access-ci.org',
    ]);
    $node->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $svc = $this->makeService($alloc->reveal(), $xd->reveal());

    $this->assertSame(
      (int) $node->id(),
      $svc->resolveGlobalResourceIdToNid('delta.ncsa.access-ci.org')
    );
  }

  public function testResolveGlobalResourceIdToNidReturnsNullForUnknownId(): void {
    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $svc = $this->makeService($alloc->reveal(), $xd->reveal());

    $this->assertNull(
      $svc->resolveGlobalResourceIdToNid('nonexistent.example.access-ci.org')
    );
  }

  public function testResolveGlobalResourceIdToNidReturnsNullForUnpublishedResource(): void {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta',
      'field_access_global_resource_id' => 'delta.ncsa.access-ci.org',
      'status' => 0,
    ]);
    $node->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $svc = $this->makeService($alloc->reveal(), $xd->reveal());

    $this->assertNull(
      $svc->resolveGlobalResourceIdToNid('delta.ncsa.access-ci.org')
    );
  }

  public function testGuardedRefreshSkipsWhenLockHeld(): void {
    $lock = \Drupal::service('lock');
    $this->assertTrue($lock->acquire('rp_account_refresh:42'));

    $alloc = $this->prophesize(AllocationsClient::class);
    // If the guarded refresh ran, it would call getProjectsForUser. It must NOT.
    $alloc->getProjectsForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $xd = $this->prophesize(XdusageClient::class);
    $service = $this->makeService($alloc->reveal(), $xd->reveal());

    $service->runGuardedRefresh(42);

    $lock->release('rp_account_refresh:42');
  }

  public function testGuardedRefreshRunsWhenLockFree(): void {
    $user = User::create(['name' => 'guarded@access-ci.org', 'mail' => 'g@example.com', 'status' => 1]);
    $user->save();
    $alloc = $this->prophesize(AllocationsClient::class);
    $alloc->getProjectsForUser('guarded')->willReturn([])->shouldBeCalled();
    $alloc->getResourcesForUser('guarded')->willReturn([]);
    $xd = $this->prophesize(XdusageClient::class);
    $xd->lookupPerson('guarded')->willReturn(['status' => 'found', 'person' => ['person_id' => 111]]);
    $xd->getProjectsMap()->willReturn([]);
    $service = $this->makeService($alloc->reveal(), $xd->reveal());

    $service->runGuardedRefresh((int) $user->id());

    // The lock must be released after a successful refresh — re-acquiring it succeeds.
    $lock = \Drupal::service('lock');
    $this->assertTrue($lock->acquire('rp_account_refresh:' . (int) $user->id()));
    $lock->release('rp_account_refresh:' . (int) $user->id());
  }

  public function testGuardedRefreshReleasesLockOnThrow(): void {
    $user = User::create(['name' => 'thrower@access-ci.org', 'mail' => 't@example.com', 'status' => 1]);
    $user->save();
    $alloc = $this->prophesize(AllocationsClient::class);
    // Make the refresh throw partway through.
    $alloc->getProjectsForUser('thrower')->willThrow(new \RuntimeException('boom'));
    $xd = $this->prophesize(XdusageClient::class);
    $xd->lookupPerson('thrower')->willReturn(['status' => 'found', 'person' => ['person_id' => 222]]);
    $service = $this->makeService($alloc->reveal(), $xd->reveal());

    try {
      $service->runGuardedRefresh((int) $user->id());
      $this->fail('Expected the refresh to throw');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('boom', $e->getMessage());
    }

    // Despite the throw, the finally must have released the lock.
    $lock = \Drupal::service('lock');
    $this->assertTrue($lock->acquire('rp_account_refresh:' . (int) $user->id()));
    $lock->release('rp_account_refresh:' . (int) $user->id());
  }

  public function testResolveRpNidsToResourceInfo(): void {
    $n1 = Node::create(['type' => 'access_active_resources_from_cid', 'title' => 'NCSA Delta',
      'field_access_global_resource_id' => 'delta.ncsa.access-ci.org', 'status' => 1]);
    $n1->save();
    $n2 = Node::create(['type' => 'access_active_resources_from_cid', 'title' => 'PSC Bridges-2',
      'field_access_global_resource_id' => 'bridges2.psc.access-ci.org', 'status' => 1]);
    $n2->save();

    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $service = $this->makeService($alloc->reveal(), $xd->reveal());

    $info = $service->resolveRpNidsToResourceInfo([(int) $n1->id(), (int) $n2->id(), 999999]);
    $this->assertSame('delta.ncsa.access-ci.org', $info[(int) $n1->id()]['resource_id']);
    $this->assertSame('NCSA Delta', $info[(int) $n1->id()]['rp_display_name']);
    $this->assertSame('bridges2.psc.access-ci.org', $info[(int) $n2->id()]['resource_id']);
    $this->assertArrayNotHasKey(999999, $info); // unknown nid absent
  }

  public function testResolveRpNidsToResourceInfoIncludesUnpublished(): void {
    $n = Node::create(['type' => 'access_active_resources_from_cid', 'title' => 'Retired RP',
      'field_access_global_resource_id' => 'retired.ncsa.access-ci.org', 'status' => 0]);
    $n->save();
    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $service = $this->makeService($alloc->reveal(), $xd->reveal());
    $info = $service->resolveRpNidsToResourceInfo([(int) $n->id()]);
    $this->assertSame('retired.ncsa.access-ci.org', $info[(int) $n->id()]['resource_id']);
  }

  public function testResolveRpNidsToResourceInfoEmptyInput(): void {
    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $service = $this->makeService($alloc->reveal(), $xd->reveal());
    $this->assertSame([], $service->resolveRpNidsToResourceInfo([]));
  }

  public function testGetLiveBalanceForRowDelegatesToXdusageClientWithPersonId(): void {
    $row = ['project_id' => 66897, 'resource_id' => 3031];

    $alloc = $this->prophesize(AllocationsClient::class);
    $xd = $this->prophesize(XdusageClient::class);
    $xd->getLiveBalance(66897, 3031, 297776)->shouldBeCalledOnce()->willReturn([
      'project_balance' => '99.0', 'account_charges' => '1.0', 'billable_unit' => 'Core-hours',
    ]);

    $svc = $this->makeService($alloc->reveal(), $xd->reveal());
    $result = $svc->getLiveBalanceForRow($row, 297776);
    $this->assertSame('99.0', $result['project_balance']);
  }
}
