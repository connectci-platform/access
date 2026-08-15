<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
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

  /**
   * A content edit on a multi-occurrence draft series succeeds.
   *
   * The old $series->validate() raised a spurious core cardinality violation on
   * the computed event_instances field (cardinality 1, populated with many via
   * direct saves) for any series with more than one occurrence, so every edit to
   * a recurring series failed. This is the reproduction that must now pass.
   */
  public function testUpdateContentOnMultiOccurrenceDraftSeriesSucceeds(): void {
    $c = $this->createUser();
    $series = $this->makeCoordinatorSeries($c);
    // Add a second instance so the series is multi-occurrence (the trigger).
    $second = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);
    $second->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($second, (int) $series->id());

    $response = $this->doCrud('update', (int) $series->id(), $c, ['title' => 'Edited title']);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
  }

  /**
   * A published series edited by a coordinator who lacks the publish transition
   * is still refused — the moderation gate survives the validate() removal.
   */
  public function testUpdateContentOnPublishedSeriesByCoordinatorWithoutPublishRefused(): void {
    $c = $this->createUser();
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($c);
    $response = $this->doCrud('update', (int) $series->id(), $c, ['title' => 'Sneaky edit']);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_state', $data['error']);
    // It must be the transition-access refusal, NOT a cardinality message.
    $this->assertStringNotContainsStringIgnoringCase('event instances', $data['message']);
    $this->assertStringNotContainsStringIgnoringCase('cannot hold more than', $data['message']);
  }

  /**
   * An administrator holds the publish transition, so their content edit on a
   * published multi-occurrence series succeeds — the gate is not over-broad.
   */
  public function testUpdateContentOnPublishedSeriesByAdminSucceeds(): void {
    $c = $this->createUser();
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($c);
    $admin = $this->createUser([], NULL, FALSE, ['roles' => ['administrator']]);
    $response = $this->doCrud('update', (int) $series->id(), $admin, ['title' => 'Admin edit']);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * A series in a state with no self-transition (archived) refuses cleanly with
   * a 409, not a 500 — the hasTransitionFromStateToState existence guard fires
   * before isTransitionValid, which would otherwise throw.
   */
  public function testUpdateContentOnArchivedSeriesRefusedCleanlyNot500(): void {
    $c = $this->createUser();
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($c);
    $series->set('moderation_state', 'archived')->save();
    $admin = $this->createUser([], NULL, FALSE, ['roles' => ['administrator']]);
    $response = $this->doCrud('update', (int) $series->id(), $admin, ['title' => 'Edit archived']);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_state', $data['error']);
  }

  /**
   * A disallowed list value is refused and not persisted.
   *
   * field_event_type restricts values via allowed_values, and entity
   * validation is the ONLY enforcement — the column is a plain varchar and
   * core never re-checks constraints at raw save() time. The endpoint must
   * still run field validation on a multi-occurrence series, filtering out
   * only the spurious event_instances cardinality violation (recurring_events
   * #3272361), not skipping validation wholesale.
   */
  public function testUpdateWithDisallowedListValueRefused(): void {
    // Mirror the site's real field_event_type: list_string, allowed_values.
    $this->createEventTypeField(FALSE);

    $c = $this->createUser();
    $series = $this->makeCoordinatorSeries($c);
    // Multi-occurrence, so the spurious cardinality violation is present too —
    // the refusal below must come from the allowed-values check surviving the
    // filter, not from the filtered-out event_instances violation.
    $second = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);
    $second->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($second, (int) $series->id());

    $response = $this->doCrud('update', (int) $series->id(), $c, ['field_event_type' => 'garbage']);
    $this->assertSame(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('validation_error', $data['error']);
    $this->assertStringNotContainsStringIgnoringCase('event instances', $data['message']);
    $this->assertStringNotContainsStringIgnoringCase('cannot hold more than', $data['message']);

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertTrue($reloaded->get('field_event_type')->isEmpty());
  }

  /**
   * The moderation gate refuses BEFORE field validation runs.
   *
   * A coordinator without the publish transition editing a published series
   * with an ALSO-invalid field value gets the moderation 409, not the field
   * 422 — state authorization outranks content validity.
   */
  public function testUpdateModerationRefusalOutranksFieldValidation(): void {
    $this->createEventTypeField(FALSE);

    $c = $this->createUser();
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($c);
    $response = $this->doCrud('update', (int) $series->id(), $c, ['field_event_type' => 'garbage']);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_state', $data['error']);
  }

  /**
   * A list-field write accepts the option LABEL and stores the KEY.
   *
   * field_event_type's "Other" option is stored as the internal sort-hack key
   * zz_other (the key sorts the option last in Drupal's alphabetized admin
   * UI). The read path already emits labels; the write path must accept them
   * so the hack never leaks into the API contract. Keys stay accepted.
   */
  public function testUpdateAcceptsOptionLabelAndStoresKey(): void {
    $this->createEventTypeField(FALSE);

    $c = $this->createUser();
    $series = $this->makeCoordinatorSeries($c);

    $response = $this->doCrud('update', (int) $series->id(), $c, ['field_event_type' => 'Other']);
    $this->assertSame(200, $response->getStatusCode());

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertSame('zz_other', $reloaded->get('field_event_type')->value);
  }

  /**
   * Label matching is case-insensitive — LLM callers will send "office hours".
   */
  public function testUpdateAcceptsOptionLabelCaseInsensitively(): void {
    $this->createEventTypeField(FALSE);

    $c = $this->createUser();
    $series = $this->makeCoordinatorSeries($c);

    $response = $this->doCrud('update', (int) $series->id(), $c, ['field_event_type' => 'office hours']);
    $this->assertSame(200, $response->getStatusCode());

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertSame('Office Hours', $reloaded->get('field_event_type')->value);
  }

  /**
   * Array values normalize element-wise, and a raw KEY still passes through.
   *
   * Multi-value list fields (field_skill_level) legitimately arrive as arrays;
   * single-value fields may too. And legacy callers holding the old schema
   * still send the zz_other key — both must keep working.
   */
  public function testUpdateNormalizesArrayValuesAndKeepsAcceptingKeys(): void {
    $this->createEventTypeField(FALSE);

    $c = $this->createUser();
    $series = $this->makeCoordinatorSeries($c);

    $response = $this->doCrud('update', (int) $series->id(), $c, ['field_event_type' => ['zz_other']]);
    $this->assertSame(200, $response->getStatusCode());

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertSame('zz_other', $reloaded->get('field_event_type')->value);
  }

  /**
   * A validation refusal names the violating field, not just the raw message.
   *
   * The API can create a draft missing a required field (create() does not
   * validate; the site's field_event_type and field_location are required).
   * Editing such a draft is refused by the NotNull constraint, whose bare
   * message ("This value should not be null.") gives the caller no way to know
   * which field to supply. The refusal must carry the property path.
   */
  public function testUpdateValidationRefusalNamesTheField(): void {
    $this->createEventTypeField(TRUE);

    $c = $this->createUser();
    // makeCoordinatorSeries() never sets field_event_type, mirroring a minimal
    // API create — the draft is born violating the required field.
    $series = $this->makeCoordinatorSeries($c);

    $response = $this->doCrud('update', (int) $series->id(), $c, ['title' => 'Edited title']);
    $this->assertSame(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('validation_error', $data['error']);
    $this->assertStringContainsString('field_event_type', $data['message']);
  }

  /**
   * Supplying the missing required field in the same edit succeeds.
   *
   * The recovery path for a draft born incomplete: one update that both edits
   * content and fills the required field passes validation and saves.
   */
  public function testUpdateSupplyingMissingRequiredFieldSucceeds(): void {
    $this->createEventTypeField(TRUE);

    $c = $this->createUser();
    $series = $this->makeCoordinatorSeries($c);

    $response = $this->doCrud('update', (int) $series->id(), $c, [
      'title' => 'Edited title',
      'field_event_type' => 'Conference',
    ]);
    $this->assertSame(200, $response->getStatusCode());

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')
      ->loadUnchanged($series->id());
    $this->assertSame('Conference', $reloaded->get('field_event_type')->value);
    $this->assertSame('Edited title', $reloaded->label());
  }

}
