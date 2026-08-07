<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected NotificationService $notificationService,
    protected TimeInterface $time,
  ) {}

  /**
   * Notifies registrants of one cancelled (archived) instance, if it's future.
   *
   * @return int
   *   Number of notifications enqueued.
   */
  public function notifyInstanceCancelled(int $instanceId): int {
    $instance = $this->entityTypeManager->getStorage('eventinstance')->load($instanceId);
    if (!$instance || !$this->instanceIsFuture($instance)) {
      return 0;
    }
    return $this->enqueueForInstance($instanceId, self::KEY);
  }

  /**
   * Notifies registrants across a cancelled series' future instances.
   *
   * @return int
   *   Number of notifications enqueued.
   */
  public function notifySeriesCancelled(int $seriesId): int {
    return $this->notifySeriesFutureInstances($seriesId, self::KEY);
  }

  /**
   * Notifies registrants of exactly the given (future) instances under $key.
   *
   * Used by delete()/restore() so the notify set matches the archive/restore
   * set exactly — not "every future instance of the series". Archiving skips
   * instances that are not currently published (e.g. one already archived
   * individually via cancelOccurrence, or one left in draft); notifying by
   * series scope instead of by this returned set would double-notify the
   * former and falsely notify the latter.
   *
   * @param int[] $instanceIds
   *   Event instance ids to notify.
   * @param string $key
   *   The notification key to enqueue under.
   *
   * @return int
   *   Number of notifications enqueued.
   */
  public function notifyInstances(array $instanceIds, string $key): int {
    $count = 0;
    foreach ($instanceIds as $instanceId) {
      $instance = $this->entityTypeManager->getStorage('eventinstance')->load($instanceId);
      if ($instance && $this->instanceIsFuture($instance)) {
        $count += $this->enqueueForInstance((int) $instanceId, $key);
      }
    }
    return $count;
  }

  /**
   * Enqueues $key for every registrant on the series' future instances.
   *
   * @return int
   *   Number of notifications enqueued.
   */
  protected function notifySeriesFutureInstances(int $seriesId, string $key): int {
    $series = $this->entityTypeManager->getStorage('eventseries')->load($seriesId);
    if (!$series) {
      return 0;
    }
    $count = 0;
    foreach ($series->event_instances->referencedEntities() as $instance) {
      if ($this->instanceIsFuture($instance)) {
        $count += $this->enqueueForInstance((int) $instance->id(), $key);
      }
    }
    return $count;
  }

  /**
   * Enqueues a notice under $key for every registrant on an instance.
   *
   * Keyed so both the cancel and reinstate paths share this lookup+enqueue
   * without duplicating the registrant query.
   */
  protected function enqueueForInstance(int $instanceId, string $key): int {
    $ids = $this->entityTypeManager->getStorage('registrant')->getQuery()
      ->condition('eventinstance_id', $instanceId)
      ->accessCheck(FALSE)
      ->execute();
    $count = 0;
    foreach ($this->entityTypeManager->getStorage('registrant')->loadMultiple($ids) as $registrant) {
      // SEND-ONLY. addEmailNotificationToQueue enqueues a pre-rendered
      // message; it gates on email_notifications + notifications.<key>.
      // enabled and calls setKey()/setEntity() itself. It does NOT delete the
      // registrant (unlike the module's own delete hooks, which enqueue/send
      // and then unconditionally delete the registrant row).
      $this->notificationService->addEmailNotificationToQueue($key, $registrant);
      $count++;
    }
    return $count;
  }

  /**
   * TRUE if the instance's date has not yet ended (end_value > now).
   *
   * INVARIANT: this boundary must stay identical to RegistrantCounter's
   * countFutureFor* queries — both use getRequestTime() and a strict >.
   * Changing either alone (>=, wall-clock time()) silently splits the
   * notified population from the reschedule-blocked population.
   */
  protected function instanceIsFuture($instance): bool {
    $end = $instance->get('date')->end_value;
    if (!$end) {
      return FALSE;
    }
    // date.end_value stores as 'Y-m-d\TH:i:s' UTC (no offset suffix).
    return strtotime($end . ' UTC') > $this->time->getRequestTime();
  }

}
