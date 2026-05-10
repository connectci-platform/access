<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Controller\RpAccountController;
use Drupal\access_affinitygroup\Service\RpAccountService;
use Drupal\access_affinitygroup\Service\XdusageClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group access_affinitygroup
 */
class RpAccountControllerTest extends KernelTestBase {

  use ProphecyTrait;

  protected static $modules = ['access_affinitygroup', 'node', 'field', 'user', 'system', 'text', 'filter', 'key'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('access_affinitygroup', ['access_user_rp_account']);
    $this->installConfig(['filter']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'RP'])->save();
    \Drupal\field\Entity\FieldStorageConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node', 'type' => 'string',
    ])->save();
    \Drupal\field\Entity\FieldConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node', 'bundle' => 'access_active_resources_from_cid',
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

  protected function makeController(RpAccountService $svc, ?XdusageClient $xd = NULL): RpAccountController {
    $xd = $xd ?: $this->prophesize(XdusageClient::class)->reveal();
    $controller = new RpAccountController($svc, $xd);
    $controller->setStringTranslation(\Drupal::service('string_translation'));
    return $controller;
  }

  protected function makeRpNode(): Node {
    $rp = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $rp->save();
    return $rp;
  }

  protected function makeUser(): User {
    $user = User::create(['name' => 'aaadhavan@access-ci.org', 'mail' => 'a@example.com']);
    $user->save();
    return $user;
  }

  public function testReturnsHasAccountFalseWhenServiceReportsNoRowsFresh(): void {
    $rp = $this->makeRpNode();
    $user = $this->makeUser();

    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [], 'state' => 'no_rows_fresh']);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id());
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(FALSE, $body['has_account']);
    $this->assertSame(FALSE, $body['stale']);
    $this->assertArrayNotHasKey('rp_username', $body);
    $this->assertArrayNotHasKey('grants', $body);
  }

  public function testReturnsHasAccountNullAndStaleTrueOnError(): void {
    $rp = $this->makeRpNode();
    $user = $this->makeUser();

    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [], 'state' => 'error']);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id());
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertNull($body['has_account']);
    $this->assertTrue($body['stale']);
    $this->assertArrayNotHasKey('rp_username', $body);
    $this->assertArrayNotHasKey('grants', $body);
  }

  public function testReturnsHasAccountNullAndStaleTrueOnNoRowsUnknown(): void {
    $rp = $this->makeRpNode();
    $user = $this->makeUser();

    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [], 'state' => 'no_rows_unknown']);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id());
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertNull($body['has_account']);
    $this->assertTrue($body['stale']);
    $this->assertArrayNotHasKey('rp_username', $body);
    $this->assertArrayNotHasKey('grants', $body);
  }

  public function testReturnsAccountPanelWithRows(): void {
    $rp = $this->makeRpNode();
    $user = $this->makeUser();

    $row = [
      'uid' => (int) $user->id(), 'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173', 'project_id' => 66897, 'resource_id' => 3031,
      'grant_title' => 'Halo finding', 'rp_username' => 'aaadhavan',
      'account_state' => 'active', 'project_balance' => '245119.85',
      'project_end' => '2026-07-09', 'project_state' => 'active',
      'is_expired' => 0, 'billable_unit' => 'Core-hours',
      'synced_at' => time(),
    ];
    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [$row], 'state' => 'rows_fresh']);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id());
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal())->get((int) $rp->id(), $request);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'));
    $body = json_decode($response->getContent(), TRUE);

    $this->assertTrue($body['has_account']);
    $this->assertFalse($body['stale']);
    $this->assertSame('aaadhavan', $body['rp_username']);
    $this->assertCount(1, $body['grants']);
    $this->assertSame('PHY250173', $body['grants'][0]['grant_number']);
    $this->assertSame('Halo finding', $body['grants'][0]['title']);
    $this->assertSame('https://allocations.access-ci.org/', $body['manage_url']);
  }

  public function testRpUsernameNullWhenMultipleDistinctRpUsernames(): void {
    $rp = $this->makeRpNode();
    $user = $this->makeUser();

    $rows = [
      ['grant_number' => 'A', 'project_id' => 1, 'resource_id' => 1, 'grant_title' => 'a',
       'rp_username' => 'one', 'account_state' => 'active', 'project_balance' => '1',
       'project_end' => '2027-01-01', 'project_state' => 'active', 'is_expired' => 0,
       'billable_unit' => 'CH', 'synced_at' => time()],
      ['grant_number' => 'B', 'project_id' => 2, 'resource_id' => 1, 'grant_title' => 'b',
       'rp_username' => 'two', 'account_state' => 'active', 'project_balance' => '2',
       'project_end' => '2027-01-01', 'project_state' => 'active', 'is_expired' => 0,
       'billable_unit' => 'CH', 'synced_at' => time()],
    ];
    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => $rows, 'state' => 'rows_fresh']);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id());
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertTrue($body['has_account']);
    $this->assertNull($body['rp_username']);
  }

  public function testLiveBalanceOverlayAppliedWhenLiveQueryParamPresent(): void {
    $rp = $this->makeRpNode();
    $user = User::create([
      'name' => 'aaadhavan@access-ci.org',
      'mail' => 'a@example.com',
      'field_xdusage_person_id' => 297776,
    ]);
    $user->save();

    $row = [
      'uid' => (int) $user->id(), 'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173', 'project_id' => 66897, 'resource_id' => 3031,
      'grant_title' => 'Halo', 'rp_username' => 'aaadhavan',
      'account_state' => 'active', 'project_balance' => '100.0',
      'project_end' => '2026-07-09', 'project_state' => 'active',
      'is_expired' => 0, 'billable_unit' => 'Core-hours',
      'synced_at' => time(),
    ];
    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [$row], 'state' => 'rows_fresh']);

    $xd = $this->prophesize(XdusageClient::class);
    $xd->getLiveBalanceBatch([$row], 297776, 4, 4.0, 8.0)
      ->shouldBeCalledOnce()
      ->willReturn(['66897:3031' => [
        'project_balance' => '999.99', 'account_charges' => '5.0', 'billable_unit' => 'Core-hours',
      ]]);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id() . '?live=1');
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal(), $xd->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame('999.99', $body['grants'][0]['project_balance']);
    $this->assertSame('5.0', $body['grants'][0]['account_charges']);
  }

  public function testLiveBalanceErrorMarkedWhenBatchOmitsTuple(): void {
    $rp = $this->makeRpNode();
    $user = $this->makeUser();

    $row = [
      'uid' => (int) $user->id(), 'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173', 'project_id' => 66897, 'resource_id' => 3031,
      'grant_title' => 'Halo', 'rp_username' => 'aaadhavan',
      'account_state' => 'active', 'project_balance' => '100.0',
      'project_end' => '2026-07-09', 'project_state' => 'active',
      'is_expired' => 0, 'billable_unit' => 'Core-hours',
      'synced_at' => time(),
    ];
    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [$row], 'state' => 'rows_fresh']);

    $xd = $this->prophesize(XdusageClient::class);
    $xd->getLiveBalanceBatch(\Prophecy\Argument::cetera())->willReturn([]);

    $request = Request::create('/api/1.0/rp-account/' . $rp->id() . '?live=1');
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal(), $xd->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertTrue($body['grants'][0]['live_balance_error']);
    $this->assertSame('100.0', $body['grants'][0]['project_balance']);
  }

  public function testLiveOverlayWhenUserHasNoPersonId(): void {
    $rp = $this->makeRpNode();
    // makeUser() doesn't set field_xdusage_person_id, simulating a user
    // who hasn't been refreshed yet.
    $user = $this->makeUser();

    $row = [
      'uid' => (int) $user->id(), 'rp_nid' => (int) $rp->id(),
      'grant_number' => 'PHY250173', 'project_id' => 66897, 'resource_id' => 3031,
      'grant_title' => 'Halo', 'rp_username' => 'aaadhavan',
      'account_state' => 'active', 'project_balance' => '100.0',
      'project_end' => '2026-07-09', 'project_state' => 'active',
      'is_expired' => 0, 'billable_unit' => 'Core-hours',
      'synced_at' => time(),
    ];
    $svc = $this->prophesize(RpAccountService::class);
    $svc->getAccountsForUserAndRp((int) $user->id(), (int) $rp->id())
      ->willReturn(['rows' => [$row], 'state' => 'rows_fresh']);

    $xd = $this->prophesize(XdusageClient::class);
    // Without person_id, the batch must NOT be called.
    $xd->getLiveBalanceBatch(\Prophecy\Argument::cetera())->shouldNotBeCalled();

    $request = Request::create('/api/1.0/rp-account/' . $rp->id() . '?live=1');
    $request->attributes->set('rp_account_effective_uid', (int) $user->id());

    $response = $this->makeController($svc->reveal(), $xd->reveal())->get((int) $rp->id(), $request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertTrue($body['grants'][0]['live_balance_error']);
    $this->assertSame('no_person_id', $body['grants'][0]['live_unavailable_reason']);
    // Pre-existing balance preserved (no overwrite from foreign API row).
    $this->assertSame('100.0', $body['grants'][0]['project_balance']);
  }

  public function testNonExistentRpReturns404(): void {
    $svc = $this->prophesize(RpAccountService::class);
    $request = Request::create('/api/1.0/rp-account/999999');
    $request->attributes->set('rp_account_effective_uid', 1);

    $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    $this->makeController($svc->reveal())->get(999999, $request);
  }

  public function testWrongBundleReturns404(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $node = Node::create(['type' => 'page', 'title' => 'Not an RP']);
    $node->save();

    $svc = $this->prophesize(RpAccountService::class);
    $request = Request::create('/api/1.0/rp-account/' . $node->id());
    $request->attributes->set('rp_account_effective_uid', 1);

    $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    $this->makeController($svc->reveal())->get((int) $node->id(), $request);
  }

}
