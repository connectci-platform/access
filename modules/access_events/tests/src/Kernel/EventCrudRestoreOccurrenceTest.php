<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Covers POST /api/2.3/event-occurrences/{eventinstance}/restore.
 *
 * restore_occurrence is cancel_occurrence's inverse, branching on whether the
 * instance's PARENT series is currently published:
 *  - archived + published parent → publish it. The reinstatement reaction
 *    (EventStateReactions, wired to the moderation-state-change hooks) clears
 *    individually_cancelled and enqueues the reinstatement email; the
 *    controller only drains what happened.
 *  - archived + dark (non-published) parent → publishing would violate the
 *    occurrence-publish-under-unpublished-event refusal, so this clears
 *    individually_cancelled only and saves — the
 *    instance stays archived but rejoins the series' own restore sweep, so it
 *    comes back automatically once the series itself is restored. The
 *    response signals this with returns_with_series: true.
 *  - non-archived → refuses invalid_state.
 *
 * Instance authz resolves via the instance's PARENT series' affinity-group
 * coordinator grant (userMayManageSeries('update')), and the published-parent
 * branch additionally requires the `archived_published` moderation transition
 * on editorial_eventinstance — per the live config only news_pm/administrator
 * hold it.
 */
class EventCrudRestoreOccurrenceTest extends EventKernelTestBase {

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
    // site-level fields those hooks touch, mirroring EventCrudCancelOccurrenceTest.
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
   */
  private function orderedInstances($series): array {
    $instances = $this->loadInstances($series);
    ksort($instances);
    return array_values($instances);
  }

