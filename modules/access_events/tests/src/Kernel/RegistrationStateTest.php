<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\RegistrationState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\recurring_events_registration\Entity\RegistrantType;
use Drupal\user\Entity\User;

/**
 * Tests the read-only per-instance registration-state service.
 *
 * @covers \Drupal\access_events\RegistrationState
 * @group access_events
 */
class RegistrationStateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'link',
    'datetime',
    'datetime_range',
    'field_inheritance',
    'recurring_events',
    'recurring_events_registration',
  ];

  /**
   * The acting user.
   */
  protected User $owner;

  /**
   * A user unrelated to any registration.
   */
  protected User $stranger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('eventseries');
    $this->installEntitySchema('eventinstance');
    $this->installEntitySchema('registrant');
    $this->installConfig(['field_inheritance', 'recurring_events']);

    // Only the "default" registrant bundle is needed. Installing the full
    // recurring_events_registration config also installs
    // recurring_events_registration.registrant.config with
    // email_notifications: true, which fires the registration-notification
    // mail pipeline on every registrant save — unwanted machinery unrelated to
    // what's under test here. recurring_events_registration_install()
    // auto-creates a "default" registrant_type for each existing
    // eventseries_type when the module is installed, so it may already exist.
    if (!RegistrantType::load('default')) {
      RegistrantType::create([
        'id' => 'default',
        'label' => 'Default',
      ])->save();
    }

    $this->owner = User::create([
      'name' => 'owner',
      'mail' => 'owner@example.com',
      'status' => 1,
    ]);
    $this->owner->save();

    $this->stranger = User::create([
      'name' => 'stranger',
      'mail' => 'stranger@example.com',
      'status' => 1,
    ]);
    $this->stranger->save();
  }

  /**
   * Creates a registrable eventseries + eventinstance and returns the instance.
   *
   * The series' event_registration base field is populated so the contrib
   * RegistrationCreationService reports the instance as registrable:
   *  - registration = 1 (enabled)
   *  - registration_type = 'instance' (all enabled ACCESS series use this)
   *  - registration_dates = 'open' (window is now → instance start), so a
   *    future instance is open and a past instance is closed.
   *
   * Source eventseries display fields (title/body/location/…) are seeded so the
   * later A2 field-inheritance reads resolve non-empty.
   */
  protected function createRegistrableInstance(int $capacity = 60, bool $waitlist = FALSE, bool $pastDate = FALSE): EventInstance {
    $date = $pastDate
      ? ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00']
      : ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'];

    $series = EventSeries::create([
      'title' => 'Registrable Event',
      'recur_type' => 'custom',
      'type' => 'default',
      'event_registration' => [
        'registration' => 1,
        'registration_type' => 'instance',
        'registration_dates' => 'open',
        'capacity' => $capacity,
        'waitlist' => $waitlist ? 1 : 0,
      ],
    ]);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => $date,
    ]);
    $instance->save();

    return $instance;
  }

  /**
   * Creates an eventseries + eventinstance with registration disabled.
   */
  protected function createNonRegistrableInstance(): EventInstance {
    $series = EventSeries::create([
      'title' => 'Non-Registrable Event',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();

    return $instance;
  }

  /**
   * Registers a user for an instance and returns the saved registrant.
   */
  protected function registerUser(User $user, EventInstance $instance, bool $waitlist = FALSE): Registrant {
    $registrant = Registrant::create([
      'user_id' => $user->id(),
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'email' => $user->getEmail(),
      'waitlist' => $waitlist ? 1 : 0,
      'type' => 'default',
    ]);
    $registrant->save();
    return $registrant;
  }

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
