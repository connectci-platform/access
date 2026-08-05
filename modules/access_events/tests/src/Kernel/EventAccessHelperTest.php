<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the event-management authorization helper.
 *
 * userMayManageSeries($series, $op) is TRUE when Drupal entity access allows
 * the operation (administrator, an events editor holding the eventseries
 * edit/delete permission, the author, or a field_other_authors user — all via
 * $series->access()), OR when the series carries affinity groups the current
 * user coordinates. An AG-less series relies on entity access alone.
 *
 * @covers \Drupal\access_events\EventAccessHelper
 * @group access_events
 */
class EventAccessHelperTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The base module list plus the modules access_events needs to compile. The
   * helper service (access_events.access_helper) only lands in the container
   * when access_events is enabled; key + content_moderation + access_misc are
   * hard service-compile dependencies of access_events.
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
    // eventseries access check (unconditionally, no hasField guard), and the
    // field_other_authors grant path is under test here. Attach it as a
    // multi-value user reference on the eventseries default bundle.
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

    // Saving an affinity_group node (createAffinityGroupNode()) fires
    // access_affinitygroup_entity_presave() -> add_ag_taxonomy_term(), which
    // reads and sets the node's field_affinity_group (a taxonomy_term
    // reference into the affinity_groups vocab the base already seeds). The
    // base seeds field_coordinator/slug/ext-email fields but not this one, so
    // attach it here; empty, the hook creates a matching term and sets it.
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

    // news_pm is a legitimate events editor: it holds the eventseries
    // edit/delete permission, so $series->access() allows it (no hardcoded
    // role list in the helper). Grant those permissions to the seeded role.
    $this->grantPermissions(
      Role::load('news_pm'),
      ['edit eventseries entity', 'delete eventseries entity'],
    );

    // content_moderation_entity_access() forbids 'update' on a moderated entity
    // when the current user cannot use any transition out of its current state,
    // which would mask every non-admin grant regardless of the entity handler
    // or access_events_entity_access(). In production event editors hold these
    // transition permissions; grant them to the authenticated role so
    // $series->access('update') reflects that baseline (this is not the
    // transition-gate scaffolding — it is the moderation baseline that makes
    // update access resolvable at all).
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
  private function attachInstancePresaveFields(): void {
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
   * news_pm holds the eventseries edit/delete permission, so it may manage.
   */
  public function testNewsPmMayManageAnySeries(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    // newsPm coordinates nothing; the grant comes from entity access alone.
    $group = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $series = $this->createRegistrableInstance()->getEventSeries();
    $series->set('field_affinity_group_node', [$group->id()])->save();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($newsPm, function () use ($helper, $series) {
      $this->assertTrue($helper->userMayManageSeries($series, 'update'));
      $this->assertTrue($helper->userMayManageSeries($series, 'delete'));
    });
  }

  /**
   * An administrator may manage any series.
   */
  public function testAdministratorMayManageAnySeries(): void {
    $admin = $this->createUser([], NULL, FALSE, ['roles' => ['administrator']]);
    $series = $this->createRegistrableInstance()->getEventSeries();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($admin, function () use ($helper, $series) {
      $this->assertTrue($helper->userMayManageSeries($series, 'update'));
    });
  }

  /**
   * The author reaches TRUE via entity access, not a hand-rolled uid check.
   */
  public function testAuthorMayManageOwnSeriesViaEntityAccessNoUidFallback(): void {
    $author = $this->createUser();
    $series = $this->createRegistrableInstance()->getEventSeries();
    $series->set('uid', $author->id())->set('field_affinity_group_node', [])->save();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($author, function () use ($helper, $series) {
      $this->assertTrue($helper->userMayManageSeries($series, 'update'));
    });
  }

  /**
   * A field_other_authors user reaches TRUE via access_events_entity_access().
   */
  public function testFieldOtherAuthorsUserMayManageSeriesViaHook(): void {
    $otherAuthor = $this->createUser();
    $series = $this->createRegistrableInstance()->getEventSeries();
    // A real (non-other-author) author so access_events_entity_access() gets a
    // valid owner and proceeds to its field_other_authors branch; in prod a
    // series is never owned by the anonymous user.
    $series->set('uid', $this->createUser()->id())
      ->set('field_other_authors', [$otherAuthor->id()])
      ->set('field_affinity_group_node', [])
      ->save();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($otherAuthor, function () use ($helper, $series) {
      $this->assertTrue($helper->userMayManageSeries($series, 'update'));
    });
  }

  /**
   * An AG coordinator who did not author the series manages it via the grant.
   */
  public function testAgCoordinatorWhoDidNotAuthorManagesViaCoordinatorGrant(): void {
    $coordinator = $this->createUser();
    $group = $this->createAffinityGroupNode([$coordinator->id()]);
    // Authored by someone else; coordinator has no blanket edit permission.
    $series = $this->createRegistrableInstance()->getEventSeries();
    $series->set('uid', $this->createUser()->id())->set('field_affinity_group_node', [$group->id()])->save();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($coordinator, function () use ($helper, $series) {
      $this->assertTrue($helper->userMayManageSeries($series, 'update'));
    });
  }

  /**
   * A stranger who coordinates no referenced group may not manage the series.
   */
  public function testStrangerMayNotManageSeries(): void {
    $stranger = $this->createUser();
    $group = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $series = $this->createRegistrableInstance()->getEventSeries();
    $series->set('field_affinity_group_node', [$group->id()])->save();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($stranger, function () use ($helper, $series) {
      $this->assertFalse($helper->userMayManageSeries($series, 'update'));
    });
  }

  /**
   * An AG-less series refuses an authenticated non-author (empty-groups guard).
   *
   * Proves the empty-groups guard: with no affinity groups the coordinator
   * branch must NOT fall through to a vacuous grant — only entity access
   * governs, and a stranger with no grant is refused.
   */
  public function testAgLessSeriesAuthenticatedNonAuthorRefused(): void {
    $stranger = $this->createUser();
    $series = $this->createRegistrableInstance()->getEventSeries();
    // Author is the default kernel user, not $stranger.
    $series->set('field_affinity_group_node', [])->save();
    $helper = \Drupal::service('access_events.access_helper');
    $this->asActingUser($stranger, function () use ($helper, $series) {
      $this->assertFalse($helper->userMayManageSeries($series, 'update'));
    });
  }

}
