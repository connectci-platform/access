<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Entity\EventSeriesAccess;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * The eventseries "default" bundle resolves to the access bundle class.
 *
 * The bundle class is where the recurrence-boundary storage invariant lives;
 * every consumer (API controller, form widget, instance rebuild) gets it for
 * free only if BOTH creation and load paths hand back EventSeriesAccess. The
 * raw-boundary fixture trait is exercised here too, because a direct DB write
 * is the only way tests can manufacture legacy-shaped boundary rows.
 *
 * @group access_events
 */
class EventSeriesBundleClassTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and the affinity-group node insert hook (reads
    // field_affinity_group). Seed the empty site-level fields those hooks
    // touch, mirroring EventCrudCancelOccurrenceTest.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1, []],
      ['eventinstance', 'domain_access', 'string', -1, []],
      ['eventseries', 'field_other_authors', 'entity_reference', -1, ['target_type' => 'user']],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality, $settings]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
          'settings' => $settings,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }

    if (!FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
      FieldStorageConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => 1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A freshly created (unsaved) series is already the bundle class.
   */
  public function testCreatedSeriesIsBundleClass(): void {
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->create(['type' => 'default']);
    $this->assertInstanceOf(EventSeriesAccess::class, $series);
  }

  /**
   * A saved-then-loaded series is the bundle class, flag defaulting FALSE.
   */
  public function testLoadedSeriesIsBundleClass(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);

    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $storage->resetCache([$series->id()]);
    $loaded = $storage->load($series->id());

    $this->assertInstanceOf(EventSeriesAccess::class, $loaded);
    // The controller-writer flag is transient state, never persisted: a fresh
    // load must always come up FALSE.
    $this->assertFalse($loaded->recurBoundariesNormalized);
  }

  /**
   * The fixture trait round-trips a raw boundary value untouched.
   *
   * setRawBoundary() writes the columns directly, so whatever shape it plants
   * (here a T00:00:00 wall-clock pair unlike anything the entity API would
   * save) must read back byte-identical from the field item.
   */
  public function testSetRawBoundaryRoundTripsRawValues(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);

    $this->setRawBoundary($series, 'weekly_recurring_date', '2999-02-02T00:00:00', '2999-02-08T00:00:00');

    $loaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->load($series->id());
    $item = $loaded->get('weekly_recurring_date');
    $this->assertSame('2999-02-02T00:00:00', $item->value);
    $this->assertSame('2999-02-08T00:00:00', $item->end_value);
  }

}
