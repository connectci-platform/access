<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;

/**
 * Covers POST /api/2.3/events — the create-event write endpoint.
 *
 * The endpoint creates a DRAFT eventseries (which auto-spawns instances),
 * gated by an affinity-group coordinator check against the REQUESTED groups,
 * and returns a write envelope carrying a moderation "review-needed" signal
 * derived entirely from the acting user's valid workflow transitions.
 */
class EventCrudCreateTest extends EventKernelTestBase {

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
    // The insert hook auto-spawns instances, whose presave (see
    // access_events_entity_presave) reads domain_access + the post-survey
    // tracking fields on both the series and each instance. Seed the empty
    // site-level fields those hooks touch so the save resolves in this minimal
    // kernel env, plus the two whitelisted content fields under test.
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

    // access_events_entity_access() reads field_other_authors on every
    // eventseries access check (no hasField guard), and the controller's
    // $series->access('create') check runs it. Attach it empty.
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

    // Saving an affinity_group node fires access_affinitygroup_entity_presave()
    // -> add_ag_taxonomy_term(), which reads/sets the node's
    // field_affinity_group (a taxonomy_term reference). The base does not seed
    // it; attach it empty so the hook resolves.
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

    // In production every authenticated user holds 'add eventseries entity'
    // (user.role.authenticated.yml), which governs the entity-type create
    // permission the controller's $series->access('create') check enforces.
    // The base seeds the moderation-transition permissions but not this one.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A coordinator may create a draft series that auto-spawns instances.
   */
  public function testCreateEventAsCoordinatorHardcodesDraftAndSpawnsInstances(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'title' => 'Test Event',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $this->assertSame('draft', $series->get('moderation_state')->value);
    $this->assertCount(1, $data['instance_ids']);
  }

  /**
   * A user who coordinates none of the requested groups is refused.
   */
  public function testCreateEventRejectsNonCoordinator(): void {
    $stranger = $this->createUser();
    $group = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $body = [
      'title' => 'X',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $stranger, $body);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A missing title is a validation error.
   */
  public function testCreateEventValidationErrorOnMissingTitle(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('validation_error', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A caller-supplied moderation_state is ignored — create is always draft.
   */
  public function testCreateEventIgnoresCallerModerationState(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'title' => 'X',
      'moderation_state' => 'published',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    // NOT published.
    $this->assertSame('draft', $series->get('moderation_state')->value);
  }

  /**
   * A plain author holds send_for_review but not publish.
   */
  public function testCreateEventByPlainAuthorSignalsSendForReview(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'title' => 'X',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('draft', $data['moderation']['state']);
    $this->assertFalse($data['moderation']['can_publish']);
    $this->assertSame('send_for_review', $data['moderation']['next_action']);
  }

  /**
   * news_pm holds the full editor set, including publish.
   */
  public function testCreateEventByNewsPmSignalsCanPublish(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([$newsPm->id()]);
    $body = [
      'title' => 'X',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $newsPm, $body);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['moderation']['can_publish']);
    $this->assertNull($data['moderation']['next_action']);
  }

  /**
   * custom_dates map to the entity custom_date field, one instance per date.
   */
  public function testCreateEventMapsCustomDatesToInstances(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'title' => 'Two-Date Event',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [
        ['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00'],
        ['start_date' => '2099-07-20T09:00:00', 'end_date' => '2099-07-20T11:00:00'],
      ],
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(2, $data['instance_ids']);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $customDate = $series->get('custom_date')->getValue();
    $this->assertCount(2, $customDate);
    $this->assertSame('2099-06-15T14:00:00', $customDate[0]['value']);
    $this->assertSame('2099-06-15T16:00:00', $customDate[0]['end_value']);
    $this->assertSame('2099-07-20T09:00:00', $customDate[1]['value']);
    $this->assertSame('2099-07-20T11:00:00', $customDate[1]['end_value']);
  }

  /**
   * Whitelisted content fields are applied from the request body.
   */
  public function testCreateEventAppliesContentFields(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'title' => 'Rich Event',
      'field_affinity_group_node' => [$group->uuid()],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
      'field_summary' => 'A short summary.',
      'field_location' => 'Building 42',
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $this->assertSame('A short summary.', $series->get('field_summary')->value);
    $this->assertSame('Building 42', $series->get('field_location')->value);
  }

}
