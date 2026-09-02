<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events_registration\NotificationService;

/**
 * Enqueues notices to registrants when an event is cancelled or reinstated.
 *
 * SEND-ONLY: this never deletes registrants. Cancel keeps registrations so a
 * later restore brings the event (and its registrations) back; restoring
 * enqueues a "this event is back on" notice under a distinct key. Only
 * registrants on NOT-yet-ended instances are notified — a past occurrence
 * needs no notice either way.
 */
class CancellationNotifier {

  /**
   * The module notification key we enqueue under (enabled + retemplated).
   *
   * Deliberately NOT instance_deletion_notification: contrib's own
   * EventInstanceDeleteForm / OrphanedEventInstanceForm (UI hard-delete paths)
   * fire that exact key, then unconditionally DELETE the registrant right
   * after sending. Sharing the key would mean our retemplated "your
   * registration has been kept" wording goes out on those hard-delete paths
   * too — false, since the registrant is gone a moment later. A module-owned
   * key keeps our send-only cancel notice from ever being re-armed by a
   * contrib path that deletes. Same reasoning as REINSTATE_KEY below.
   */
  public const KEY = 'event_cancelled_notification';

  /**
   * The key for a reinstated (restored) series' "back on" notice.
   *
   * Deliberately NOT series_modification_notification: contrib's own
   * recurring_events_registration_recurring_events_save_pre_instances_deletion()
   * fires that exact key from its destructive-rebuild hook (a recur-config
   * change deletes and recreates every instance), where it DELETES the
   * registrant right after sending. Sharing the key would mean our
   * retemplated "your registration has been kept / the event is back on"
   * wording goes out on that path too — false on both counts. `notifications`
   * is a sequence of dynamic-keyed mappings (config schema), and
   * NotificationService reads `notifications.<key>.*` by string key at
   * runtime, so a module-owned key works without touching contrib.
   */
  public const REINSTATE_KEY = 'event_reinstated_notification';

  /**
   * The key for a live occurrence's date/time having moved.
   *
   * This is the module's replacement for contrib's own
   * recurring_events_registration_entity_update(), which access_events
   * unimplements (see access_events_module_implements_alter()) because it
   * fires with no state gates at all — a date edit on an archived or still-
   * drafting occurrence would notify registrants of a "reschedule" on an
   * event nobody can currently see. Sharing contrib's
   * instance_modification_notification key would mean an already-configured
   * site's template/enabled state carries over unchanged, which is exactly
   * what's wanted here (unlike KEY/REINSTATE_KEY above, this is not
   * replacing a DESTRUCTIVE contrib path, just a gate-less one) — so this
   * reuses the same key rather than minting a module-owned one.
   */
  public const MODIFICATION_KEY = 'instance_modification_notification';

  /**
   * The DB queue table's queue name for registration email notifications.
   *
   * Matches the queue NotificationService::addEmailNotificationToQueue()
   * writes to, so the supersede sweep can find and remove the items enqueued
   * there before they are claimed.
   */
  private const QUEUE_NAME = 'recurring_events_registration_email_notifications_queue_worker';

