<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\user\Entity\User;

/**
 * Tests the timezone the event form pre-selects for an author.
 *
 * The old form told the author their own zone and asked them to do the
 * arithmetic if the event was somewhere else. Production shows what that
 * produced: 30 events with a timezone typed into the location free-text field.
 * The field replaces the arithmetic, so the value it starts on has to be the
 * one the author was already assuming.
 *
 * @group access_events
 */
class EventTimezoneFormDefaultTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
  }

  /**
   * An author with an account timezone gets it pre-selected.
   */
  public function testAuthorAccountTimezoneIsTheDefault(): void {
    $author = User::create([
      'name' => 'chicago_author',
      'mail' => 'chicago@example.com',
      'status' => 1,
      'timezone' => 'America/Chicago',
    ]);
    $author->save();

    $this->assertSame(
      'America/Chicago',
      _access_events_default_event_timezone($author),
      "the author's own zone is what they were already assuming"
    );
  }

  /**
   * An author with no timezone set falls back to the site default.
   */
  public function testAuthorWithoutTimezoneFallsBackToSiteDefault(): void {
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    $author = User::create([
      'name' => 'zoneless_author',
      'mail' => 'zoneless@example.com',
      'status' => 1,
      'timezone' => '',
    ]);
    $author->save();

    $this->assertSame(
      'America/New_York',
      _access_events_default_event_timezone($author),
      'the site default stands in, and the form shows it rather than hiding it'
    );
  }

  /**
   * The resolved default is always a zone core would offer in the select.
   *
   * A default the select cannot represent would render as an empty control,
   * which reads to the author as "no timezone" — the state this work exists
   * to remove.
   */
  public function testResolvedDefaultIsAlwaysSelectable(): void {
    $options = \Drupal\Core\Datetime\TimeZoneFormHelper::getOptionsList();

    foreach (['America/Chicago', 'Australia/Perth', 'Europe/Rome', ''] as $zone) {
      $author = User::create([
        'name' => 'author_' . md5($zone),
        'mail' => md5($zone) . '@example.com',
        'status' => 1,
        'timezone' => $zone,
      ]);
      $author->save();

      $resolved = _access_events_default_event_timezone($author);
      $this->assertArrayHasKey($resolved, $options,
        "the resolved default ($resolved) is offered by the select");
    }
  }

}
