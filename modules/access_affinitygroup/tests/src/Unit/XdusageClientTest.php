<?php

namespace Drupal\Tests\access_affinitygroup\Unit;

use Drupal\access_affinitygroup\Service\XdusageClient;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * @coversDefaultClass \Drupal\access_affinitygroup\Service\XdusageClient
 * @group access_affinitygroup
 */
class XdusageClientTest extends UnitTestCase {

  use ProphecyTrait;

  protected function makeClient($http, $cache_hit = NULL) {
    $key = $this->prophesize(KeyInterface::class);
    $key->getKeyValue()->willReturn('test-api-key');
    $keyRepo = $this->prophesize(KeyRepositoryInterface::class);
    $keyRepo->getKey('xdusage_api')->willReturn($key->reveal());

    $cache = $this->prophesize(CacheBackendInterface::class);
    if ($cache_hit !== NULL) {
      $obj = (object) ['data' => $cache_hit, 'expire' => time() + 3600];
      $cache->get('xdusage:projects_map')->willReturn($obj);
    }
    else {
      $cache->get('xdusage:projects_map')->willReturn(FALSE);
      $cache->set('xdusage:projects_map', Argument::any(), Argument::any())
        ->willReturn(NULL);
    }

    $logFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $log = $this->prophesize(LoggerChannelInterface::class);
    $logFactory->get('access_affinitygroup')->willReturn($log->reveal());

    return new XdusageClient(
      $http,
      $keyRepo->reveal(),
      $cache->reveal(),
      $logFactory->reveal()
    );
  }

  public function testGetPersonByPortalUsernameSendsCorrectHeaders(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/acdb/xdusage/v2/people/by_portal_username/aaadhavan',
      Argument::that(function ($opts) {
        $h = $opts['headers'] ?? [];
        return ($h['XA-RESOURCE'] ?? NULL) === 'support.access-ci.org'
          && ($h['XA-AGENT'] ?? NULL) === 'xdusage'
          && ($h['XA-API-KEY'] ?? NULL) === 'test-api-key';
      })
    )->willReturn(new Response(200, [], json_encode([
      'message' => NULL,
      'result' => [['person_id' => 297776, 'portal_username' => 'aaadhavan']],
    ])));

    $client = $this->makeClient($http->reveal());
    $result = $client->getPersonByPortalUsername('aaadhavan');
    $this->assertSame(297776, $result['person_id']);
  }

  public function testGetPersonByPortalUsernameReturnsNullOn404(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(404, [], json_encode(['result' => NULL])));
    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getPersonByPortalUsername('nope'));
  }

  public function testGetProjectsMapBuildsIndexFromFlatList(): void {
    $apiResponse = json_encode([
      'message' => NULL,
      'result' => [
        [
          'project_id' => 66897, 'grant_number' => 'PHY250173',
          'resource_id' => 3031, 'resource_name' => 'delta-cpu.ncsa.xsede.org',
          'info_resource_id' => 'delta-cpu.ncsa.access-ci.org',
          'project_balance' => '245119.85', 'project_end' => '2026-07-09',
          'billable_unit_type' => 'Core-hours',
          'project_state' => 'active', 'is_expired' => FALSE,
        ],
        [
          'project_id' => 99999, 'grant_number' => 'CIS220051',
          'resource_id' => 3097, 'resource_name' => 'anvil.purdue.xsede.org',
          'info_resource_id' => 'anvil.purdue.access-ci.org',
          'project_balance' => '12345.0', 'project_end' => '2027-01-01',
          'billable_unit_type' => 'Service Units',
          'project_state' => 'active', 'is_expired' => FALSE,
        ],
      ],
    ]);
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/acdb/xdusage/v2/projects',
      Argument::any()
    )->willReturn(new Response(200, [], $apiResponse));

    $client = $this->makeClient($http->reveal());
    $map = $client->getProjectsMap();
    $this->assertArrayHasKey('PHY250173', $map);
    $this->assertArrayHasKey('delta-cpu.ncsa.access-ci.org', $map['PHY250173']);
    $this->assertSame(66897, $map['PHY250173']['delta-cpu.ncsa.access-ci.org']['project_id']);
    $this->assertSame('245119.85', $map['PHY250173']['delta-cpu.ncsa.access-ci.org']['project_balance']);
  }

  public function testGetProjectsMapReturnsCachedValue(): void {
    $cached = ['CACHED_GRANT' => ['some.resource' => ['project_id' => 1]]];
    $http = $this->prophesize(ClientInterface::class);
    // No HTTP call expected when cache hits.
    $http->request(Argument::cetera())->shouldNotBeCalled();

    $client = $this->makeClient($http->reveal(), $cached);
    $this->assertSame($cached, $client->getProjectsMap());
  }

  public function testGetAccountForUserAppendsPersonIdQueryParam(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/acdb/xdusage/v2/accounts/66897/3031?person_id=297776',
      Argument::any()
    )->willReturn(new Response(200, [], json_encode([
      'message' => NULL,
      'result' => [[
        'project_id' => 66897, 'resource_id' => 3031, 'person_id' => 297776,
        'portal_username' => 'aaadhavan', 'account_state' => 'active',
        'account_charges' => '0.0',
      ]],
    ])));

    $client = $this->makeClient($http->reveal());
    $result = $client->getAccountForUser(66897, 3031, 297776);
    $this->assertSame('aaadhavan', $result['portal_username']);
    $this->assertSame('active', $result['account_state']);
  }

  public function testGetLiveBalanceWithPersonIdReturnsMatchingRowsBalance(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/acdb/xdusage/v2/accounts/66897/3031?person_id=297776',
      Argument::any()
    )->willReturn(new Response(200, [], json_encode([
      'message' => NULL,
      'result' => [
        ['person_id' => 1, 'project_balance' => '1.0', 'account_charges' => '0.0', 'billable_unit_type' => 'Core-hours'],
        ['person_id' => 297776, 'project_balance' => '245119.85', 'account_charges' => '500.0', 'billable_unit_type' => 'Core-hours'],
      ],
    ])));

    $client = $this->makeClient($http->reveal());
    $result = $client->getLiveBalance(66897, 3031, 297776);
    $this->assertSame('245119.85', $result['project_balance']);
    $this->assertSame('500.0', $result['account_charges']);
    $this->assertSame('Core-hours', $result['billable_unit']);
  }

  public function testGetLiveBalanceWithPersonIdReturnsNullWhenNoMatch(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(200, [], json_encode([
        'message' => NULL,
        'result' => [
          ['person_id' => 1, 'project_balance' => '1.0', 'account_charges' => '0.0', 'billable_unit_type' => 'Core-hours'],
        ],
      ])));

    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getLiveBalance(66897, 3031, 297776));
  }

  public function testGetLiveBalanceBatchPlaceholder(): void {
    // The pool fan-out is exercised end-to-end in the kernel test in Task 6
    // (which mocks XdusageClient at the service boundary). Pure unit-testing
    // the Guzzle Pool plumbing is fiddly and provides little marginal value.
    $this->markTestIncomplete('Pool covered via kernel test fixture; see RpAccountServiceTest.');
  }
}
