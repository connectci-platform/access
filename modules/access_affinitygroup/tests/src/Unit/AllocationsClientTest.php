<?php

namespace Drupal\Tests\access_affinitygroup\Unit;

use Drupal\access_affinitygroup\Service\AllocationsClient;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
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

}
