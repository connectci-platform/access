<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the single place the has-registrations delete rule is decided.
 *
 * @covers \Drupal\access_events\EventDeleteGuard
 * @group access_events
 *
 * An eventseries or eventinstance that has ANY registrations (past or
 * future — attendance history is protected data) can never be hard-deleted.
 * Every layer that can reach a delete (the two predelete hooks, the contrib
 * pre_delete_instance hook, the two delete-form alters, and the API
 * draft-delete branch) asks this ONE service instead of re-deriving the
 * count/message logic itself.
 */
class EventDeleteGuardTest extends EventKernelTestBase {

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

    // Enabling access_events activates access_events_entity_presave(), which
    // on every eventinstance/eventseries save reads site-level fields absent
    // in this minimal kernel env. Attach them empty so the hook's reads
    // return empty and its conditional blocks are skipped. Mirrors
    // RegistrantCounterTest's setUp().
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

    // access_events_entity_access() reads field_other_authors on every
    // eventseries access check.
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
   * A series with no registrants anywhere is deletable.
   */
  public function testSeriesWithNoRegistrantsHasNullReason(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $series = $instance->getEventSeries();
    $guard = \Drupal::service('access_events.event_delete_guard');
    $this->assertNull($guard->deletionBlockedReason($series));
  }

  /**
   * A series with a registrant on any one of its instances is blocked.
   */
  public function testSeriesWithRegistrantHasReason(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);
    $series = $instance->getEventSeries();
    $guard = \Drupal::service('access_events.event_delete_guard');
    $this->assertSame(
      'This event has 1 registration(s) and cannot be deleted. Cancel the event instead (registrations are kept and registrants notified), or remove the registrations first.',
      $guard->deletionBlockedReason($series),
    );
  }

  /**
   * A PAST registration still blocks series deletion — history is protected.
   */
  public function testSeriesWithPastRegistrantHasReason(): void {
    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $this->registerUser($this->createUser(), $pastInstance);
    $series = $pastInstance->getEventSeries();
    $guard = \Drupal::service('access_events.event_delete_guard');
    $this->assertNotNull($guard->deletionBlockedReason($series));
  }

  /**
   * An instance with no registrants is deletable.
   */
  public function testInstanceWithNoRegistrantsHasNullReason(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $guard = \Drupal::service('access_events.event_delete_guard');
    $this->assertNull($guard->deletionBlockedReason($instance));
  }

  /**
   * An instance with a registrant is blocked, with the count in the message.
   */
  public function testInstanceWithRegistrantsHasReasonWithCount(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);
    $this->registerUser($this->createUser(), $instance);
    $guard = \Drupal::service('access_events.event_delete_guard');
    $this->assertSame(
      'This event has 2 registration(s) and cannot be deleted. Cancel the event instead (registrations are kept and registrants notified), or remove the registrations first.',
      $guard->deletionBlockedReason($instance),
    );
  }

  /**
   * A silent no-op: assertDeletable() when there is nothing blocking.
   */
  public function testAssertDeletableDoesNotThrowWhenDeletable(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $guard = \Drupal::service('access_events.event_delete_guard');
    // No exception expected — this line completing IS the assertion.
    $guard->assertDeletable($instance);
    $this->addToAssertionCount(1);
  }

  /**
   * The throw message matches deletionBlockedReason()'s returned string.
   */
  public function testAssertDeletableThrowsWithReasonMessage(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);
    $guard = \Drupal::service('access_events.event_delete_guard');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('This event has 1 registration(s) and cannot be deleted. Cancel the event instead (registrations are kept and registrants notified), or remove the registrations first.');
    $guard->assertDeletable($instance);
  }

}
