<?php

namespace Drupal\Tests\access_affinitygroup\Unit;

use Drupal\access_affinitygroup\CcLogic;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\access_affinitygroup\CcLogic
 * @group access_affinitygroup
 */
class CcLogicTest extends UnitTestCase {

  /**
   * @covers ::httpSucceeded
   * @dataProvider httpCodes
   */
  public function testHttpSucceeded(int $code, bool $expected): void {
    $this->assertSame($expected, CcLogic::httpSucceeded($code));
  }

  /**
   * Data provider for testHttpSucceeded().
   */
  public static function httpCodes(): array {
    return [
      'ok' => [200, TRUE],
      'created' => [201, TRUE],
      'accepted' => [202, TRUE],
      'no content (schedule POST)' => [204, TRUE],
      'last 2xx' => [299, TRUE],
      'redirect not success' => [302, FALSE],
      'bad request' => [400, FALSE],
      'unauthorized' => [401, FALSE],
      'conflict' => [409, FALSE],
      'server error' => [500, FALSE],
      'zero (curl failure)' => [0, FALSE],
    ];
  }

  /**
   * @covers ::isLiveEnv
   * @dataProvider liveEnvCases
   */
  public function testIsLiveEnv(?string $pantheonEnv, bool $expected): void {
    $this->assertSame($expected, CcLogic::isLiveEnv($pantheonEnv));
  }

  /**
   * Data provider for testIsLiveEnv().
   */
  public static function liveEnvCases(): array {
    return [
      'live' => ['live', TRUE],
      'dev' => ['dev', FALSE],
      'multidev' => ['md-2740', FALSE],
      'test env' => ['test', FALSE],
      'local' => ['local', FALSE],
      'empty string' => ['', FALSE],
      'null (getenv unset)' => [NULL, FALSE],
    ];
  }

  /**
   * @covers ::resolveCcEnvironment
   * @dataProvider envCases
   */
  public function testResolveCcEnvironment(?string $pantheonEnv, string $domainClass, ?string $forced, string $expected): void {
    $this->assertSame($expected, CcLogic::resolveCcEnvironment($pantheonEnv, $domainClass, $forced));
  }

  /**
   * Data provider for testResolveCcEnvironment().
   */
  public static function envCases(): array {
    return [
      'live ood' => ['live', 'open-ondemand', NULL, 'openondemand'],
      'live support' => ['live', 'support', NULL, 'support'],
      'local forces test' => ['local', 'open-ondemand', NULL, 'test'],
      // The bug: a non-live Pantheon env must NOT touch live token slots.
      'dev ood falls back to test' => ['dev', 'open-ondemand', NULL, 'test'],
      'multidev support falls back to test' => ['md-2740', 'support', NULL, 'test'],
      'empty pantheon env falls back to test' => ['', 'open-ondemand', NULL, 'test'],
      // The caller passes NULL when getenv() returns FALSE; exercise that.
      'null pantheon env falls back to test' => [NULL, 'open-ondemand', NULL, 'test'],
      // Forced override still wins (used by admin form / manual token tools).
      'forced support beats fallback' => ['dev', 'open-ondemand', 'support', 'support'],
      'forced ood on live' => ['live', 'support', 'openondemand', 'openondemand'],
    ];
  }

}
