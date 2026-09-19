<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

/**
 * Tests the event timezone and in-person fields, and their inheritance.
 *
 * Two things here fail silently if they are wrong, so both are pinned:
 *
 * - The inherited field is read on the instance under a name with the
 *   `eventinstance_default_` prefix STRIPPED (field_inheritance names the
 *   computed field from the config id via idWithoutTypeAndBundle()). Reading
 *   `field_event_timezone` on an instance returns nothing, and for the boolean
 *   nothing is falsy, which renders the safe branch and hides the bug.
 * - The inheritance plugin resolves its source through a `field_inheritance`
 *   base field, not an entity reference, so a field can be configured
 *   correctly and still read empty if that map carries no pointer at the
 *   source series for the instance.
 *
 * @group access_events
 */
class EventTimezoneFieldTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The fields and their inheritance configs must exist BEFORE any instance is
   * created: configureDefaultInheritances() writes a per-field entry only for
   * the configs present at creation time, so an instance created first never
   * picks up a field added later. Pre-existing instances resolve a later-added
   * field through the base field's entities[] fallback instead — pinned in
   * EventTimezoneBackfillTest.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seedTimezoneFields();
  }

  /**
   * The series carries both fields, with the in-person flag defaulting off.
   */
  public function testSeriesCarriesBothFields(): void {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();

    $this->assertTrue($series->hasField('field_event_timezone'),
      'the series has a timezone field');
    $this->assertTrue($series->hasField('field_event_in_person'),
      'the series has an in-person field');
    $this->assertTrue(
      $series->get('field_event_in_person')->isEmpty()
        || !$series->get('field_event_in_person')->value,
      'in-person defaults off, so an unset event renders viewer-local'
    );
  }

  /**
   * The timezone field stores an IANA name.
   */
  public function testTimezoneFieldStoresAnIanaName(): void {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();

    $series->set('field_event_timezone', 'America/Chicago');
    $series->save();

    $this->assertSame('America/Chicago',
      $this->reloadInstance($instance)->getEventSeries()
        ->get('field_event_timezone')->value,
      'the stored value round-trips as the IANA name');
  }

  /**
   * The inherited fields are read on the INSTANCE under their stripped names.
   *
   * This is the assertion that catches the silent failure: if the config ids
   * or the read names are wrong, these return empty rather than raising.
   */
  public function testInheritedFieldsResolveOnTheInstanceUnderStrippedNames(): void {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $series->set('field_event_timezone', 'America/Denver');
    $series->set('field_event_in_person', TRUE);
    $series->save();

    $reloaded = $this->reloadInstance($instance);

    $this->assertTrue($reloaded->hasField('event_timezone'),
      'the instance exposes the inherited timezone under its stripped name');
    $this->assertTrue($reloaded->hasField('event_in_person'),
      'the instance exposes the inherited flag under its stripped name');

    $this->assertSame('America/Denver',
      $reloaded->get('event_timezone')->value,
      'and it resolves to a NON-EMPTY value from the series');
    $this->assertSame('1',
      (string) $reloaded->get('event_in_person')->value,
      'the in-person flag inherits as set');
  }

  /**
   * The timezone field accepts any string at the storage layer.
   *
   * Deliberate: the field is a plain string so the IANA option list comes from
   * core at form-build time (TimeZoneFormHelper) rather than being frozen into
   * config allowed_values, which would go stale as the timezone database
   * changes. The constraint that a value must BE a valid IANA name is enforced
   * at form build, where access_events_form_alter() swaps the textfield for a
   * select populated from TimeZoneFormHelper.
   */
  public function testStorageAcceptsAnyStringAndDefersValidationToTheWidget(): void {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();

    $series->set('field_event_timezone', 'Europe/Rome');
    $series->save();

    $this->assertSame('Europe/Rome',
      $this->reloadInstance($instance)->getEventSeries()
        ->get('field_event_timezone')->value);
    $this->assertContains('Europe/Rome',
      array_keys(\Drupal\Core\Datetime\TimeZoneFormHelper::getOptionsList()),
      'and the value is one core would offer in the select');
  }

  /**
   * The prefixed name is NOT what the instance exposes.
   *
   * Pins the naming from the other direction, so an implementer who reaches
   * for `field_event_timezone` on an instance learns it immediately rather
   * than through a silently-empty render.
   */
  public function testInstanceDoesNotExposeThePrefixedFieldName(): void {
    $instance = $this->createRegistrableInstance();

    $this->assertFalse($instance->hasField('field_event_timezone'),
      'the instance does not carry the series field name');
    $this->assertFalse($instance->hasField('field_event_in_person'),
      'the instance does not carry the series flag name');
  }

}
