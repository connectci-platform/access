<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Controller\RegistrationApiController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field_inheritance\Entity\FieldInheritance;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the acting-user-scoped registrations list API.
 *
 * @covers \Drupal\access_events\Controller\RegistrationApiController
 * @group access_events
 */
class RegistrationApiTest extends KernelTestBase {

  use UserCreationTrait;

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
   * The acting user whose registrations are listed.
   */
  protected User $user;

  /**
   * A second user whose registrations must not leak.
   */
  protected User $otherUser;

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

    // recurring_events ships the "title" inheritance config, so the computed
    // "title" field on the eventinstance (what the controller reads for
    // event_title) already resolves from the eventseries "title" base field.
    //
    // The controller also reads inherited event_type / location /
    // virtual_meeting_link. Each inherits from a config field on the series, so
    // those source fields must exist for the computed field to be registered.
    $this->createSeriesField('field_event_type', 'list_string', [
      'allowed_values' => [
        'conf' => 'Conference',
        'train' => 'Training',
      ],
    ]);
    $this->createSeriesField('field_location', 'text_long');
    $this->createSeriesField('field_event_virtual_meeting_link', 'link');

    FieldInheritance::create([
      'id' => 'eventinstance_default_event_type',
      'label' => 'Event Type',
      'type' => 'inherit',
      'sourceEntityType' => 'eventseries',
      'sourceEntityBundle' => 'default',
      'sourceField' => 'field_event_type',
      'destinationEntityType' => 'eventinstance',
      'destinationEntityBundle' => 'default',
      'destinationField' => '',
      'plugin' => 'default_inheritance',
    ])->save();
    FieldInheritance::create([
      'id' => 'eventinstance_default_location',
      'label' => 'Location',
      'type' => 'inherit',
      'sourceEntityType' => 'eventseries',
      'sourceEntityBundle' => 'default',
      'sourceField' => 'field_location',
      'destinationEntityType' => 'eventinstance',
      'destinationEntityBundle' => 'default',
      'destinationField' => '',
      'plugin' => 'default_inheritance',
    ])->save();
    FieldInheritance::create([
      'id' => 'eventinstance_default_virtual_meeting_link',
      'label' => 'Virtual Meeting Link',
      'type' => 'inherit',
      'sourceEntityType' => 'eventseries',
      'sourceEntityBundle' => 'default',
      'sourceField' => 'field_event_virtual_meeting_link',
      'destinationEntityType' => 'eventinstance',
      'destinationEntityBundle' => 'default',
      'destinationField' => '',
      'plugin' => 'default_inheritance',
    ])->save();

    $this->user = User::create([
      'name' => 'acting',
      'mail' => 'acting@example.com',
      'status' => 1,
    ]);
    $this->user->save();

    $this->otherUser = User::create([
      'name' => 'other',
      'mail' => 'other@example.com',
      'status' => 1,
    ]);
    $this->otherUser->save();