  /**
   * archived + published parent → publish, clear the flag, notify.
   */
  public function testRestoreArchivedOccurrenceUnderPublishedParentRepublishesAndNotifies(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_reinstated_notification.enabled', TRUE)
      ->set('notifications.event_reinstated_notification.subject', 'Event reinstated')
      ->set('notifications.event_reinstated_notification.body', 'The event is back on.')
      ->save();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($newsPm);
    $target = $this->orderedInstances($series)[0];
    $this->registerUser($this->createUser(), $target);

    $this->doOccurrence('cancel', (int) $target->id(), $newsPm, ['confirmed' => TRUE]);
    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);
    $this->assertSame('1', (string) $storage->loadUnchanged($target->id())->get('individually_cancelled')->value);

    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $newsPm);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame(1, $data['notified']);

    $reloaded = $storage->loadUnchanged($target->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
    $this->assertSame('0', (string) $reloaded->get('individually_cancelled')->value);
  }

  /**
   * archived + dark (archived) parent → clear the flag only, stays archived,
   * returns_with_series: true. Publishing here would violate the
   * occurrence-publish-under-unpublished-event refusal.
   */
  public function testRestoreArchivedOccurrenceUnderDarkParentClearsFlagOnly(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($newsPm);
    $instances = $this->orderedInstances($series);
    $target = $instances[0];

    // Individually cancel the target first (published parent, so the cancel
    // succeeds normally), THEN archive the whole series — the series-wide
    // sweep leaves an already-archived, individually-flagged instance alone
    // (the cancel sweep only sweeps PUBLISHED instances), so the instance stays archived +
    // flagged while its parent goes dark.
    $this->doOccurrence('cancel', (int) $target->id(), $newsPm, ['confirmed' => TRUE]);
    $this->doCrud('delete', (int) $series->id(), $newsPm, [], ['confirmed' => TRUE]);

    $seriesStorage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $this->assertSame('archived', $seriesStorage->loadUnchanged($series->id())->get('moderation_state')->value);
    $storage = \Drupal::entityTypeManager()->getStorage('eventinstance');
    $this->assertSame('archived', $storage->loadUnchanged($target->id())->get('moderation_state')->value);
    $this->assertSame('1', (string) $storage->loadUnchanged($target->id())->get('individually_cancelled')->value);

    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $newsPm);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertTrue($data['returns_with_series']);
    $this->assertSame(0, $data['notified']);

    $reloaded = $storage->loadUnchanged($target->id());
    $this->assertSame('archived', $reloaded->get('moderation_state')->value, 'Publishing under a dark parent would violate the occurrence-publish-under-unpublished-event refusal; the instance stays archived.');
    $this->assertSame('0', (string) $reloaded->get('individually_cancelled')->value, 'The flag is cleared so a later series restore brings this instance back too.');

    // Now restore the SERIES: the instance (flag cleared, no longer excluded)
    // comes back via the ordinary sweep.
    $this->doCrud('restore', (int) $series->id(), $newsPm, []);
    $this->assertSame('published', $storage->loadUnchanged($target->id())->get('moderation_state')->value, 'Clearing the flag let the instance rejoin the series restore sweep.');
  }

  /**
   * The dark-parent restore branch (clearing the individually-cancelled flag
   * while the instance stays archived) is gated on the same `archive`
   * transition permission as the publish branch, not merely on managing the
   * series. An affinity_group_leader coordinator who lacks `archive` is
   * refused, and the flag is left set.
   */
  public function testAgLeaderMayNotClearFlagUnderDarkParentLacksArchiveTransition(): void {
    $agLeader = $this->createUser([], NULL, FALSE, ['roles' => ['affinity_group_leader']]);
    $series = $this->makePublishedCoordinatorSeriesWithTwoInstances($agLeader);
    $target = $this->orderedInstances($series)[0];

    // Force the dark-parent + archived-flagged-instance state directly via
    // syncing saves (the ag_leader lacks the transitions to reach it through
    // the API): the instance is archived and individually cancelled, its
    // parent series is archived (dark).
    $target->setSyncing(TRUE);
    $target->set('moderation_state', 'archived');
    $target->set('individually_cancelled', TRUE);
    $target->save();
    $series->setSyncing(TRUE);
    $series->set('moderation_state', 'archived')->save();

    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $agLeader);
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id());
    $this->assertSame('archived', $reloaded->get('moderation_state')->value);
    $this->assertSame('1', (string) $reloaded->get('individually_cancelled')->value, 'The flag was left set.');
  }

  /**
   * A non-archived (published) occurrence refuses invalid_state — nothing to
   * restore.
   */
  public function testRestorePublishedOccurrenceRefusesInvalidState(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($newsPm);
    $target = $this->orderedInstances($series)[0];
    $this->assertSame('published', $target->get('moderation_state')->value);

    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $newsPm);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'published',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * A DRAFT occurrence (never published, never archived) refuses
   * invalid_state — restore is only legal from archived.
   */
  public function testRestoreDraftOccurrenceRefusesInvalidState(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorSeries($newsPm);
    $target = $this->orderedInstances($series)[0];
    $this->assertSame('draft', $target->get('moderation_state')->value);

    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $newsPm);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * A non-coordinator is refused not_coordinator, writes nothing.
   */
  public function testRestoreOccurrenceRejectsNonCoordinator(): void {
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($newsPm);
    $target = $this->orderedInstances($series)[0];
    $this->doOccurrence('cancel', (int) $target->id(), $newsPm, ['confirmed' => TRUE]);

    $stranger = $this->createUser();
    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $stranger);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

  /**
   * An author who owns the series lacks the `archived_published` transition.
   *
   * Entity access (userMayManageSeries('update')) passes for the author, but
   * the transition gate refuses — the author holds neither `archive` nor
   * `archived_published`. The instance is cancelled by news_pm first so the
   * fixture reaches 'archived' under a still-published parent.
   */
  public function testAuthorCannotRestoreOccurrenceLacksArchivedPublishedTransition(): void {
    $author = $this->createUser();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makePublishedCoordinatorSeries($author);
    $target = $this->orderedInstances($series)[0];
    $this->doOccurrence('cancel', (int) $target->id(), $newsPm, ['confirmed' => TRUE]);

    $response = $this->doOccurrence('restoreOccurrence', (int) $target->id(), $author);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(
      'archived',
      \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($target->id())->get('moderation_state')->value,
    );
  }

}
