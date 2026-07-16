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

}
