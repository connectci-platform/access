<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\Entity\EventSeriesType;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\recurring_events_registration\Entity\RegistrantType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests registrant delete access, independent of the current request path.
 *
 * @covers \Drupal\access_misc\AccessRegistrantAccessControlHandler
 * @group access_misc
 */
class AccessRegistrantAccessControlHandlerTest extends KernelTestBase {

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
    // Container dependencies, not assertions of this test:
    // access_misc.services.yml wires JsonApiEmailToUuidSubscriber against
    // access.access_id_resolver, and access's own eligibility_check_subscriber
    // wires against access_affinitygroup.allocations_client (which needs key).
    // Same set EventKernelTestBase carries, for the same reason.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * The registrant's owner.
   */
  protected User $owner;

  /**
   * The event series organizer.
   */
  protected User $organizer;

  /**
   * A user unrelated to either the registration or the event.
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
    // field_inheritance 3.x installs a `field_inheritance` base field on every
    // entity type named in field_inheritance.config, via its ConfigSubscriber.
    // The module's install default names node/taxonomy_term/block_content/file,
    // whose entity schemas this kernel env does not install — and the site only
    // inherits into eventinstance anyway. Set the site's value directly rather
    // than importing the module default.
    $this->config('field_inheritance.config')
      ->set('included_entities', ['eventinstance'])
      ->save();
    $this->installConfig(['recurring_events']);
    // recurring_events 3.0 added a RecurringDateConstraint that refuses to save
    // a series whose recurrence yields no dates, gated per bundle on
    // validate_recurring_date. The module's install default turns it ON, but
    // the site's eventseries_type does NOT (recurring_events_update_103001
    // preserved existing behaviour), so leaving the contrib default in place
    // here would test a configuration we do not run — and its violation
    // pre-empts access_events' own EventSeriesRescheduleBlockConstraint
    // message. Match the site.
    $seriesType = EventSeriesType::load('default');
    $seriesType->setValidateRecurringDate(FALSE);
    $seriesType->save();

    // Only the "default" registrant bundle is needed. Installing the full
    // recurring_events_registration config also installs
    // recurring_events_registration.registrant.config with
    // email_notifications: true, which fires the registration-notification
    // mail pipeline on every registrant save — unwanted (and unconfigured,
    // in this minimal test) machinery unrelated to what's under test here.
    // recurring_events_registration_install() auto-creates a "default"
    // registrant_type for each existing eventseries_type when the module is
    // installed, so this may already exist by the time setUp() runs.
    if (!RegistrantType::load('default')) {
      RegistrantType::create([
        'id' => 'default',
        'label' => 'Default',
      ])->save();
    }

    $this->organizer = User::create([
      'name' => 'organizer',
      'mail' => 'organizer@example.com',
      'status' => 1,
    ]);
    $this->organizer->save();

    $this->owner = User::create([
      'name' => 'owner',
      'mail' => 'owner@example.com',
      'status' => 1,
    ]);
    $this->owner->save();
    // Production grants this to the authenticated role by default (see
    // user.role.authenticated.yml); grant it directly here since kernel
    // tests don't load site role config.
    $ownRole = Role::create(['id' => 'delete_own', 'label' => 'Delete own']);
    $ownRole->grantPermission('delete own registrant entities');
    $ownRole->save();
    $this->owner->addRole('delete_own')->save();

    $this->stranger = User::create([
      'name' => 'stranger',
      'mail' => 'stranger@example.com',
      'status' => 1,
    ]);
    $this->stranger->save();
  }

  /**
   * Creates an eventseries + eventinstance owned by $this->organizer.
   */
  protected function createInstance(): EventInstance {
    $series = EventSeries::create([
      'title' => 'Test Event',
      'recur_type' => 'custom',
      'type' => 'default',
      'uid' => $this->organizer->id(),
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
  protected function registerUser(User $user, EventInstance $instance): Registrant {
    $registrant = Registrant::create([
      'user_id' => $user->id(),
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'email' => $user->getEmail(),
      'type' => 'default',
    ]);
    $registrant->save();
    return $registrant;
  }

  /**
   * Sets the current request path, simulating the given route shape.
   */
  protected function setCurrentPath(string $path): void {
    $request = Request::create($path);
    \Drupal::service('path.current')->setPath($path, $request);
  }

  /**
   * Data provider: exercise both a canonical /events/ path and an API path.
   *
   * The handler must not depend on request-path shape at all, so both cases
   * are expected to behave identically.
   */
  public static function pathProvider(): array {
    return [
      'events path' => ['/events/1/registrations/1/delete'],
      'api path' => ['/api/1.0/registrations/some-uuid'],
    ];
  }

  /**
   * The registrant's own owner can delete their registration.
   *
   * @dataProvider pathProvider
   */
  public function testOwnerCanDelete(string $path): void {
    $this->setCurrentPath($path);
    $instance = $this->createInstance();
    $registrant = $this->registerUser($this->owner, $instance);

    $access = $registrant->access('delete', $this->owner, TRUE);

    $this->assertTrue($access->isAllowed(), 'The registrant owner can delete their own registration.');
  }

  /**
   * The event organizer can delete a registration for their event.
   *
   * @dataProvider pathProvider
   */
  public function testOrganizerCanDelete(string $path): void {
    $this->setCurrentPath($path);
    $instance = $this->createInstance();
    $registrant = $this->registerUser($this->owner, $instance);

    $access = $registrant->access('delete', $this->organizer, TRUE);

    $this->assertTrue($access->isAllowed(), 'The event organizer can delete a registration for their event.');
  }

  /**
   * An unrelated user without permissions cannot delete.
   *
   * @dataProvider pathProvider
   */
  public function testStrangerCannotDelete(string $path): void {
    $this->setCurrentPath($path);
    $instance = $this->createInstance();
    $registrant = $this->registerUser($this->owner, $instance);

    $access = $registrant->access('delete', $this->stranger, TRUE);

    $this->assertFalse($access->isAllowed(), 'A user with no relation to the registration or event cannot delete it.');
  }

  /**
   * Denied delete does not fall through into the resend permission check.
   *
   * @dataProvider pathProvider
   */
  public function testDeleteDoesNotFallThroughToResend(string $path): void {
    $this->setCurrentPath($path);
    $instance = $this->createInstance();
    $registrant = $this->registerUser($this->owner, $instance);

    $role = Role::create(['id' => 'resend_only', 'label' => 'Resend only']);
    $role->grantPermission('resend registrant emails');
    $role->save();
    $this->stranger->addRole('resend_only')->save();

    $access = $registrant->access('delete', $this->stranger, TRUE);

    $this->assertFalse($access->isAllowed(), 'Holding only "resend registrant emails" must not grant delete access.');
  }

}
