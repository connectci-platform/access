<?php

namespace Drupal\Tests\access_affinitygroup\Unit;

use Drupal\access_affinitygroup\Service\AllocationsClient;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use org\bovigo\vfs\vfsStream;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * @coversDefaultClass \Drupal\access_affinitygroup\Service\AllocationsClient
 * @group access_affinitygroup
 */
class AllocationsClientTest extends UnitTestCase {

  use ProphecyTrait;

  protected function makeClient($http, $secrets_content = '{"ramps_api_key":"test-ramps"}') {
    vfsStream::setup('private', NULL, ['.keys' => ['secrets.json' => $secrets_content]]);

    $fs = $this->prophesize(FileSystemInterface::class);
    $fs->realpath('private://')->willReturn(vfsStream::url('private'));

    $logFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $log = $this->prophesize(LoggerChannelInterface::class);
    $logFactory->get('access_affinitygroup')->willReturn($log->reveal());

    return new AllocationsClient(
      $http,
      $fs->reveal(),
      $logFactory->reveal()
    );
  }

  public function testGetProjectsForUserSendsCorrectHeaders(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/identity/profiles/v1/people/aaadhavan?projects=1',
      Argument::that(function ($opts) {
        $h = $opts['headers'] ?? [];
        return ($h['XA-API-KEY'] ?? NULL) === 'test-ramps'
          && ($h['XA-REQUESTER'] ?? NULL) === 'MATCH';
      })
    )->willReturn(new Response(200, [], json_encode([
      'projects' => [[
        'grant_number' => 'PHY250173',
        'allocation_type' => 'Accelerate',
        'grant_type' => 'Dissertation or Thesis',
        'title' => 'Test',
      ]],
    ])));

