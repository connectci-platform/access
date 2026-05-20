<?php

namespace Drupal\Tests\access\Unit;

use Drupal\access\EligibilityState;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\access\EligibilityState
 * @group access
 */
class EligibilityStateTest extends UnitTestCase {

  public function testDefaultIsUnknown(): void {
    $state = new EligibilityState();
    $this->assertFalse($state->isKnown());
    $this->assertFalse($state->isEligible());
    $this->assertNull($state->getReason());
  }

  public function testEligibleState(): void {
    $state = new EligibilityState();
    $state->setEligible();
    $this->assertTrue($state->isKnown());
    $this->assertTrue($state->isEligible());
    $this->assertNull($state->getReason());
  }

  public function testIneligibleStateWithReason(): void {
    $state = new EligibilityState();
    $state->setIneligible('Country of Residence is not set.');
    $this->assertTrue($state->isKnown());
    $this->assertFalse($state->isEligible());
    $this->assertSame('Country of Residence is not set.', $state->getReason());
  }

  public function testEligibleAfterIneligibleClearsReason(): void {
    $state = new EligibilityState();
    $state->setIneligible('Country of Residence is not set.');
    $state->setEligible();
    $this->assertTrue($state->isEligible());
    $this->assertNull($state->getReason());
  }

  public function testIneligibleAfterEligibleSetsReason(): void {
    $state = new EligibilityState();
    $state->setEligible();
    $state->setIneligible('Country of Residence is not set.');
    $this->assertFalse($state->isEligible());
    $this->assertSame('Country of Residence is not set.', $state->getReason());
  }

}