    // Fixture users implicitly have the authenticated role; install its config
    // + grant 'delete own registrant entities' so the owner-cancel path passes
    // the explicit $registrant->access('delete', $owner) assertion exactly as in
    // production. A non-owner (who lacks 'delete registrant entities') is still
    // denied by that same entity-access handler — the security property under
    // test after the manual owner-compare was removed from the controller.
    $this->installConfig(['user']);
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      ['delete own registrant entities'],
    );
  }

  /**
   * Creates a config field on the eventseries default bundle.
   */
  protected function createSeriesField(string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'eventseries',
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'eventseries',
      'bundle' => 'default',
      'label' => $name,
    ])->save();
  }

  /**
   * Creates an eventseries + eventinstance with a date, returns the instance.
   *
   * Modeled on the fixture in
   * recurring_events_registration/tests/src/Kernel/RegistrantTest.php, with a
   * daterange added on the instance (the "date" base field) so the "when"
   * filter has something to sort/filter on.
   */
  protected function createInstance(string $title, string $start, string $end, array $seriesValues = []): EventInstance {
    $series = EventSeries::create([
      'title' => $title,
      'recur_type' => 'custom',
      'type' => 'default',
    ] + $seriesValues);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => $start,
        'end_value' => $end,
      ],
    ]);
    $instance->save();

    // field_inheritance resolves each computed field's source entity from a
    // keyvalue "field_inheritance" state, not from eventseries_id. That state
    // is normally written by the eventinstance form-submit handler, which does
    // not run here. Write it directly so the inherited event_type / location /
    // virtual_meeting_link fields resolve back to this instance's series.
    // (See FieldInheritancePluginBase::getSourceEntity() and
    // field_inheritance_entity_form_submit().)
    $state = \Drupal::keyValue('field_inheritance');
    $state->set('eventinstance:' . $instance->uuid(), [
      'enabled' => TRUE,
      'event_type' => ['entity' => $series->id()],
      'location' => ['entity' => $series->id()],
      'virtual_meeting_link' => ['entity' => $series->id()],
    ]);

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
   * Builds a list request with the acting user attribute set.
   */
  protected function listRequest(string $when): Request {
    $request = Request::create('/api/1.0/registrations?when=' . $when);
    $request->attributes->set('acting_user_uid', (int) $this->user->id());
    return $request;
  }

  /**
   * Runs the controller and decodes the JSON body.
   */
  protected function listBody(string $when): array {
    $response = RegistrationApiController::create($this->container)->list($this->listRequest($when));
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Upcoming (default) returns the acting user's future registrations.
   */
  public function testListReturnsActingUsersUpcomingRegistrations(): void {
    $instance = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00', [
      // Source values on the series inherit onto the computed instance fields
      // that the controller reads. These prove the type-specific mapping:
      // list label resolution, markup stripping, and link ->uri extraction.
      'field_event_type' => 'conf',
      'field_location' => ['value' => '<a href="https://x">NCSA</a>', 'format' => 'plain_text'],
      'field_event_virtual_meeting_link' => ['uri' => 'https://zoom.example/123'],
    ]);
    $registrant = $this->registerUser($this->user, $instance);

    $body = $this->listBody('upcoming');

    $this->assertCount(1, $body['registrations']);
    $r = $body['registrations'][0];
    $this->assertSame($registrant->uuid(), $r['registrant_id']);
    $this->assertSame((int) $instance->id(), $r['eventinstance_id']);
    $this->assertArrayHasKey('event_title', $r);
    $this->assertArrayHasKey('start_date', $r);
    $this->assertFalse($r['waitlist']);

    // Type-specific field mapping: the ship-null-data traps.
    // event_type: allowed_values map 'conf' => 'Conference', so the label
    // must resolve (proving label mapping, not raw-key passthrough).
    $this->assertNotNull($r['event_type']);
    $this->assertSame('Conference', $r['event_type']);
    // location: markup must be stripped to plain text.
    $this->assertSame('NCSA', $r['location']);
    // virtual_meeting_link: must read the link field's ->uri, not ->value
    // (which would be NULL) — this is the fragile bit worth pinning.
    $this->assertSame('https://zoom.example/123', $r['virtual_meeting_link']);
  }

  /**
   * The past filter excludes future registrations.
   */
  public function testPastFilterExcludesFuture(): void {
    $future = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $past = $this->createInstance('Past Event', '2000-01-01T10:00:00', '2000-01-01T12:00:00');
    $this->registerUser($this->user, $future);
    $pastRegistrant = $this->registerUser($this->user, $past);

    $body = $this->listBody('past');

    $this->assertCount(1, $body['registrations']);
    $this->assertSame($pastRegistrant->uuid(), $body['registrations'][0]['registrant_id']);
    $this->assertSame((int) $past->id(), $body['registrations'][0]['eventinstance_id']);
  }

  /**
   * Only the acting user's registrations are returned.
   */
  public function testOnlyActingUsersRegistrationsReturned(): void {
    $instance = $this->createInstance('Shared Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $mine = $this->registerUser($this->user, $instance);
    $this->registerUser($this->otherUser, $instance);

    $body = $this->listBody('all');

    $this->assertCount(1, $body['registrations']);
    $this->assertSame($mine->uuid(), $body['registrations'][0]['registrant_id']);
  }

  /**
   * when=all returns both past and upcoming, sorted by start date ascending.
   */
  public function testAllReturnsPastAndUpcomingSortedByStart(): void {
    $future = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $past = $this->createInstance('Past Event', '2000-01-01T10:00:00', '2000-01-01T12:00:00');
    $this->registerUser($this->user, $future);
    $this->registerUser($this->user, $past);

    $body = $this->listBody('all');

    $this->assertCount(2, $body['registrations']);
    // Sorted by start date ascending: the past event comes first.
    $this->assertSame((int) $past->id(), $body['registrations'][0]['eventinstance_id']);
    $this->assertSame((int) $future->id(), $body['registrations'][1]['eventinstance_id']);
  }

  /**
   * An unrecognized when value is a 400 and lists nothing.
   */
  public function testUnknownWhenRejectedWith400(): void {
    $instance = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $this->registerUser($this->user, $instance);

    $response = RegistrationApiController::create($this->container)->list($this->listRequest('bogus'));

    $this->assertSame(400, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame("Invalid 'when' value. Use upcoming, past, or all.", $body['error']);
    // Nothing is listed on rejection.
    $this->assertArrayNotHasKey('registrations', $body);
  }

  /**
   * Builds a cancel (DELETE) request acting as the given uid.
   */
  protected function cancelRequest(string $uuid, int $actingUid): Request {
    $request = Request::create('/api/1.0/registrations/' . $uuid, 'DELETE');
    $request->attributes->set('acting_user_uid', $actingUid);
    return $request;
  }

  /**
   * Loads registrants by uuid (route params are uuids, not entity ids).
   */
  protected function loadByUuid(string $uuid): array {
    return $this->container->get('entity_type.manager')->getStorage('registrant')
      ->loadByProperties(['uuid' => $uuid]);
  }

  /**
   * Cancelling your own registration deletes the registrant.
   */
  public function testCancelDeletesOwnRegistration(): void {
    $instance = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $registrant = $this->registerUser($this->user, $instance);
    $uuid = $registrant->uuid();

    $response = RegistrationApiController::create($this->container)
      ->cancel($uuid, $this->cancelRequest($uuid, (int) $this->user->id()));

    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('cancelled', $body['status']);
    $this->assertSame($uuid, $body['registrant_id']);
    // The registrant must be gone.
    $this->assertEmpty($this->loadByUuid($uuid));
  }

  /**
   * Cancelling another user's registration is denied AND the row survives.
   *
   * The acting user is a REAL, existing non-owner ($this->otherUser) who holds
   * only 'delete own registrant entities' — so the refusal comes from the
   * registrant access handler's delete branch (a non-owner needs the broader
   * 'delete registrant entities', which they lack), NOT from a null-user
   * short-circuit. This is the security property the removed manual owner-compare
   * used to provide: it must now hold via entity access.
   */
  public function testCancelAnotherUsersRegistrationForbidden(): void {
    $instance = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $registrant = $this->registerUser($this->user, $instance);
    $uuid = $registrant->uuid();

    // try/catch instead of expectException so we can ALSO assert the
    // registrant survives the denied attempt.
    $denied = FALSE;
    try {
      RegistrationApiController::create($this->container)
        ->cancel($uuid, $this->cancelRequest($uuid, (int) $this->otherUser->id()));
    }
    catch (AccessDeniedHttpException $e) {
      $denied = TRUE;
    }
    $this->assertTrue($denied, 'Cancelling another user\'s registration throws AccessDeniedHttpException.');
    $this->assertNotEmpty($this->loadByUuid($uuid), 'The registrant survives the denied cancel attempt.');
  }

  /**
   * Cancelling an unknown uuid is a 404.
   */
  public function testCancelUnknownUuidNotFound(): void {
    $this->expectException(NotFoundHttpException::class);
    RegistrationApiController::create($this->container)
      ->cancel('nonexistent-uuid', $this->cancelRequest('nonexistent-uuid', (int) $this->user->id()));
  }

  /**
   * Creates an anonymous-owned (uid 0) registrant for an instance.
   *
   * Anonymous-owned registrants are a real data shape in recurring_events:
   * Registrant::preCreate() defaults user_id to the current user, which is 0
   * for anonymous registrations.
   */
  protected function registerAnonymous(EventInstance $instance): Registrant {
    $registrant = Registrant::create([
      'user_id' => 0,
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'email' => 'anon@example.com',
      'waitlist' => 0,
      'type' => 'default',
    ]);
    $registrant->save();
    return $registrant;
  }

  /**
   * A cancel request with NO acting_user_uid attribute must be denied.
   *
   * A missing attribute coerces to uid 0 in the controller, which would
   * otherwise match (and delete) anonymous-owned registrants. The route's
   * access check normally guarantees uid >= 1, but the controller must not
   * depend solely on that wiring.
   */
  public function testCancelWithoutActingUserDeniedAndAnonymousRegistrantSurvives(): void {
    $instance = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $registrant = $this->registerAnonymous($instance);
    $uuid = $registrant->uuid();

    // Deliberately do NOT set the acting_user_uid attribute.
    $request = Request::create('/api/1.0/registrations/' . $uuid, 'DELETE');

    // try/catch instead of expectException so we can ALSO assert the
    // anonymous-owned registrant survives the attempt.
    $denied = FALSE;
    try {
      RegistrationApiController::create($this->container)->cancel($uuid, $request);
    }
    catch (AccessDeniedHttpException $e) {
      $denied = TRUE;
    }
    $this->assertTrue($denied, 'Cancelling with no acting user throws AccessDeniedHttpException.');
    $this->assertNotEmpty($this->loadByUuid($uuid), 'The anonymous-owned registrant survives the attempt.');
  }

  /**
   * A list request with NO acting_user_uid attribute must be denied.
   *
   * Otherwise uid 0 would list every anonymous-owned registrant.
   */
  public function testListWithoutActingUserDenied(): void {
    $this->expectException(AccessDeniedHttpException::class);
    // Deliberately do NOT set the acting_user_uid attribute.
    $request = Request::create('/api/1.0/registrations?when=all');
    RegistrationApiController::create($this->container)->list($request);
  }

  /**
   * Waitlisted registrations cancel normally (owner cancel succeeds).
   */
  public function testCancelWaitlistedRegistration(): void {
    $instance = $this->createInstance('Future Event', '2999-01-01T10:00:00', '2999-01-01T12:00:00');
    $registrant = $this->registerUser($this->user, $instance, waitlist: TRUE);
    $uuid = $registrant->uuid();

    $response = RegistrationApiController::create($this->container)
      ->cancel($uuid, $this->cancelRequest($uuid, (int) $this->user->id()));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('cancelled', json_decode($response->getContent(), TRUE)['status']);
    $this->assertEmpty($this->loadByUuid($uuid));
  }

}