    $client = $this->makeClient($http->reveal());
    $projects = $client->getProjectsForUser('aaadhavan');
    $this->assertCount(1, $projects);
    $this->assertSame('PHY250173', $projects[0]['grant_number']);
  }

  public function testGetProjectsForUserReturnsNullOnHttpError(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(500, [], 'oops'));
    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getProjectsForUser('foo'));
  }

  public function testGetProjectsForUserReturnsNullWhenNoProjectsKey(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(200, [], json_encode(['username' => 'aaadhavan'])));
    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getProjectsForUser('aaadhavan'));
  }

  public function testGetProjectsForUserReturnsNullWhenSecretsMissingKey(): void {
    $http = $this->prophesize(ClientInterface::class);
    // Should not be called when key is missing.
    $http->request(Argument::cetera())->shouldNotBeCalled();
    $client = $this->makeClient($http->reveal(), '{"other_key":"x"}');
    $this->assertNull($client->getProjectsForUser('foo'));
  }

  public function testGetProjectsForUserReturnsNullWhenSecretsMalformed(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(Argument::cetera())->shouldNotBeCalled();
    $client = $this->makeClient($http->reveal(), 'not-json{');
    $this->assertNull($client->getProjectsForUser('foo'));
  }

  public function testGetProjectsForUserReturnsEmptyArrayWhenApiSucceedsWithZeroProjects(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(200, [], json_encode(['projects' => []])));
    $client = $this->makeClient($http->reveal());
    $this->assertSame([], $client->getProjectsForUser('aaadhavan'));
  }

  public function testGetResourcesForUserReturnsResourceArray(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/identity/profiles/v1/people/aaadhavan?resources=1',
      Argument::any()
    )->willReturn(new Response(200, [], json_encode([
      'username' => 'aaadhavan',
      'resources' => [
        [
          'resource_id' => 3031,
          'cider_resource_id' => 'delta-cpu.ncsa.access-ci.org',
          'billable_unit_type' => 'Core-hours',
          'resource_name' => 'delta-cpu.ncsa.xsede.org',
        ],
      ],
    ])));

    $client = $this->makeClient($http->reveal());
    $resources = $client->getResourcesForUser('aaadhavan');
    $this->assertIsArray($resources);
    $this->assertCount(1, $resources);
    $this->assertSame('Core-hours', $resources[0]['billable_unit_type']);
    $this->assertSame('delta-cpu.ncsa.access-ci.org', $resources[0]['cider_resource_id']);
  }

  public function testGetResourcesForUserReturnsNullOnHttpError(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(500, [], 'oops'));
    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getResourcesForUser('foo'));
  }

  /**
   * Sample site_logins payload (top-level list of organizations).
   */
  protected function siteLoginsFixture(): array {
    return [
      [
        'organizationId' => 1,
        'organizationName' => 'ACCESS',
        'logins' => [[
          'resourceName' => 'portal.teragrid',
          'displayName' => 'portal.teragrid',
          'rdrResourceId' => 130827,
          'ciderResourceId' => 'portal.teragrid',
          'username' => 'hackwort',
        ],
        ],
      ],
      [
        'organizationId' => 2440,
        'organizationName' => 'OSG Consortium',
        'logins' => [[
          'resourceName' => 'grid1.osg.xsede',
          'displayName' => 'OSG Open Science Pool',
          'rdrResourceId' => 142288,
          'ciderResourceId' => 'grid1.osg.access-ci.org',
          'username' => 'ux454936',
        ],
        ],
      ],
      [
        'organizationId' => 848,
        'organizationName' => 'Pittsburgh Supercomputing Center',
        'logins' => [
          [
            'resourceName' => 'bridges2-gpu.psc.xsede.org',
            'ciderResourceId' => 'bridges2-gpu.psc.access-ci.org',
            'username' => 'hackwort',
          ],
          [
            'resourceName' => 'bridges2-rm.psc.xsede.org',
            'ciderResourceId' => 'bridges2-rm.psc.access-ci.org',
            'username' => 'hackwort',
          ],
        ],
      ],
    ];
  }

  /**
   * Builds a client whose HTTP call to site_logins returns the given response.
   */
  protected function siteLoginsClient(Response $response, string $username = 'aaadhavan'): AllocationsClient {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())->willReturn($response);
    return $this->makeClient($http->reveal());
  }

  public function testGetSiteLoginsForUserFlattensNestedOrgs(): void {
    $client = $this->siteLoginsClient(new Response(200, [], json_encode($this->siteLoginsFixture())));
    $logins = $client->getSiteLoginsForUser('aaadhavan');
    $this->assertIsArray($logins);
    $this->assertSame('ux454936', $logins['grid1.osg.access-ci.org']);
    $this->assertSame('hackwort', $logins['portal.teragrid']);
    $this->assertSame('hackwort', $logins['bridges2-gpu.psc.access-ci.org']);
    $this->assertSame('hackwort', $logins['bridges2-rm.psc.access-ci.org']);
  }

  public function testGetSiteLoginsForUserAddsResourceNameFallback(): void {
    $client = $this->siteLoginsClient(new Response(200, [], json_encode($this->siteLoginsFixture())));
    $logins = $client->getSiteLoginsForUser('aaadhavan');
    $this->assertSame('ux454936', $logins['grid1.osg.xsede']);
    $this->assertSame('hackwort', $logins['bridges2-rm.psc.xsede.org']);
  }

  public function testGetSiteLoginsForUserCiderIdWinsOverResourceNameCollision(): void {
    // First login's resourceName equals the second login's ciderResourceId.
    $payload = [[
      'organizationId' => 1,
      'logins' => [
        [
          'resourceName' => 'shared.id',
          'ciderResourceId' => 'first.access-ci.org',
          'username' => 'wrong',
        ],
        [
          'resourceName' => 'second.xsede.org',
          'ciderResourceId' => 'shared.id',
          'username' => 'right',
        ],
      ],
    ],
    ];
    $client = $this->siteLoginsClient(new Response(200, [], json_encode($payload)));
    $logins = $client->getSiteLoginsForUser('aaadhavan');
    $this->assertSame('right', $logins['shared.id']);
    $this->assertSame('wrong', $logins['first.access-ci.org']);
    $this->assertSame('right', $logins['second.xsede.org']);
  }

  public function testGetSiteLoginsForUserReturnsNullOn404(): void {
    $client = $this->siteLoginsClient(new Response(404, [], '{}'));
    $this->assertNull($client->getSiteLoginsForUser('nobody'));
  }

  public function testGetSiteLoginsForUserReturnsNullOnMalformedJson(): void {
    $client = $this->siteLoginsClient(new Response(200, [], 'not-json{'));
    $this->assertNull($client->getSiteLoginsForUser('aaadhavan'));
  }

  public function testGetSiteLoginsForUserReturnsNullWhenSecretsMissingKey(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(Argument::cetera())->shouldNotBeCalled();
    $client = $this->makeClient($http->reveal(), '{"other_key":"x"}');
    $this->assertNull($client->getSiteLoginsForUser('aaadhavan'));
  }

  public function testGetSiteLoginsForUserReturnsNullOnGuzzleException(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willThrow(new ConnectException('boom', new Request('GET', 'https://example.test')));
    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getSiteLoginsForUser('aaadhavan'));
  }

  public function testGetSiteLoginsForUserReturnsEmptyArrayForEmptyList(): void {
    $client = $this->siteLoginsClient(new Response(200, [], '[]'));
    $this->assertSame([], $client->getSiteLoginsForUser('aaadhavan'));
  }

  public function testGetSiteLoginsForUserSkipsInvalidEntries(): void {
    $payload = [
      ['organizationId' => 1],
      ['organizationId' => 2, 'logins' => 'nope'],
      'not-an-org',
      [
        'organizationId' => 3,
        'logins' => [
          ['ciderResourceId' => 'a.access-ci.org', 'username' => ''],
          ['ciderResourceId' => 'b.access-ci.org', 'username' => '   '],
          ['ciderResourceId' => 'c.access-ci.org'],
          ['ciderResourceId' => 'd.access-ci.org', 'username' => 42],
          ['resourceName' => 'e.xsede.org', 'username' => 'nocider'],
          ['ciderResourceId' => '', 'username' => 'emptycider'],
          'not-a-login',
          ['ciderResourceId' => 'ok.access-ci.org', 'username' => '  padded  '],
        ],
      ],
    ];
    $client = $this->siteLoginsClient(new Response(200, [], json_encode($payload)));
    $this->assertSame(['ok.access-ci.org' => 'padded'], $client->getSiteLoginsForUser('aaadhavan'));
  }

  public function testGetSiteLoginsForUserRequestUrlAndHeaders(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/identity/profiles/v1/people/' . rawurlencode('user+name@x') . '/site_logins',
      Argument::that(function ($opts) {
        return ($opts['headers']['XA-API-KEY'] ?? NULL) === 'test-ramps';
      })
    )->shouldBeCalledOnce()->willReturn(new Response(200, [], '[]'));
    $client = $this->makeClient($http->reveal());
    $this->assertSame([], $client->getSiteLoginsForUser('user+name@x'));
  }

}
