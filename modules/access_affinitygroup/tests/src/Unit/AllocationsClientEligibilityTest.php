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
class AllocationsClientEligibilityTest extends UnitTestCase {

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

  public function testEligibleUserReturnsEligibleTrueWithNullReason(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/identity/profiles/v1/people/apasquale',
      Argument::that(function ($opts) {
        $h = $opts['headers'] ?? [];
        return ($h['XA-API-KEY'] ?? NULL) === 'test-ramps'
          && ($h['XA-REQUESTER'] ?? NULL) === 'MATCH';
      })
    )->willReturn(new Response(200, [], json_encode([
      'username' => 'apasquale',
      'isEligible' => 'yes',
      'eligibleReason' => '',
    ])));

    $client = $this->makeClient($http->reveal());
    $result = $client->getEligibilityForUser('apasquale');

    $this->assertSame(['eligible' => TRUE, 'reason' => NULL], $result);
  }

  public function testIneligibleUserReturnsEligibleFalseWithReason(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(200, [], json_encode([
        'username' => 'aa9gj',
        'isEligible' => 'no',
        'eligibleReason' => 'Country of Residence is not set.',
      ])));

    $client = $this->makeClient($http->reveal());
    $result = $client->getEligibilityForUser('aa9gj');

    $this->assertSame(
      ['eligible' => FALSE, 'reason' => 'Country of Residence is not set.'],
      $result
    );
  }

  public function testApi404ReturnsNull(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(404, [], '{}'));

    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getEligibilityForUser('nobody'));
  }

  public function testHttpErrorReturnsNull(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willThrow(new \GuzzleHttp\Exception\ConnectException(
        'connection failed',
        new \GuzzleHttp\Psr7\Request('GET', 'http://x')
      ));

    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getEligibilityForUser('apasquale'));
  }

  public function testMissingApiKeyReturnsNull(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(Argument::cetera())->shouldNotBeCalled();

    $client = $this->makeClient($http->reveal(), '{}');
    $this->assertNull($client->getEligibilityForUser('apasquale'));
  }

  public function testMalformedJsonReturnsNull(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::any(), Argument::any())
      ->willReturn(new Response(200, [], 'not json'));

    $client = $this->makeClient($http->reveal());
    $this->assertNull($client->getEligibilityForUser('apasquale'));
  }

  public function testUsernameWithSpecialCharsIsUrlEncoded(): void {
    $http = $this->prophesize(ClientInterface::class);
    $http->request(
      'GET',
      'https://allocations-api.access-ci.org/identity/profiles/v1/people/user%40example',
      Argument::any()
    )->willReturn(new Response(200, [], json_encode([
      'isEligible' => 'yes',
      'eligibleReason' => '',
    ])));

    $client = $this->makeClient($http->reveal());
    $client->getEligibilityForUser('user@example');
  }

}
