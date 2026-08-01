<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\RegistrationState;

/**
 * Tests the read-only per-instance registration-state service.
 *
 * @covers \Drupal\access_events\RegistrationState
 * @group access_events
 */
class RegistrationStateTest extends EventKernelTestBase {

  /**
   * A registrable instance reports capacity, seats, and the window.
   */
  public function testEnabledInstanceReportsSeatsAndWindow(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: TRUE);
    $uid = (int) $this->owner->id();
    $state = RegistrationState::forInstance($instance, $uid);
    $this->assertTrue($state['enabled']);
    $this->assertSame(60, $state['capacity']);
    $this->assertSame(0, $state['registered_count']);
    $this->assertSame(60, $state['seats_remaining']);
    $this->assertTrue($state['waitlist_enabled']);
    $this->assertIsBool($state['registration_open']);
    $this->assertFalse($state['already_registered']);

    // Window: reg_dates_type='open' + registration_type='instance' means the
    // window closes at the instance start. The instance start is stored as the
    // naive-UTC '2999-01-01T10:00:00' (datetime storage is UTC). The service
    // hands back that moment in the site's default TZ (whatever the runtime is);
    // the $iso helper must convert it back to UTC before appending 'Z', so the
    // emitted string must be the exact UTC instant, not a TZ-offset-mislabeled
    // one. This is the regression guard for the UTC-labeling bug.
    $this->assertStringEndsWith('Z', $state['registration_window']['closes']);
    $this->assertSame('2999-01-01T10:00:00Z', $state['registration_window']['closes']);
  }

  /**
   * A registration-disabled instance reports only ['enabled' => FALSE].
   */
  public function testDisabledInstanceReportsBareFalse(): void {
    $instance = $this->createNonRegistrableInstance();
    $state = RegistrationState::forInstance($instance, (int) $this->owner->id());
    $this->assertSame(['enabled' => FALSE], $state);
  }

  /**
   * already_registered is per-user and the counts reflect the seated party.
   */
  public function testAlreadyRegisteredIsPerUser(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $this->registerUser($this->owner, $instance);
    $asOwner = RegistrationState::forInstance($instance, (int) $this->owner->id());
    $asStranger = RegistrationState::forInstance($instance, (int) $this->stranger->id());
    $this->assertTrue($asOwner['already_registered']);
    $this->assertFalse($asStranger['already_registered']);
    $this->assertSame(1, $asOwner['registered_count']);
    $this->assertSame(59, $asOwner['seats_remaining']);
  }

  /**
   * A waitlisted registration dedups the user but does NOT consume a seat.
   *
   * already_registered (hasUserRegisteredById) counts waitlisted registrants,
   * while registered_count (retrieveRegisteredPartiesCount(TRUE, FALSE)) does
   * not. This asymmetry is intentional — it lets A3 refuse a re-registration
   * from a user who is only on the waitlist — so it is locked in here.
   */
  public function testWaitlistedRegistrationDedupsButHoldsNoSeat(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: TRUE);
    $this->registerUser($this->owner, $instance, waitlist: TRUE);
    $state = RegistrationState::forInstance($instance, (int) $this->owner->id());
    $this->assertTrue($state['already_registered']);
    $this->assertSame(0, $state['registered_count']);
    $this->assertSame(60, $state['seats_remaining']);
  }

  /**
   * With no acting user (uid 0), already_registered is OMITTED entirely.
   *
   * hasUserRegisteredById(0) drops the user filter in the underlying service, so
   * it would read TRUE whenever ANYONE is registered — a false personal claim and
   * an info leak. With uid < 1 the key must be absent, not TRUE or FALSE, per the
   * "omit the per-user field when there's no acting user" convention.
   */
  public function testNoActingUserOmitsAlreadyRegistered(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    // A DIFFERENT user is registered, so hasUserRegisteredById(0) would be TRUE.
    $this->registerUser($this->stranger, $instance);
    $state = RegistrationState::forInstance($instance, 0);
    $this->assertArrayNotHasKey('already_registered', $state);
  }

  /**
   * The anonymous (uid 0) block omits per-user AND time-derived fields.
   *
   * registration_open (registrationIsOpen()) and registration_window.opens
   * (reg_open=now for the 'open' dates-type) are wall-clock-derived — no cache
   * tag can invalidate a clock comparison — so they must not appear in the
   * cacheable anonymous payload. already_registered is per-user. Only the
   * entity-derived fields survive for uid < 1.
   */
  public function testAnonymousBlockOmitsPerUserAndTimeDerivedFields(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $state = RegistrationState::forInstance($instance, 0);
    // Entity-derived — present (cacheable):
    $this->assertArrayHasKey('enabled', $state);
    $this->assertArrayHasKey('capacity', $state);
    $this->assertArrayHasKey('registered_count', $state);
    $this->assertArrayHasKey('seats_remaining', $state);
    $this->assertArrayHasKey('waitlist_enabled', $state);
    // Omitted for anonymous:
    $this->assertArrayNotHasKey('already_registered', $state);
    $this->assertArrayNotHasKey('registration_open', $state);
    $this->assertArrayNotHasKey('registration_window', $state);
  }

  /**
   * The acting-user (uid >= 1) block keeps all fields, including time-derived.
   */
  public function testActingUserBlockKeepsAllFields(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $state = RegistrationState::forInstance($instance, (int) $this->owner->id());
    $this->assertArrayHasKey('registration_open', $state);
    $this->assertArrayHasKey('registration_window', $state);
    $this->assertArrayHasKey('already_registered', $state);
  }

  /**
   * Zero (unlimited) capacity reports null capacity and null seats_remaining.
   */
  public function testUnlimitedCapacityReportsNulls(): void {
    $instance = $this->createRegistrableInstance(capacity: 0, waitlist: FALSE);
    $state = RegistrationState::forInstance($instance, (int) $this->owner->id());
    $this->assertNull($state['capacity']);
    $this->assertNull($state['seats_remaining']);
  }

  /**
   * A fully-booked instance clamps seats_remaining at 0, never negative.
   */
  public function testExhaustedCapacityClampsSeatsToZero(): void {
    $instance = $this->createRegistrableInstance(capacity: 1, waitlist: FALSE);
    $this->registerUser($this->stranger, $instance);
    $state = RegistrationState::forInstance($instance, (int) $this->owner->id());
    $this->assertSame(0, $state['seats_remaining']);
  }

}
