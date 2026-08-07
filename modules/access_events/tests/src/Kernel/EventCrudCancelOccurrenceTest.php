<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Covers DELETE /api/2.3/event-occurrences/{eventinstance} — cancel occurrence.
 *
 * cancel_occurrence soft-cancels a SINGLE event instance by archiving it: the
 * targeted instance transitions to moderation_state = archived while its
 * sibling instances and the parent series are left untouched. It is the
 * instance-level equivalent of delete_event's archive branch, and is gated
 * identically:
 *  - the coordinator/entity-access helper (userMayManageSeries('delete') on the
 *    instance's PARENT series — instance authz resolves via the series' affinity
 *    group);
 *  - a preview/confirm step (nothing is written without confirmed=TRUE);
 *  - registrants are kept and notified on cancel (no force gate — cancelling
 *    an occurrence with registrants is the normal path, not a destructive one);
 *  - the `archive` moderation transition permission on the
 *    editorial_eventinstance workflow (published → archived), which per the live
 *    config only news_pm/administrator hold — so an author or
 *    affinity_group_leader who may "manage" the instance is still refused the
 *    archive.
 *
 * The transition gate is the correction over the original brief, which showed a
 * bare set('moderation_state','archived')->save() with no transition-permission
 * check and no wrong-state guard: that would let ANY user passing the
 * coordinator check archive an instance (bypassing content_moderation
 * validation), making cancel MORE permissive than delete — an authz
 * inconsistency — and would 500 on a non-published instance.
 */
class EventCrudCancelOccurrenceTest extends EventKernelTestBase {

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
    // site-level fields those hooks touch, mirroring EventCrudDeleteTest.
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
   * Loads a series' instances ordered by id, so [0] is the earliest-created.
   *
   * makePublishedCoordinatorSeriesWithTwoInstances() adds the second instance
   * directly (not via the series insert hook), so the in-memory
   * $series->event_instances field can be stale; a fresh id-ordered load is the
   * deterministic way to pick "the first" vs "the sibling".
   *
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   *   The series' instances, ordered by id ascending.
   */
  private function orderedInstances($series): array {
    $instances = $this->loadInstances($series);
    ksort($instances);
    return array_values($instances);
  }

  /**
   * Cancelling a published occurrence archives it and leaves siblings + series.
   */
  public function testCancelOccurrenceArchivesOneInstanceLeavesSiblings(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $instances = $this->orderedInstances($series);
    $target = $instances[0];
    $sibling = $instances[1];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());

    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);
    // Sibling untouched.
    $this->assertSame('published', $storage->loadUnchanged($sibling->id())->get('moderation_state')->value);
    // Parent series untouched.
    $reloadedSeries = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloadedSeries->get('moderation_state')->value);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame((int) $target->id(), (int) $data['eventinstance_id']);
    $this->assertSame(0, $data['registrants_affected']);
  }

  /**
   * A cancelled occurrence stays archived after an unrelated series update.
   *
   * update_event writes only content fields, never moderation_state, so a
   * later content edit on the series must NOT re-sync the archived instance
   * back to published.
   */
  public function testCancelledOccurrenceStaysArchivedAfterSeriesUpdate(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $target = $this->orderedInstances($series)[0];

    $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    // An unrelated content update on the series must not re-sync the archived
    // instance back to published.
    $this->doCrud('update', (int) $series->id(), $coordinator, ['title' => 'Renamed']);

    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * With registrants attached, cancel proceeds (no force gate), keeps the
   * registration, archives the instance, and notifies.
   */
  public function testCancelOccurrenceWithRegistrantsProceedsAndNotifies(): void {
    // Enable the notification key so the notifier enqueues.
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $target = $this->orderedInstances($series)[0];
    $this->registerUser($this->createUser(), $target);

    // No force. confirmed only.
    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertGreaterThan(0, $data['notified']);
    // Registration kept.
    $this->assertGreaterThan(0, \Drupal::service('access_events.registrant_counter')->countForInstance((int) $target->id()));
    // Instance archived.
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * Without confirmed the op previews and writes nothing.
   */
  public function testCancelOccurrenceWithoutConfirmedPreviewsAndWritesNothing(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $target = $this->orderedInstances($series)[0];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $this->assertSame(
      'published',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * A plain author who owns the series lacks the instance `archive` transition.
   *
   * Entity access (userMayManageSeries('delete') on the parent series) passes
   * for the author (they own the series), but the transition gate refuses —
   * proving cancel_occurrence checks the editorial_eventinstance `archive`
   * transition specifically, not just "may manage" entity access. Identical to
   * delete's author refusal, scoped to one instance.
   */
  public function testAuthorMayNotCancelOccurrenceLacksArchiveTransition(): void {
    $author = $this->createUser();
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($author);
    $target = $this->orderedInstances($series)[0];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $author, ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'published',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * An affinity_group_leader holds series publish but NOT instance archive.
   */
  public function testAgLeaderMayNotCancelOccurrenceLacksArchiveTransition(): void {
    $agLeader = $this->createUser([], NULL, FALSE, ['roles' => ['affinity_group_leader']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($agLeader);
    $target = $this->orderedInstances($series)[0];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $agLeader, ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'published',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * news_pm holds `archive` on editorial_eventinstance and may cancel.
   */
  public function testNewsPmMayCancelOccurrence(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    $target = $this->orderedInstances($series)[0];

    $response = $this->doOccurrence('cancel', (int) $target->id(), $newsPm, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * Cancelling an already-archived occurrence is idempotent — no 500.
   *
   * The first cancel archives the instance. A second cancel finds archived →
   * archived is not a legal transition, so validating it must NOT throw an
   * uncaught \InvalidArgumentException (HTTP 500). The already-archived instance
   * is effectively already cancelled, so the re-cancel returns a clean success
   * no-op with the instance still archived.
   */
  public function testCancelAlreadyArchivedOccurrenceIsIdempotentNo500(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $target = $this->orderedInstances($series)[0];

    $first = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(200, $first->getStatusCode());
    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);

    $second = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(200, $second->getStatusCode());
    $this->assertTrue(json_decode($second->getContent(), TRUE)['success']);
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);
  }

  /**
   * Cancelling a non-published occurrence refuses invalid_state — no 500.
   *
   * Only a published occurrence can be cancelled: there is no legal archive
   * transition from needs_adjustment on editorial_eventinstance, so the
   * wrong-state guard must refuse invalid_state (409) rather than let
   * isTransitionValid throw an uncaught \InvalidArgumentException (HTTP 500).
   * needs_adjustment (not draft) is used because it is a DEFAULT-revision state:
   * draft is a forward revision on this workflow, so it never becomes the
   * instance's current state on its own — needs_adjustment is the genuine
   * "published once, now unpublished" case the guard must handle cleanly.
   */
  public function testCancelDraftOccurrenceRefusesInvalidStateNo500(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $target = $this->orderedInstances($series)[0];
    // Move this instance to needs_adjustment (request_adjustment: published →
    // needs_adjustment), a default-revision state, so it becomes the current
    // state while a published revision stays in history.
    $target->set('moderation_state', 'needs_adjustment')->save();

    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'needs_adjustment',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

}
