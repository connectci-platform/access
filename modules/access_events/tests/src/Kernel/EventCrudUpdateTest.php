<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Covers PATCH /api/2.3/event-series/{eventseries} — the update-event endpoint.
 *
 * The endpoint edits an existing series' CONTENT fields only (title + the
 * whitelisted content attributes). It never writes moderation_state (a
 * transition op, gated separately) and never touches recurrence config. It is
 * gated by the coordinator authz helper: a user who neither holds an eventseries
 * edit permission nor coordinates one of the series' affinity groups is refused.
 */
class EventCrudUpdateTest extends EventKernelTestBase {

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
    'node',
    'filter',
    'workflows',
    'content_moderation',
    'access_affinitygroup',
    'key',
    'access_events',
    'access_misc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The series save resolves through access_events_entity_presave (reads
    // domain_access on the series) and access_events_entity_access (reads
    // field_other_authors on every series access check). makeCoordinatorSeries
    // also spawns an instance, whose presave reads domain_access + the
    // post-survey tracking fields. Seed the empty site-level fields those hooks
    // touch, plus the two whitelisted content fields the tests assert on.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
      ['eventseries', 'field_summary', 'string_long', 1],
      ['eventseries', 'field_location', 'text', 1],
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

    // In production every authenticated user holds 'add eventseries entity'.
    // The update endpoint's authz never calls createAccess, but grant it anyway
    // to keep the authenticated role faithful to prod config.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A coordinator may edit the series' content fields.
   */
  public function testUpdateEventEditsContentFieldsOnly(): void {
    $coordinator = $this->createUser();
    $series = $this->makeCoordinatorSeries($coordinator);
    $response = $this->doCrud('update', (int) $series->id(), $coordinator, [
      'title' => 'Renamed',
      'field_location' => 'Room B',
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame((int) $series->id(), $data['series_id']);
    $this->assertContains('title', $data['updated_fields']);
    $this->assertContains('field_location', $data['updated_fields']);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertSame('Renamed', $reloaded->label());
    $this->assertSame('Room B', $reloaded->get('field_location')->value);
  }

  /**
   * A caller-supplied moderation_state is ignored — update is content-only.
   *
   * A draft series stays draft even when the body asks to publish, because
   * update never writes moderation_state (that is a transition op, gated
   * separately). This also keeps grant-authorized coordinators — who may lack a
   * moderation transition — able to save content without tripping transition
   * validation.
   */
  public function testUpdateEventNeverChangesModerationState(): void {
    $coordinator = $this->createUser();
    $series = $this->makeCoordinatorSeries($coordinator);
    $this->assertSame('draft', $series->get('moderation_state')->value);

    $response = $this->doCrud('update', (int) $series->id(), $coordinator, [
      'moderation_state' => 'published',
      'title' => 'X',
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // moderation_state is never in the written set.
    $this->assertNotContains('moderation_state', $data['updated_fields']);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertSame('draft', $reloaded->get('moderation_state')->value);
  }

  /**
   * A user who neither edits nor coordinates the series is refused.
   */
  public function testUpdateEventRejectsNonCoordinator(): void {
    $series = $this->makeCoordinatorSeries($this->createUser());
    $response = $this->doCrud('update', (int) $series->id(), $this->createUser(), [
      'title' => 'X',
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * An unknown series id is a 404.
   */
  public function testUpdateEventNotFound(): void {
    $response = $this->doCrud('update', 999999, $this->createUser(), ['title' => 'X']);
    $this->assertSame(404, $response->getStatusCode());
  }

}
