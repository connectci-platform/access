<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Controller\EventDetailApiController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the GET /api/1.0/events/{eventinstance_id} detail route.
 *
 * @covers \Drupal\access_events\Controller\EventDetailApiController
 * @group access_events
 */
class EventDetailApiControllerTest extends EventKernelTestBase {

  /**
   * GET returns the full detail object plus the live registration block.
   */
  public function testGetReturnsDetailAndRegistration(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: TRUE);

    $request = Request::create('/api/1.0/events/' . $instance->id());
    $request->attributes->set('rp_account_effective_uid', (int) $this->owner->id());
    \Drupal::requestStack()->push($request);

    $controller = EventDetailApiController::create(\Drupal::getContainer());
    $response = $controller->get($instance);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame((string) $instance->id(), (string) $data['id']);

    // Inherited detail fields resolve non-null (title/description inherit from
    // the eventseries title/body base fields).
    $this->assertArrayHasKey('title', $data);
    $this->assertArrayHasKey('description', $data);
    $this->assertSame('Registrable Event', $data['title']);
    $this->assertSame('The full event description.', $data['description']);

    // Dates: the daterange value/end_value are stored naive-UTC, so the
    // controller only appends a literal Z. The seeded start is
    // '2999-01-01T10:00:00' → exact regression guard on the Z-normalized string.
    $this->assertStringEndsWith('Z', $data['start_date']);
    $this->assertSame('2999-01-01T10:00:00Z', $data['start_date']);
    $this->assertSame('2999-01-01T12:00:00Z', $data['end_date']);

    // series_id comes from eventinstance.eventseries_id.
    $this->assertSame(
      (string) $instance->get('eventseries_id')->target_id,
      (string) $data['series_id'],
    );