  /**
   * The params key our message-params alter stamps the instance id under.
   *
   * Kept in sync with access_events_recurring_events_registration_message_
   * params_alter() in access_events.module — the alter writes it, the
   * supersede sweep reads it to scope a match to a single occurrence.
   */
  public const INSTANCE_PARAM = 'access_events_instance_id';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected NotificationService $notificationService,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected EventDomainContext $domainContext,
  ) {}

  /**
   * Queues one notification per registrant whose occurrence is not
   * verifiably past.
   *
   * The entry point for the state-reaction orchestration in
   * EventStateReactions, using RegistrantCounter::endIsNotVerifiablyPast() and
   * taking the loaded entity directly.
   *
   * Returns 0 without looping over registrants when either notification gate
   * (the key's enabled flag or the site master switch) is off — there is no
   * force-queue mechanism to bypass, since addEmailNotificationToQueue()
   * itself would silently no-op on the same two config keys either way.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $instance
   *   The event instance whose registrants to notify.
   * @param string $key
   *   The notification key to enqueue under.
   *
   * @return int
   *   Number of notifications enqueued.
   */
  public function enqueueGated(EventInstance $instance, string $key): int {
    if (!$this->gateOpen($key)) {
      return 0;
    }

    $instanceId = (int) $instance->id();
    $ids = $this->entityTypeManager->getStorage('registrant')->getQuery()
      ->condition('eventinstance_id', $instanceId)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return 0;
    }

    $now = $this->time->getRequestTime();
    $endValue = $instance->get('date')->end_value;
    if (!RegistrantCounter::endIsNotVerifiablyPast($endValue, $now)) {
      return 0;
    }

    // The whole loop runs in the occurrence's own domain context: contrib
    // renders subject and body (and with them every absolute link) at ENQUEUE
    // time and bakes them into the queue item, so the domain has to be right
    // here, not when cron drains the queue.
    // @see \Drupal\access_events\EventDomainContext
    return $this->domainContext->forEntity($instance, function () use ($ids, $instanceId, $key): int {
      $count = 0;
      foreach ($this->entityTypeManager->getStorage('registrant')->loadMultiple($ids) as $registrant) {
        // A reinstatement supersedes any earlier, still-unclaimed reinstatement
        // to the same registrant for this same occurrence: that earlier notice
        // was rendered with an old subject/body/date at enqueue time and would
        // otherwise reach the registrant alongside this fresher one. Remove it
        // before enqueuing. A cancellation is deliberately NOT superseded
        // here — a cancel followed by a reinstate is a legitimate pair.
        if ($key === self::REINSTATE_KEY) {
          $this->removeSupersededQueueItems((string) $registrant->email->value, $instanceId, [self::REINSTATE_KEY]);
        }
        $this->notificationService->addEmailNotificationToQueue($key, $registrant);
        $count++;
      }
      return $count;
    });
  }

  /**
   * Queues one reschedule notice per registrant, keyed off an old/new
   * end-date boundary rather than the instance's single current end value.
   *
   * enqueueGated() above answers "is THIS instance's current end not
   * verifiably past" — right for a cancel/reinstate notice, where there is
   * only one end value in play. A date EDIT is different: by the time this
   * runs the row has already been overwritten with the NEW date, so asking
   * the DB "was this registrant's instance not-past under the OLD schedule"
   * is no longer answerable from storage. The caller passes both boundaries
   * (the pre-save $oldEnd and the post-save $newEnd) and a registrant is
   * notified if EITHER says not-verifiably-past — a future event moved into
   * the past still owes its registrants a notice that it moved, even though
   * the saved row alone would look like nothing worth notifying about.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $instance
   *   The event instance whose registrants to notify.
   * @param string|null $oldEnd
   *   The end_value the instance's date field held before this save.
   * @param string|null $newEnd
   *   The end_value the instance's date field holds after this save.
   *
   * @return int
   *   Number of notifications enqueued.
   */
  public function enqueueModificationGated(EventInstance $instance, ?string $oldEnd, ?string $newEnd): int {
    if (!$this->gateOpen(self::MODIFICATION_KEY)) {
      return 0;
    }

    $instanceId = (int) $instance->id();
    $ids = $this->entityTypeManager->getStorage('registrant')->getQuery()
      ->condition('eventinstance_id', $instanceId)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return 0;
    }

    $now = $this->time->getRequestTime();
    if (!RegistrantCounter::endIsNotVerifiablyPast($oldEnd, $now) && !RegistrantCounter::endIsNotVerifiablyPast($newEnd, $now)) {
      return 0;
    }

    // Rendered at enqueue time in the occurrence's own domain context, for the
    // same reason as enqueueGated() above.
    return $this->domainContext->forEntity($instance, function () use ($ids, $instanceId): int {
      $count = 0;
      foreach ($this->entityTypeManager->getStorage('registrant')->loadMultiple($ids) as $registrant) {
        // A modification notice supersedes any earlier, still-unclaimed
        // reinstatement OR modification notice to the same registrant for this
        // same occurrence: those earlier notices were rendered with a now-stale
        // date at enqueue time (e.g. a restore queued "back on, <old date>",
        // then this edit moved the date) and would otherwise reach the
        // registrant as a contradictory pair. Remove them before enqueuing. A
        // cancellation is deliberately NOT superseded — a cancel then a later
        // reschedule is a legitimate sequence.
        $this->removeSupersededQueueItems(
          (string) $registrant->email->value,
          $instanceId,
          [self::REINSTATE_KEY, self::MODIFICATION_KEY]
        );
        $this->notificationService->addEmailNotificationToQueue(self::MODIFICATION_KEY, $registrant);
        $count++;
      }
      return $count;
    });
  }

  /**
   * Deletes unclaimed queued notices this path would otherwise duplicate.
   *
   * Notices are fully rendered (subject/body/recipient/date) at enqueue time
   * and sit in the DB queue until cron drains them. When this path is about to
   * enqueue a fresher notice that makes an earlier queued one stale (a second
   * reinstatement, or a modification after a reinstatement), the earlier one
   * must be removed so a registrant never receives a contradictory pair.
   *
   * The match is scoped narrowly on purpose: recipient email AND one of the
   * superseding notification keys AND — critically — the SAME occurrence, read
   * from the item's own params[self::INSTANCE_PARAM], which
   * access_events_recurring_events_registration_message_params_alter() stamps
   * on every notice this module enqueues. Without the instance-id scope a
   * supersede could delete a queued notice for a DIFFERENT event to the same
   * person; the queue table carries no queryable instance column, so the id is
   * read from the unserialized item data instead.
   *
   * @param string $email
   *   The recipient email to match.
   * @param int $instanceId
   *   The occurrence id the new notice belongs to.
   * @param string[] $supersedingKeys
   *   The notification keys whose earlier items this notice supersedes.
   */
  private function removeSupersededQueueItems(string $email, int $instanceId, array $supersedingKeys): void {
    if ($email === '' || !$supersedingKeys) {
      return;
    }

    // The core queue table is created lazily on the first enqueue (see
    // DatabaseQueue::ensureTableExists()), so it may not exist yet the first
    // time this path runs. When it does not, there is nothing queued to
    // supersede — treat a missing table as an empty result rather than let the
    // query fatal.
    // expire = 0 means unclaimed (an item a worker has leased carries a
    // non-zero expire); only unclaimed items are safe to drop here.
    try {
      $rows = $this->database->select('queue', 'q')
        ->fields('q', ['item_id', 'data'])
        ->condition('name', self::QUEUE_NAME)
        ->condition('expire', 0)
        ->execute();
    }
    catch (DatabaseExceptionWrapper $e) {
      return;
    }

    $toDelete = [];
    foreach ($rows as $row) {
      $item = @unserialize((string) $row->data);
      if (!$item instanceof \stdClass) {
        continue;
      }
      if (!isset($item->to, $item->key) || $item->to !== $email) {
        continue;
      }
      if (!in_array($item->key, $supersedingKeys, TRUE)) {
        continue;
      }
      $itemInstanceId = $item->params[self::INSTANCE_PARAM] ?? NULL;
      if ($itemInstanceId === NULL || (int) $itemInstanceId !== $instanceId) {
        continue;
      }
      $toDelete[] = $row->item_id;
    }

    if ($toDelete) {
      $this->database->delete('queue')
        ->condition('item_id', $toDelete, 'IN')
        ->execute();
    }
  }

  /**
   * Whether both notification gates (site master switch + the key) are on.
   */
  private function gateOpen(string $key): bool {
    $config = $this->configFactory->get('recurring_events_registration.registrant.config');
    return (bool) $config->get('email_notifications') && (bool) $config->get('notifications.' . $key . '.enabled');
  }

}
