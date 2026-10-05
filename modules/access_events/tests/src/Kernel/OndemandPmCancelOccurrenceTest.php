<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Pins the D8-2809 config grant: ondemand_pm may cancel OOD occurrences.
 *
 * D8-2809 closed by config alone — a new ondemand_pm role holding the same
 * archive-family transitions as news_pm (user.role.ondemand_pm.yml) — rather
 * than by code, so nothing exercises this grant unless a test does. Mirrors
 * EventCrudCancelOccurrenceTest's news_pm/affinity_group_leader coverage: the
 * published-occurrence cancel path is gated on the `archive` transition on
 * editorial_eventinstance, not merely on "may manage the series", so a plain
 * community member (the `ondemand` role, no workflow transitions) must be
 * refused even though nothing here stops them from authoring the series.
 */
class OndemandPmCancelOccurrenceTest extends EventKernelTestBase {

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

    // The series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and access_events_entity_access (reads
    // field_other_authors on every series access check) — same fixture as
    // EventCrudCancelOccurrenceTest.
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

    // The two OOD roles from D8-2809: `ondemand` (plain community
    // membership, no workflow transitions — user.role.ondemand.yml ships an
    // EMPTY permissions list) and `ondemand_pm` (the events-editor role,
    // permissions copied verbatim from user.role.ondemand_pm.yml). Grant the
    // same permission STRINGS the site config grants, so a future config
    // change that silently drops the archive-family transitions from
    // user.role.ondemand_pm.yml is caught here rather than only in
    // production.
    Role::create(['id' => 'ondemand', 'label' => 'OnDemand'])->save();
    Role::create(['id' => 'ondemand_pm', 'label' => 'OnDemand PM'])->save();
    user_role_grant_permissions('ondemand_pm', [
      'edit eventseries entity',
      'delete eventseries entity',
      'edit eventinstance entity',
      'delete eventinstance entity',
      'use editorial transition archive',
      'use editorial transition archived_draft',
      'use editorial transition archived_published',
      'use editorial transition create_new_draft',
      'use editorial transition publish',
      'use editorial transition request_adjustment',
      'use editorial_eventinstance transition archive',
      'use editorial_eventinstance transition archived_archived',
      'use editorial_eventinstance transition archived_draft',
      'use editorial_eventinstance transition archived_published',
      'use editorial_eventinstance transition create_new_draft',
      'use editorial_eventinstance transition publish',
      'use editorial_eventinstance transition request_adjustment',
    ]);

    // In production every authenticated user holds 'add eventseries entity'.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Loads a series' instances ordered by id, so [0] is the earliest-created.
   */
  private function orderedInstances($series): array {
    $instances = $this->loadInstances($series);
    ksort($instances);
    return array_values($instances);
  }

  /**
   * Ondemand_pm holds `archive` on editorial_eventinstance and may cancel.
   */
  public function testOndemandPmMayCancelOccurrence(): void {
    $ondemandPm = $this->createUser([], NULL, FALSE, ['roles' => ['ondemand_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($ondemandPm);
    $target = $this->orderedInstances($series)[0];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $ondemandPm, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * A plain ondemand community member may not cancel.
   *
   * No workflow transitions on either workflow, even though they authored the
   * series (so userMayManageSeries('delete') passes on entity access alone).
   * Proves the archive transition gate, not "may manage", is what's enforced.
   */
  public function testPlainOndemandMemberMayNotCancelOccurrence(): void {
    $member = $this->createUser([], NULL, FALSE, ['roles' => ['ondemand']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($member);
    $target = $this->orderedInstances($series)[0];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $member, ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'published',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

}
