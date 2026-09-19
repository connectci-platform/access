<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

/**
 * Tests that the write API can set an event's timezone, and defaults it.
 *
 * api/2.3 is a write-capable CRUD surface, and it is the path the MCP agent
 * creates events through. Its writable fields are a hardcoded allowlist, so a
 * field absent from that list simply cannot be set — an agent-created event
 * would carry no timezone at all.
 *
 * The form's author-zone default is a form-layer hook, so it never fires here.
 * Without an equivalent, every agent-created event would fall to the display
 * fallback, which is the population most likely to need a real zone.
 *
 * @group access_events
 */
class EventTimezoneApiWriteTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
  }

  /**
   * The timezone is a writable field on the create path.
   */
  public function testTimezoneIsWritableThroughTheApi(): void {
    $this->assertContains(
      'field_event_timezone',
      $this->writableContentFields(),
      'an API caller can set the event timezone'
    );
  }

  /**
   * The in-person flag is writable too.
   */
  public function testInPersonFlagIsWritableThroughTheApi(): void {
    $this->assertContains(
      'field_event_in_person',
      $this->writableContentFields(),
      'an API caller can mark an event as having a physical venue'
    );
  }

  /**
   * A create that omits the timezone gets the acting user's account zone.
   *
   * Drives the controller's own field-application path rather than calling the
   * resolver directly: the resolver being correct in isolation says nothing
   * about whether the create actually calls it, and the wiring is three lines
   * that can be deleted without the resolver noticing.
   */
  public function testCreateWithoutTimezoneDefaultsToActingUsersZone(): void {
    $actor = $this->createUser([], 'api_actor');
    $actor->set('timezone', 'America/Denver')->save();
    $this->container->get('current_user')->setAccount($actor);

    $values = $this->applyContentFields(['title' => 'No zone supplied']);

    $this->assertSame('America/Denver', $values['field_event_timezone'] ?? NULL,
      "the create path itself applies the acting user's zone");
  }

  /**
   * A create that supplies a timezone keeps it.
   */
  public function testSuppliedTimezoneIsHonouredOverTheDefault(): void {
    $actor = $this->createUser([], 'supplier_actor');
    $actor->set('timezone', 'America/Denver')->save();
    $this->container->get('current_user')->setAccount($actor);

    $values = $this->applyContentFields([
      'title' => 'Zone supplied',
      'field_event_timezone' => 'Europe/Rome',
    ]);

    $this->assertSame('Europe/Rome', $values['field_event_timezone'] ?? NULL,
      'an explicit zone is not overwritten by the default');
  }

  /**
   * The in-person flag submitted through the API reaches the values array.
   *
   * The allowlist constant containing the name proves only that a string is in
   * an array; this proves the field is actually applied on write.
   */
  public function testInPersonFlagIsAppliedOnWrite(): void {
    $actor = $this->createUser([], 'inperson_actor');
    $this->container->get('current_user')->setAccount($actor);

    $values = $this->applyContentFields([
      'title' => 'In person',
      'field_event_in_person' => TRUE,
    ]);

    $this->assertArrayHasKey('field_event_in_person', $values,
      'the API can mark an event as having a physical venue');
    $this->assertTrue((bool) $values['field_event_in_person'],
      'and the submitted value survives');
  }

  /**
   * An acting user with no zone falls back to the site default.
   */
  public function testActingUserWithoutZoneFallsBackToSiteDefault(): void {
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    $actor = $this->createUser([], 'zoneless_actor');
    $actor->set('timezone', '')->save();
    $this->container->get('current_user')->setAccount($actor);

    $values = $this->applyContentFields(['title' => 'Zoneless actor']);

    $this->assertSame('America/New_York', $values['field_event_timezone'] ?? NULL,
      'the site default stands in, as it does on the form');
  }

  /**
   * Runs the controller's private field-application step on a request body.
   */
  private function applyContentFields(array $body): array {
    $controller = \Drupal\access_events\Controller\EventCrudApiController::create($this->container);
    $method = new \ReflectionMethod($controller, 'applyContentFields');
    $method->setAccessible(TRUE);
    $values = [];
    $method->invokeArgs($controller, [&$values, $body]);
    return $values;
  }

  /**
   * Reads the controller's writable-field allowlist.
   */
  private function writableContentFields(): array {
    $reflection = new \ReflectionClass(
      \Drupal\access_events\Controller\EventCrudApiController::class
    );
    return $reflection->getConstant('CONTENT_ATTRIBUTES');
  }

}
