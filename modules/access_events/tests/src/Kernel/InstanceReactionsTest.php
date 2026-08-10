<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\CancellationNotifier;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests the instance-level state reactions: the cancellation-email reaction
 * and the reinstatement reaction, plus the occurrence-publish-under-unpublished-event
 * refusal (dark-parent).
 *
 * @coversDefaultClass \Drupal\access_events\EventStateReactions
 * @group access_events
 */
class InstanceReactionsTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableEventNotifications();
  }

  /**
   * The cancellation-email reaction: published -> archived plain save enqueues cancellation + flags.
   */
  public function testArchiveFromPlainSaveEnqueuesCancellationAndFlags(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r1'), $instance);
    $instance->set('moderation_state', 'archived');
    $instance->save();
    $this->assertQueueCount('event_cancelled_notification', 1);
    $this->assertSame('1', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value);
  }

  /**
   * The reinstatement reaction: archived -> published under a published parent enqueues reinstatement
   * and clears the individually_cancelled flag (published-coherence rule).
   */
  public function testRepublishEnqueuesReinstatementAndClearsFlag(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r2'), $instance);

    // Archive it first (individually_cancelled gets set TRUE by the cancellation-email reaction).
    $instance->set('moderation_state', 'archived');
    $instance->save();
    $this->assertSame('1', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value);

    // Parent series stays published throughout, so the occurrence-publish-under-unpublished-event refusal does not trigger, and this is permitted.
    $instance = $this->reloadInstance($instance);
    $instance->set('moderation_state', 'published');
    $instance->save();

    $this->assertQueueCount('event_reinstated_notification', 1);
    $this->assertSame('0', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value);
  }

  /**
   * The occurrence-publish-under-unpublished-event refusal: publishing an occurrence while its parent series is archived (dark)
   * is refused with a RuntimeException on a bare (non-validated) save.
   */
  public function testPublishUnderDarkParentThrowsOnBareSave(): void {
    $instance = $this->createRegistrableInstance();
    $seriesId = (int) $instance->get('eventseries_id')->target_id;

    // Archive the instance itself via a syncing save (bypasses the cancellation-email reaction and the occurrence-publish-under-unpublished-event refusal so the
    // fixture setup itself doesn't trip the reactions under test). A
    // published->published re-save would be from===to and never reach the
    // gate, so the instance must first land in a genuinely non-published
    // state before the publish attempt below can exercise the gate.
    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    // Archive the parent series too, via syncing, so the instance's later
    // publish attempt is refused by the occurrence-publish-under-unpublished-event refusal (parent not published).
    $series = EventSeries::load($seriesId);
    $series->setSyncing(TRUE);
    $series->set('moderation_state', 'archived');
    $series->save();

    // Core's SqlContentEntityStorage wraps a presave-hook exception in an
    // EntityStorageException, with the original RuntimeException as its
    // previous exception (see EventSeriesPresaveBackstopTest for the same
    // pattern on the series side).
    $instance = $this->reloadInstance($instance);
    $instance->set('moderation_state', 'published');
    $threw = FALSE;
    try {
      $instance->save();
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $threw = TRUE;
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertStringContainsString('cannot be published while the event itself is not published', $e->getPrevious()->getMessage());
    }
    $this->assertTrue($threw, 'Bare save() must throw when the parent series is not published.');
  }

  /**
   * The occurrence-publish-under-unpublished-event refusal: the same dark-parent setup surfaces as a validation violation too.
   */
  public function testPublishUnderDarkParentViolatesConstraintOnValidate(): void {
    $instance = $this->createRegistrableInstance();
    $seriesId = (int) $instance->get('eventseries_id')->target_id;

    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $series = EventSeries::load($seriesId);
    $series->setSyncing(TRUE);
    $series->set('moderation_state', 'archived');
    $series->save();

    $instance = $this->reloadInstance($instance);
    $instance->set('moderation_state', 'published');
    $violations = $instance->validate();

    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool =>
      str_contains($m, 'This occurrence cannot be published while the event itself is not published.')
    ), 'Expected the dark-parent publish-guard message among violations: ' . implode(' | ', $messages));
  }

  /**
   * The occurrence-publish-under-unpublished-event refusal: a series that was NEVER published (still default draft) also refuses
   * an instance publish attempt — proves the gate is binary isPublished(),
   * not a string compare against 'archived'.
   */
  public function testPublishUnderDraftParentAlsoRefused(): void {
    $series = EventSeries::create([
      'title' => 'Never Published Event',
      'body' => 'Draft only.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    // Deliberately do NOT call publishModerated(): the series stays in its
    // default (draft) moderation state, unpublished, and was never published.
    $series->save();

    $instance = \Drupal\recurring_events\Entity\EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);
    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $instance = $this->reloadInstance($instance);
    $instance->set('moderation_state', 'published');
    $threw = FALSE;
    try {
      $instance->save();
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $threw = TRUE;
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertStringContainsString('cannot be published while the event itself is not published', $e->getPrevious()->getMessage());
    }
    $this->assertTrue($threw, 'Bare save() must throw when the parent series was never published.');
  }

  /**
   * published -> published re-save (e.g. a title edit) is a non-event.
   */
  public function testPublishedToPublishedResaveIsANonEvent(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('title', 'A retitled but still-published occurrence');
    $instance->save();
    $this->assertQueueCount('event_cancelled_notification', 0);
    $this->assertQueueCount('event_reinstated_notification', 0);
  }

  /**
   * archived -> archived re-save (e.g. a date edit) is a non-event; the
   * archived_archived self-transition must not re-fire the cancellation-email reaction.
   */
  public function testArchivedToArchivedSaveIsANonEvent(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('moderation_state', 'archived');
    $instance->save();

    // Drain whatever the initial archive produced before the assertion below.
    $queue = \Drupal::queue('recurring_events_registration_email_notifications_queue_worker');
    while ($queue->claimItem()) {
      // Drain.
    }

    $instance = $this->reloadInstance($instance);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $this->assertQueueCount('event_cancelled_notification', 0);
    $this->assertSame('1', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value,
      'the flag set by the first archive must be untouched by the non-event resave');
  }

  /**
   * A published instance moved to a pending (needs_adjustment) draft state is
   * not the cancellation-email reaction's transition (to !== 'published' is necessary but so is
   * from === 'published' -- covered; here the resulting state is not the
   * archived flow, so no cancellation notice/flag should fire).
   */
  public function testPendingDraftSaveOfPublishedInstanceIsANonEvent(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('moderation_state', 'needs_adjustment');
    $instance->save();

    $this->assertQueueCount('event_cancelled_notification', 0);
    $this->assertQueueCount('event_reinstated_notification', 0);
    $this->assertSame('0', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value);
  }

  /**
   * A syncing save (e.g. a revert/rebuild) fires no reaction at all.
   */
  public function testSyncingSaveFiresNothing(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r3'), $instance);

    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $this->assertQueueCount('event_cancelled_notification', 0);
    $this->assertSame('0', (string) $this->reloadInstance($instance)->get('individually_cancelled')->value);
  }

  /**
   * When the notification key is disabled, drain() shows notified=0 plus the
   * notifications_disabled marker (recipients were present, gate blocked it).
   */
  public function testNotificationsDisabledRecordsMarker(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('notifications.event_cancelled_notification.enabled', FALSE)
      ->save();

    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r4'), $instance);
    $seriesId = (int) $instance->get('eventseries_id')->target_id;

    $instance->set('moderation_state', 'archived');
    $instance->save();

    $this->assertQueueCount('event_cancelled_notification', 0);

    $collector = \Drupal::service('access_events.state_change_collector');
    $instanceOutcomes = $collector->drain('eventinstance', (int) $instance->id());
    $seriesOutcomes = $collector->drain('eventseries', $seriesId);

    $this->assertSame(0, $instanceOutcomes['notified'] ?? NULL);
    $this->assertTrue($instanceOutcomes['notifications_disabled'] ?? FALSE);
    $this->assertSame(0, $seriesOutcomes['notified'] ?? NULL);
    $this->assertTrue($seriesOutcomes['notifications_disabled'] ?? FALSE);
  }

}