    // The registration block is RegistrationState::forInstance().
    $this->assertTrue($data['registration']['enabled']);
    $this->assertSame(60, $data['registration']['capacity']);
    $this->assertFalse($data['registration']['already_registered']);
  }

  /**
   * A non-registrable instance reports registration: {enabled: false}.
   */
  public function testGetNonRegistrableInstanceHasBareRegistrationFalse(): void {
    $instance = $this->createNonRegistrableInstance();

    $request = Request::create('/api/1.0/events/' . $instance->id());
    $request->attributes->set('rp_account_effective_uid', (int) $this->owner->id());
    \Drupal::requestStack()->push($request);

    $data = json_decode(
      EventDetailApiController::create(\Drupal::getContainer())->get($instance)->getContent(),
      TRUE,
    );

    $this->assertSame(['enabled' => FALSE], $data['registration']);
    // Detail fields still present; inherited title/description resolve.
    $this->assertSame('Non-Registrable Event', $data['title']);
  }

  /**
   * Detail fields sourced from absent (site-level) series fields degrade to
   * null rather than erroring.
   */
  public function testAbsentInheritedFieldsAreNull(): void {
    $instance = $this->createRegistrableInstance();

    $request = Request::create('/api/1.0/events/' . $instance->id());
    $request->attributes->set('rp_account_effective_uid', (int) $this->owner->id());
    \Drupal::requestStack()->push($request);

    $data = json_decode(
      EventDetailApiController::create(\Drupal::getContainer())->get($instance)->getContent(),
      TRUE,
    );

    // location/event_type/skill_level/speakers/tags/registration_url inherit
    // from site-level series fields not present in this minimal kernel env.
    $this->assertNull($data['location']);
    $this->assertNull($data['event_type']);
    $this->assertNull($data['skill_level']);
    $this->assertNull($data['registration_url']);
  }

  /**
   * The link + taxonomy fields are read by type, not the generic string reader.
   *
   * Regression guard for C1: the generic $item->value ?? getString() reader
   * mangles a LINK item that carries title text (Map::getString() implodes uri
   * + title → "https://x.org/reg, Register Here") and collapses a multi-value
   * ENTITY_REFERENCE. registration_url must be the clean URI, and tags must be
   * the joined term NAMES of every referenced term. This test fails against the
   * pre-fix reader.
   */
  public function testLinkAndTaxonomyFieldsReadByType(): void {
    $instance = $this->createInstanceWithLinkAndTags(
      linkUri: 'https://x.org/reg',
      linkTitle: 'Register Here',
      tagNames: ['HPC', 'Machine Learning'],
    );

    $request = Request::create('/api/1.0/events/' . $instance->id());
    $request->attributes->set('rp_account_effective_uid', (int) $this->owner->id());
    \Drupal::requestStack()->push($request);

    $data = json_decode(
      EventDetailApiController::create(\Drupal::getContainer())->get($instance)->getContent(),
      TRUE,
    );

    // Clean URI, NOT the "https://x.org/reg, Register Here" mangle.
    $this->assertSame('https://x.org/reg', $data['registration_url']);

    // Both term names, joined — not a single mangled entity-reference string.
    $this->assertSame('HPC, Machine Learning', $data['tags']);
  }

  /**
   * event_type / skill_level are emitted as the LABEL, not the stored KEY.
   *
   * Regression guard: event_type and skill_level are list_string (option)
   * fields whose stored KEY differs from the human LABEL. event_type maps the
   * internal sort-hack key `zz_other` to `Other`. The pre-fix generic string
   * reader returned the raw ->value, leaking `zz_other` into the API; the other
   * event tools (get_my_registrations, search_events) emit the label, so
   * get_event was inconsistent. The controller must map the key through the
   * field's allowed_values and emit the label. skill_level goes through the same
   * mapping for consistency (its keys equal labels today).
   */
  public function testOptionFieldsEmitLabelNotKey(): void {
    $instance = $this->createInstanceWithOptionFields(
      eventTypeKey: 'zz_other',
      skillLevelKey: 'Beginner',
    );

    $request = Request::create('/api/1.0/events/' . $instance->id());
    $request->attributes->set('rp_account_effective_uid', (int) $this->owner->id());
    \Drupal::requestStack()->push($request);

    $data = json_decode(
      EventDetailApiController::create(\Drupal::getContainer())->get($instance)->getContent(),
      TRUE,
    );

    // The LABEL, NOT the leaked internal 'zz_other' key.
    $this->assertSame('Other', $data['event_type']);
    // skill_level maps through the same allowed_values path.
    $this->assertSame('Beginner', $data['skill_level']);
  }

  /**
   * A confirmed register on a seated event creates a seated registrant.
   */
  public function testRegisterCommitCreatesSeatedRegistrant(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $before = $this->countRegistrants($instance);

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame('registered', $data['status']);
    $this->assertNotEmpty($data['registrant_id']);
    // registrant_id is the registrant uuid, not the integer id.
    $this->assertMatchesRegularExpression(
      '/^[0-9a-f-]{36}$/',
      $data['registrant_id'],
    );
    $this->assertSame((string) $instance->id(), (string) $data['eventinstance_id']);
    $this->assertSame($before + 1, $this->countRegistrants($instance));
  }

  /**
   * A preview (no confirmed) writes nothing and reports the seat outcome.
   */
  public function testPreviewCreatesNoRegistrant(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $before = $this->countRegistrants($instance);

    $data = json_decode(
      $this->doRegister($instance, $this->owner, [])->getContent(),
      TRUE,
    );

    $this->assertTrue($data['preview']);
    $this->assertSame('seat', $data['outcome_if_confirmed']);
    $this->assertSame(60, $data['seats_remaining']);
    $this->assertTrue($data['registration_open']);
    $this->assertFalse($data['already_registered']);
    // No waitlist outcome, so no waitlisted_count key.
    $this->assertArrayNotHasKey('waitlisted_count', $data);
    // No write.
    $this->assertSame($before, $this->countRegistrants($instance));
  }

  /**
   * A non-bool `confirmed` stays in preview and never writes.
   *
   * Locks in the strict `=== TRUE` fail-safe: a truthy-but-not-bool value like
   * the integer 1 (and equally "true", {}, or malformed JSON) must NOT commit a
   * registration — it degrades to a no-write preview. Guards a future refactor
   * from loosening the parse into an accidental write.
   */
  public function testNonBoolConfirmedStaysPreviewNoWrite(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $before = $this->countRegistrants($instance);

    // Integer 1 is truthy but not the boolean TRUE the guard requires.
    $response = $this->doRegister($instance, $this->owner, ['confirmed' => 1]);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertTrue($data['preview']);
    $this->assertArrayNotHasKey('success', $data);
    $this->assertSame($before, $this->countRegistrants($instance));
  }

  /**
   * A second confirmed register by the same user is refused (409), no write.
   */
  public function testAlreadyRegisteredReturns409AndNoSecondRegistrant(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE);
    $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $before = $this->countRegistrants($instance);

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame(
      'already_registered',
      json_decode($response->getContent(), TRUE)['error'],
    );
    $this->assertSame($before, $this->countRegistrants($instance));
  }

  /**
   * A full event with no waitlist refuses with 409 event_full.
   */
  public function testFullNoWaitlistReturns409EventFull(): void {
    $instance = $this->createRegistrableInstance(capacity: 1, waitlist: FALSE);
    // Fill the one seat with a different user.
    $this->registerUser($this->stranger, $instance);

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame(
      'event_full',
      json_decode($response->getContent(), TRUE)['error'],
    );
  }

  /**
   * A full event WITH a waitlist waitlists the acting user.
   */
  public function testFullWithWaitlistWaitlists(): void {
    $instance = $this->createRegistrableInstance(capacity: 1, waitlist: TRUE);
    $this->registerUser($this->stranger, $instance);

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame('waitlisted', $data['status']);
  }

  /**
   * A non-registrable instance refuses with 409 not_registrable.
   */
  public function testNotRegistrableReturns409(): void {
    $instance = $this->createNonRegistrableInstance();

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame(
      'not_registrable',
      json_decode($response->getContent(), TRUE)['error'],
    );
  }

  /**
   * A full-with-waitlist preview reports the waitlist outcome + count.
   */
  public function testFullWithWaitlistPreviewReportsWaitlistedCount(): void {
    $instance = $this->createRegistrableInstance(capacity: 1, waitlist: TRUE);
    // One seated party ahead fills the only seat.
    $this->registerUser($this->stranger, $instance);

    $data = json_decode(
      $this->doRegister($instance, $this->owner, [])->getContent(),
      TRUE,
    );

    $this->assertTrue($data['preview']);
    $this->assertSame('waitlist', $data['outcome_if_confirmed']);
    $this->assertArrayHasKey('waitlisted_count', $data);
    $this->assertIsInt($data['waitlisted_count']);
  }

  /**
   * A past-dated (window-closed) instance refuses with 409 registration_closed.
   *
   * With registration_dates = 'open' the window is now → instance start, so a
   * past instance start makes registrationIsOpen() FALSE.
   */
  public function testRegistrationClosedReturns409(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: FALSE, pastDate: TRUE);

    // Sanity: the service agrees the window is closed for this instance.
    $svc = \Drupal::service('recurring_events_registration.creation_service');
    $svc->setEventInstance($instance);
    $this->assertFalse($svc->registrationIsOpen());

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame(
      'registration_closed',
      json_decode($response->getContent(), TRUE)['error'],
    );
  }

  /**
   * A role-restricted series refuses a user lacking the role with 409.
   *
   * The not_permitted refusal is a 409 (registration-STATE refusal), not a 403:
   * the acting user is already identified and authorized by the RpAccountAccess
   * gate, so re-authenticating cannot help. 403 is reserved for the gate's own
   * identity/auth failure, which runs before this controller.
   */
  public function testNotPermittedRoleReturns409(): void {
    // The role need not exist as a Role entity for the guard to deny: the
    // acting user simply does not carry 'restricted_registrants'.
    $instance = $this->createRegistrableInstance(
      capacity: 60,
      waitlist: FALSE,
      permittedRoles: ['restricted_registrants'],
    );

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame(
      'not_permitted',
      json_decode($response->getContent(), TRUE)['error'],
    );
  }

  /**
   * A user WITH the permitted role registers successfully.
   */
  public function testPermittedRoleRegisters(): void {
    $role = $this->createRole([], 'restricted_registrants');
    $this->owner->addRole($role)->save();

    $instance = $this->createRegistrableInstance(
      capacity: 60,
      waitlist: FALSE,
      permittedRoles: [$role],
    );

    $response = $this->doRegister($instance, $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue(json_decode($response->getContent(), TRUE)['success']);
  }

}
