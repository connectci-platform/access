<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\RegistrantCounter;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the registrant-count helper for the has_registrations safety gate.
 *
 * @covers \Drupal\access_events\RegistrantCounter
 * @group access_events
 */
class RegistrantCounterTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The base module list plus the modules access_events needs to compile. The
   * counter service (access_events.registrant_counter) only lands in the
   * container when access_events is enabled; key + content_moderation +
   * access_misc are hard service-compile dependencies of access_events.
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
    'taxonomy',
    'key',
    'workflows',
    'content_moderation',
    'access_misc',
    'access_events',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Enabling access_events activates access_events_entity_presave(), which on
    // every eventinstance/eventseries save reads site-level fields absent in
    // this minimal kernel env. Attach them empty so the hook's reads return
    // empty and its conditional blocks are skipped.
    $this->attachInstancePresaveFields();

    // access_events_entity_access() reads field_other_authors on every
    // eventseries access check (unconditionally, no hasField guard), and it
    // must exist to avoid fatals.
    if (!FieldStorageConfig::loadByName('eventseries', 'field_other_authors')) {
      FieldStorageConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }

    // field_affinity_group on node (taxonomy_term reference) — used by
    // createAffinityGroupNode() fixture.
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

    // Grant moderation transitions to authenticated so series/instance saves
    // don't fail in presave hooks.
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      [
        'use editorial transition create_new_draft',
        'use editorial transition archived_draft',
        'use editorial transition review_to_review',
        'use editorial transition send_for_review',
      ],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Attaches the empty site-level fields access_events_entity_presave() reads.
   */
  protected function attachInstancePresaveFields(): void {
    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Counts registrants for a single instance when multiple exist.
   */
  public function testCountForInstanceCountsAttachedRegistrants(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);
    $this->registerUser($this->createUser(), $instance);
    $counter = \Drupal::service('access_events.registrant_counter');
    $this->assertSame(2, $counter->countForInstance((int) $instance->id()));
  }

  /**
   * Zero registrants returns zero.
   */
  public function testCountForInstanceZeroWhenNone(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $counter = \Drupal::service('access_events.registrant_counter');
    $this->assertSame(0, $counter->countForInstance((int) $instance->id()));
  }

  /**
   * Series registrant count sums across all instances.
   */
  public function testCountForSeriesSumsAcrossInstances(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);
    $series = $instance->getEventSeries();
    $counter = \Drupal::service('access_events.registrant_counter');
    $this->assertSame(1, $counter->countForSeries((int) $series->id()));
  }

  /**
   * A series' future count excludes registrants on its past instances.
   */
  public function testCountFutureForSeriesExcludesPastInstances(): void {
    // A future instance with a registrant, and a past instance with a registrant.
    $futureInstance = $this->createRegistrableInstance(); // default: future date
    $this->registerUser($this->createUser(), $futureInstance);
    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $this->registerUser($this->createUser(), $pastInstance);
    $series = $futureInstance->getEventSeries();
    // Put the past instance on the SAME series so countFutureForSeries can discriminate.
    $pastInstance->set('eventseries_id', $series->id())->save();
    $counter = \Drupal::service('access_events.registrant_counter');
    // Only the future registrant counts.
    $this->assertSame(1, $counter->countFutureForSeries((int) $series->id()));
  }

  /**
   * An instance's future count is zero once the instance has ended.
   */
  public function testCountFutureForInstanceZeroWhenInstancePast(): void {
    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $this->registerUser($this->createUser(), $pastInstance);
    $counter = \Drupal::service('access_events.registrant_counter');
    $this->assertSame(0, $counter->countFutureForInstance((int) $pastInstance->id()));
  }

  /**
   * An instance's future count includes its registrants while still future.
   */
  public function testCountFutureForInstanceCountsWhenFuture(): void {
    $futureInstance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $futureInstance);
    $counter = \Drupal::service('access_events.registrant_counter');
    $this->assertSame(1, $counter->countFutureForInstance((int) $futureInstance->id()));
  }

  /**
   * Not-past count includes NULL end-date registrants.
   */
  public function testCountNotPastIncludesNullEndRegistrants(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'nullend'), $instance);
    // NULL-end dates exist only as legacy data — the entity API refuses to create
    // them. Seed the condition at the database layer (where the counter reads).
    $entityType = $instance->getEntityType();
    $tableName = $entityType->getDataTable() ?: $entityType->getBaseTable();
    \Drupal::database()->update($tableName)
      ->fields(['date__end_value' => NULL])
      ->condition('id', $instance->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache([$instance->id()]);
    $counter = \Drupal::service('access_events.registrant_counter');
    $this->assertSame(0, $counter->countFutureForInstance((int) $instance->id()));
    $this->assertSame(1, $counter->countNotPastForInstance((int) $instance->id()));
    $this->assertSame(1, $counter->countNotPastForSeries((int) $instance->getEventSeries()->id()));
  }

  /**
   * endIsNotVerifiablyPast boundary tests.
   */
  public function testEndIsNotVerifiablyPastBoundary(): void {
    $now = \Drupal::time()->getRequestTime();
    $this->assertTrue(RegistrantCounter::endIsNotVerifiablyPast(NULL, $now));
    $this->assertTrue(RegistrantCounter::endIsNotVerifiablyPast('not-a-date', $now));
    $this->assertTrue(RegistrantCounter::endIsNotVerifiablyPast(gmdate('Y-m-d\TH:i:s', $now + 3600), $now));
    $this->assertFalse(RegistrantCounter::endIsNotVerifiablyPast(gmdate('Y-m-d\TH:i:s', $now - 3600), $now));
  }

}
