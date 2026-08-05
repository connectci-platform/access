<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Covers DELETE /api/2.3/event-series/{eventseries} — the delete-event endpoint.
 *
 * This is a soft-delete: a series that was ever published is ARCHIVED (the
 * series and each of its instances transition to moderation_state = archived),
 * while a never-published draft is HARD-deleted (which cascades its instance
 * deletes via the recurring_events predelete hook). The op is gated by:
 *  - the coordinator/entity-access helper (userMayManageSeries('delete'));
 *  - a preview/confirm step (nothing is written without confirmed=TRUE);
 *  - registrant protection (refused without force when registrants exist);
 *  - the `archive` moderation transition permission (published → archived),
 *    which per the live config only news_pm/administrator hold — so an author
 *    or affinity_group_leader who owns the series is refused the archive even
 *    though entity access lets them "manage" it.
 */
class EventCrudDeleteTest extends EventKernelTestBase {

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
    // field_other_authors on every series access check). Seed the empty
    // site-level fields those hooks touch, mirroring EventCrudUpdateTest.
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

    // In production every authenticated user holds 'add eventseries entity'.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A published series and each of its instances is archived, not hard-deleted.
   */
  public function testDeletePublishedEventArchivesSeriesAndEachInstance(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instanceIds = array_map(fn ($i) => $i->id(), $series->event_instances->referencedEntities());
    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $reloadedSeries = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertNotNull($reloadedSeries);
    $this->assertSame('archived', $reloadedSeries->get('moderation_state')->value);
    foreach ($instanceIds as $iid) {
      $inst = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($iid);
      $this->assertNotNull($inst);
      $this->assertSame('archived', $inst->get('moderation_state')->value);
    }
  }

  /**
   * Without confirmed the op previews and writes nothing.
   */
  public function testDeleteWithoutConfirmedPreviewsAndWritesNothing(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], []);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * With registrants attached and no force, the op refuses and writes nothing.
   */
  public function testDeleteRefusesWhenRegistrantsExistWithoutForce(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $instance = $series->event_instances->referencedEntities()[0];
    $this->registerUser($this->createUser(), $instance);
    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('has_registrations', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * A never-published draft series is hard-deleted.
   */
  public function testDeleteNeverPublishedDraftHardDeletes(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $sid = $series->id();
    $response = $this->doCrud('delete', (int) $sid, $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($sid));
  }

  /**
   * A plain author who owns the series lacks the `archive` transition.
   *
   * Entity access (userMayManageSeries('delete')) passes for the author (they
   * own the series), but the transition gate refuses — proving delete_event
   * checks the transition specifically, not just "may manage" entity access.
   */
  public function testAuthorMayNotDeleteOwnPublishedEventLacksArchiveTransition(): void {
    $author = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($author);
    $response = $this->doCrud('delete', (int) $series->id(), $author, [], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * An affinity_group_leader holds series publish but NOT archive.
   */
  public function testAgLeaderMayNotDeleteOwnPublishedEventLacksArchiveTransition(): void {
    $agLeader = $this->createUser([], NULL, FALSE, ['roles' => ['affinity_group_leader']]);
    $series = $this->makePublishedCoordinatorSeries($agLeader);
    $response = $this->doCrud('delete', (int) $series->id(), $agLeader, [], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A series published once but now needs_adjustment can't be archived — no 500.
   *
   * wasEverPublished() scans ALL revisions, so a series that was published and
   * then moved to needs_adjustment (a DEFAULT-revision unpublished state,
   * reached via request_adjustment) still enters the archive branch. But the
   * only legal archive transition is published → archived; there is no
   * needs_adjustment → archived transition, so validating it must NOT throw an
   * uncaught \InvalidArgumentException (HTTP 500). The op refuses cleanly
   * instead: the event is not in a state that can be archived.
   */
  public function testDeleteWasPublishedNowNeedsAdjustmentRefusesInsteadOf500(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    // Move the series to needs_adjustment (a default-revision state, so it
    // becomes the current state) while leaving a published revision behind, so
    // wasEverPublished() stays TRUE.
    $series->set('moderation_state', 'needs_adjustment')->save();

    $response = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('needs_adjustment', $reloaded->get('moderation_state')->value);
  }

  /**
   * Re-deleting an already-archived series is idempotent — no 500.
   *
   * The first delete archives the published series. A second delete finds it
   * everPublished (TRUE) and enters the archive branch again, but archived →
   * archived is not a legal transition, so validating it must NOT throw an
   * uncaught \InvalidArgumentException (HTTP 500). The already-archived series is
   * effectively already soft-deleted, so the re-delete returns a clean success
   * no-op with the series still archived.
   */
  public function testDeleteAlreadyArchivedSeriesIsIdempotentNo500(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($coordinator);

    $first = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $first->getStatusCode());
    $archived = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('archived', $archived->get('moderation_state')->value);

    $second = $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $second->getStatusCode());
    $data = json_decode($second->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('archived', $reloaded->get('moderation_state')->value);
  }

  /**
   * news_pm holds `archive` on both workflows and may delete any published event.
   */
  public function testNewsPmCanDeleteAnyPublishedEvent(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([$this->createUser()->id()]);
    $series = $this->makePublishedCoordinatorSeries($this->createUser());
    $series->set('field_affinity_group_node', [$group->id()])->save();
    $response = $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
  }

}
