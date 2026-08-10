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
 * cancel_occurrence is tri-state (plus a catch-all), keyed on the instance's
 * CURRENT moderation_state:
 *  - published → archive it. The save alone does the orchestration: the
 *    cancellation-email reaction (EventStateReactions, wired to the moderation-state-change hooks) sets
 *    individually_cancelled and enqueues the cancellation email — this
 *    controller does not separately notify, so a cancel produces exactly ONE
 *    notification, not two.
 *  - archived → idempotent: sets individually_cancelled TRUE (if not already)
 *    and reports the instance is now excluded from a future series restore.
 *  - draft, unregistered → refuses invalid_state ("delete it instead").
 *  - draft, registered → refuses invalid_state ("needs manual review").
 *  - any other state (needs_adjustment, ready_for_review, …) → refuses
 *    invalid_state (catch-all).
 *
 * Instance authz resolves via the instance's PARENT series' affinity-group
 * coordinator grant (userMayManageSeries('delete')), and the published branch
 * additionally requires the `archive` moderation transition on the
 * editorial_eventinstance workflow — per the live config only news_pm/
 * administrator hold it, so an author or affinity_group_leader who may
 * "manage" the instance is still refused the archive.
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
    $this->assertSame('1', (string) $storage->loadUnchanged($target->id())->get('individually_cancelled')->value);
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
   * registration, archives the instance, and notifies EXACTLY ONCE.
   *
   * Before this task, cancelOccurrence called CancellationNotifier::
   * notifyInstanceCancelled() directly AND the cancellation-email reaction fired off the
   * same save — a double-enqueue bug. That direct call is gone; the envelope
   * now comes solely from draining the collector the cancellation-email reaction populated.
   */
  public function testCancelOccurrenceWithRegistrantsProceedsAndNotifiesOnce(): void {
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

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    // No force. confirmed only.
    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(1, $data['notified'], 'Exactly one notification — the double-enqueue bug is gone.');

    $after = $queue->numberOfItems();
    $this->assertSame($before + 1, $after, 'Exactly one queue item, not two.');

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
   * The archived branch (marking an already-archived occurrence individually
   * cancelled) is gated on the same `archive` transition permission as the
   * published branch, not merely on managing the series. An affinity_group_
   * leader coordinator who can manage the series but lacks `archive` is
   * refused, and the flag is not written.
   */
  public function testAgLeaderMayNotFlagArchivedOccurrenceLacksArchiveTransition(): void {
    $agLeader = $this->createUser([], NULL, FALSE, ['roles' => ['affinity_group_leader']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($agLeader);
    $target = $this->orderedInstances($series)[0];
    // Force the instance to archived directly (a syncing save lands the state
    // as the default revision, as elsewhere in this suite).
    $target->setSyncing(TRUE);
    $target->set('moderation_state', 'archived')->save();

    $response = $this->doOccurrence('cancel', (int) $target->id(), $agLeader, ['confirmed' => TRUE]);
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id());
    $this->assertSame('archived', $reloaded->get('moderation_state')->value);
    $this->assertSame('0', (string) $reloaded->get('individually_cancelled')->value, 'The flag was not written.');
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
   * The archived branch does not route through a moderation transition at
   * all (archived→archived is not a defined transition); it directly (re)sets
   * individually_cancelled and saves. The response notes the instance's
   * exclusion from a future series restore.
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
    $secondData = json_decode($second->getContent(), TRUE);
    $this->assertTrue($secondData['success']);
    $this->assertArrayHasKey('note', $secondData);
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);
    $this->assertSame('1', (string) $storage->loadUnchanged($target->id())->get('individually_cancelled')->value);
  }

  /**
   * Cancelling an unpublished DRAFT occurrence with no registrations refuses
   * invalid_state, pointing the caller at delete instead.
   *
   * needs_adjustment can no longer be manufactured as a DEFAULT revision by a
   * bare save on this workflow (the flip makes it a pending revision), so the
   * draft branch is exercised via a genuinely draft-state instance instead —
   * one that never went through a publish/archive cycle.
   */
  public function testCancelUnregisteredDraftOccurrenceRefusesInvalidState(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $target = $this->orderedInstances($series)[0];
    $this->assertSame('draft', $target->get('moderation_state')->value);

    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_state', $data['error']);
    $this->assertSame('This occurrence is an unpublished draft; delete it instead.', $data['message']);
    $this->assertSame(
      'draft',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * Cancelling an unpublished DRAFT occurrence WITH registrations refuses
   * invalid_state with the manual-review message, distinct from the
   * unregistered-draft message.
   *
   * registerUserOnDraftInstance() models a real "was published, someone
   * registered, then the occurrence got pulled back to draft" sequence — the
   * registrant-presave publish gate only refuses a NEW registrant save
   * against a non-published instance, it does not reach back and invalidate
   * registrants who already exist.
   */
  public function testCancelRegisteredDraftOccurrenceRefusesInvalidStateNeedsReview(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($coordinator);
    $target = $this->orderedInstances($series)[0];
    $this->assertSame('draft', $target->get('moderation_state')->value);
    $this->registerUserOnDraftInstance($this->createUser(), $target);
    $this->assertSame('draft', $this->reloadInstance($target)->get('moderation_state')->value);

    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_state', $data['error']);
    $this->assertSame('This occurrence is an unpublished draft with registrations; it needs manual review.', $data['message']);
    $this->assertSame(
      'draft',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * Cancelling an occurrence in any OTHER state (needs_adjustment) refuses
   * invalid_state via the catch-all branch — no 500.
   *
   * needs_adjustment is default_revision: FALSE on editorial_eventinstance in
   * this kernel env — an ORDINARY
   * bare save moving published → needs_adjustment creates a non-default
   * PENDING revision and leaves the current default revision at published
   * (see content_moderation's EntityOperations::entityPresave() /
   * ModerationHandler::onPresave()), so it can never become the CURRENT
   * state that way. setSyncing(TRUE) bypasses content_moderation's own
   * default-revision computation entirely (onPresave() no-ops when syncing),
   * letting this plain save land the needs_adjustment state as the default
   * revision directly — mirrors InstanceReactionsTest's same technique for
   * forcing a state.
   */
  public function testCancelNeedsAdjustmentOccurrenceRefusesInvalidStateCatchAll(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $target = $this->orderedInstances($series)[0];
    $target->setSyncing(TRUE);
    $target->set('moderation_state', 'needs_adjustment')->save();
    $target = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id());
    $this->assertSame('needs_adjustment', $target->get('moderation_state')->value, 'Fixture setup: the instance must genuinely be at needs_adjustment as its default revision.');

    $response = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'needs_adjustment',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * A series-wide cancel (delete_event's archive sweep) writes
   * individually_cancelled = FALSE on the swept instances (StateChangeCollector
   * ::isSweeping() suppresses the cancellation-email reaction's flag write during a sweep), so a later
   * series restore brings them all back. Cancelling ONE of those instances
   * individually via cancel_occurrence afterward writes the flag TRUE on it
   * specifically — and a subsequent series restore skips exactly that one.
   */
  public function testCancelWhileSeriesCancelledWritesFlagAndRestoreSkipsIt(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($coordinator);
    $instances = $this->orderedInstances($series);
    $target = $instances[0];
    $sibling = $instances[1];

    // Series-wide cancel: sweepCancel() archives both instances WITHOUT
    // setting individually_cancelled (isSweeping() suppresses the cancellation-email reaction's flag
    // write for the duration of the sweep).
    $this->doCrud('delete', (int) $series->id(), $coordinator, [], ['confirmed' => TRUE]);
    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);
    $this->assertSame('archived', $storage->loadUnchanged($sibling->id())->get('moderation_state')->value);
    $this->assertSame('0', (string) $storage->loadUnchanged($target->id())->get('individually_cancelled')->value);
    $this->assertSame('0', (string) $storage->loadUnchanged($sibling->id())->get('individually_cancelled')->value);

    // Now individually cancel the target while it is ALREADY archived (the
    // archived branch of cancelOccurrence): the flag goes TRUE specifically
    // on this instance.
    $cancelResponse = $this->doOccurrence('cancel', (int) $target->id(), $coordinator, ['confirmed' => TRUE]);
    $this->assertSame(200, $cancelResponse->getStatusCode());
    $this->assertSame('1', (string) $storage->loadUnchanged($target->id())->get('individually_cancelled')->value);
    $this->assertSame('0', (string) $storage->loadUnchanged($sibling->id())->get('individually_cancelled')->value);

    // Series restore: sweepRestore() republishes every archived, UNFLAGGED
    // instance — the sibling comes back, the individually-cancelled target
    // does not.
    $this->doCrud('restore', (int) $series->id(), $coordinator, []);
    $this->assertSame('published', $storage->loadUnchanged($sibling->id())->get('moderation_state')->value);
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value, 'The individually-cancelled instance must stay archived through a series restore.');
  }

}
