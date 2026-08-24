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
   * An authenticated user may create an event with no affinity group.
   *
   * Depends on the prod field field_affinity_group_node being required: FALSE
   * (field.field.eventseries.default.field_affinity_group_node.yml). If that
   * ever flips to required, this kernel test stays green while prod browser
   * POSTs start failing — this comment is the breadcrumb.
   */
  public function testCreateEventWithNoAffinityGroupSucceeds(): void {
    $user = $this->createUser();
    $body = [
      'title' => 'Group-less Event',
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $this->assertSame('draft', $series->get('moderation_state')->value);
    $this->assertCount(1, $data['instance_ids']);
    // The field is written only when a group resolved; group-less => empty.
    $this->assertTrue($series->get('field_affinity_group_node')->isEmpty());
  }

  /**
   * A supplied affinity group that resolves to nothing is a validation error,
   * not a silent group-less create.
   */
  public function testCreateEventRejectsUnresolvableAffinityGroup(): void {
    $user = $this->createUser();
    $body = [
      'title' => 'Bad UUID Event',
      'field_affinity_group_node' => ['not-a-real-uuid'],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('validation_error', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A mix of one good and one bad UUID proceeds with only the resolved group;
   * the bad UUID is silently dropped (accepted footgun, locked here).
   */
  public function testCreateEventPartialResolveKeepsOnlyResolvedGroup(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    $body = [
      'title' => 'Partial Resolve Event',
      'field_affinity_group_node' => [$group->uuid(), 'not-a-real-uuid'],
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $coordinator, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $groupValue = $series->get('field_affinity_group_node')->getValue();
    $this->assertCount(1, $groupValue);
    $this->assertSame((string) $group->id(), (string) $groupValue[0]['target_id']);
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

  /**
   * Creating without a required field refuses, names the field, saves nothing.
   *
   * create() used to skip entity validation entirely, so a minimal API create
   * (title + recur_type only) birthed a draft violating the site's required
   * fields (field_event_type, field_location) — a draft the browser form could
   * never save, and one whose every subsequent content edit was refused by
   * update()'s validation. The API must refuse at birth instead, with the
   * field name in the message.
   */
  public function testCreateEventMissingRequiredFieldRefusedWithFieldName(): void {
    $this->createEventTypeField(TRUE);

    $user = $this->createUser();
    $body = [
      'title' => 'Incomplete Event',
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('validation_error', $data['error']);
    $this->assertStringContainsString('field_event_type', $data['message']);

    $ids = \Drupal::entityQuery('eventseries')->accessCheck(FALSE)->execute();
    $this->assertSame([], $ids, 'No series may be persisted on a validation refusal.');
  }

  /**
   * An API-created series is scoped to the request's active domain.
   *
   * On this site an EMPTY domain_access means "affiliated to ALL domains".
   * The browser form fills the field from the current domain via a
   * form-submit handler the API never runs, and the controller drops
   * caller-supplied domain_access for non-admins — so API-created series were
   * born unscoped and would surface on every affiliate site once published.
   * The controller must fill an empty domain_access from the active domain
   * (the MCP calls support.access-ci.org, so its events belong to ACCESS
   * Support; an MCP deployment for another affiliate, pointed at that
   * domain's hostname, scopes automatically). The kernel env has no domain
   * module, so stub the negotiator the controller consults.
   */
  public function testCreateEventScopesDomainToActiveDomain(): void {
    $domain = new class {

      public function id(): string {
        return 'amp_cyberinfrastructure_org';
      }

    };
    $negotiator = new class($domain) {

      public function __construct(private object $domain) {}

      public function getActiveDomain(): object {
        return $this->domain;
      }

    };
    \Drupal::getContainer()->set('domain.negotiator', $negotiator);

    $user = $this->createUser();
    $body = [
      'title' => 'Scoped Event',
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $seriesDomains = array_column($series->get('domain_access')->getValue(), 'value');
    $this->assertSame(['amp_cyberinfrastructure_org'], $seriesDomains);

    // The presave hook inherits the series domains onto the spawned instance.
    $instance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($data['instance_ids'][0]);
    $instanceDomains = array_column($instance->get('domain_access')->getValue(), 'value');
    $this->assertSame(['amp_cyberinfrastructure_org'], $instanceDomains);
  }

  /**
   * Creating with the option LABEL stores the KEY (zz_other stays internal).
   */
  public function testCreateEventAcceptsOptionLabelAndStoresKey(): void {
    $this->createEventTypeField(TRUE);

    $user = $this->createUser();
    $body = [
      'title' => 'Other-typed Event',
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
      'field_event_type' => 'Other',
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $this->assertSame('zz_other', $series->get('field_event_type')->value);
  }

  /**
   * A rule-recur create passes the validation gate.
   *
   * No recurring_events rule field is required, so validation must not refuse
   * a rule-type create — the gate exists for field constraints, not to demand
   * recurrence config the custom path doesn't need either.
   */
  public function testCreateEventWithRuleRecurTypePassesValidationGate(): void {
    // The weekly spawn path parses times via core date-format config entities
    // (html_date / html_time), which kernel tests don't get by default.
    $this->installConfig(['system']);

    $user = $this->createUser();
    $body = [
      'title' => 'Weekly Event',
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => [
        [
          'value' => '2099-06-01T00:00:00',
          'end_value' => '2099-06-08T00:00:00',
          'time' => '10:00 am',
          'end_time' => '11:00 am',
          'duration' => '60',
          'duration_or_end_time' => 'duration',
          'days' => 'monday',
        ],
      ],
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Creating with the required field supplied still succeeds.
   */
  public function testCreateEventWithRequiredFieldSucceeds(): void {
    $this->createEventTypeField(TRUE);

    $user = $this->createUser();
    $body = [
      'title' => 'Complete Event',
      'recur_type' => 'custom',
      'custom_dates' => [['start_date' => '2099-06-15T14:00:00', 'end_date' => '2099-06-15T16:00:00']],
      'field_event_type' => 'Training',
    ];
    $response = $this->doCrud('create', NULL, $user, $body);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($data['series_id']);
    $this->assertSame('Training', $series->get('field_event_type')->value);
  }

}
