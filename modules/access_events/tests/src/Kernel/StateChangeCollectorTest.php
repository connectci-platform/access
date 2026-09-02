<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\StateChangeCollector;

/**
 * Tests the per-request orchestration-outcome collector.
 *
 * @covers \Drupal\access_events\StateChangeCollector
 * @group access_events
 */
class StateChangeCollectorTest extends EventKernelTestBase {

  /**
   * drain() returns the accumulated counts for a key and clears them.
   */
  public function testDrainReturnsAndClears(): void {
    $collector = new StateChangeCollector();

    $collector->record('eventinstance', 7, 'notified', 3);
    $collector->record('eventinstance', 7, 'notified', 2);

    $this->assertSame(['notified' => 5], $collector->drain('eventinstance', 7));
    $this->assertSame([], $collector->drain('eventinstance', 7));
  }

  /**
   * resetOwn() clears only its exact key, never a series/instance mirror.
   */
  public function testResetOwnLeavesOtherKeysAlone(): void {
    $collector = new StateChangeCollector();

    $collector->record('eventseries', 1, 'notified', 4);
    $collector->record('eventinstance', 7, 'notified', 4);

    $collector->resetOwn('eventinstance', 7);

    $this->assertSame([], $collector->drain('eventinstance', 7));
    $this->assertSame(['notified' => 4], $collector->drain('eventseries', 1));
  }

  /**
   * The sweep marker is a single nullable series id, keyed by an exact match.
   */
  public function testSweepMarker(): void {
    $collector = new StateChangeCollector();

    $this->assertFalse($collector->isSweeping(1));

    $collector->beginSweep(1);
    $this->assertTrue($collector->isSweeping(1));
    $this->assertFalse($collector->isSweeping(2));

    $collector->endSweep();
    $this->assertFalse($collector->isSweeping(1));
  }

  /**
   * flag() sets a boolean marker that drain() surfaces as TRUE.
   */
  public function testFlagSetsBooleanMarker(): void {
    $collector = new StateChangeCollector();

    $collector->flag('eventinstance', 7, 'individually_cancelled');

    $this->assertSame(['individually_cancelled' => TRUE], $collector->drain('eventinstance', 7));
  }

}
