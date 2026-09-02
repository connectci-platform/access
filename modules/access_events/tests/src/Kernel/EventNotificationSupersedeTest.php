<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\CancellationNotifier;

/**
 * Tests that CancellationNotifier collapses stale, still-unclaimed queued
 * notices before enqueuing a fresher one for the same registrant + occurrence.
 *
 * Notices are fully rendered (subject/body/recipient/date) at enqueue time and
 * sit in the DB queue until cron drains them. A restore queues a "back on,
 * <old date>" notice; a later edit before cron would queue a second, correct
 * "rescheduled to <new date>" notice — the registrant would otherwise receive
 * a contradictory pair. The supersede sweep removes the stale one first,
 * scoped to the same recipient AND the same occurrence (via the instance id
 * the message-params alter stamps on every notice this module enqueues) so it
 * never touches a notice for a different event to the same person. A
 * cancellation is deliberately never collapsed — a cancel then a reinstate is
 * a legitimate pair.
 *
 * @group access_events
 */
class EventNotificationSupersedeTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableEventNotifications();
  }

  /**
   * The notifier under test.
   */
  private function notifier(): CancellationNotifier {
    return \Drupal::service('access_events.cancellation_notifier');
  }

  /**
   * Counts unclaimed queued items by notification key and (optionally) the
   * stamped occurrence id, reading the DB queue table the same way the
   * supersede sweep does.
   */
  private function countQueued(string $key, ?int $instanceId = NULL): int {
    $rows = \Drupal::database()->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', 'recurring_events_registration_email_notifications_queue_worker')
      ->condition('expire', 0)
      ->execute();

    $count = 0;
    foreach ($rows as $row) {
      $item = unserialize((string) $row->data);
      if (!$item instanceof \stdClass || ($item->key ?? NULL) !== $key) {
        continue;
      }
      if ($instanceId !== NULL) {
        $stamped = $item->params[CancellationNotifier::INSTANCE_PARAM] ?? NULL;
        if ((int) $stamped !== $instanceId) {
          continue;
        }
      }
      $count++;
    }
    return $count;
  }

  /**
   * A reinstatement followed by a modification for the same registrant and
   * occurrence leaves exactly ONE queued item — the modification, the newest —
   * because the modification supersedes the earlier reinstatement notice.
   */
  public function testReinstateThenModificationLeavesOnlyModification(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'sup1'), $instance);
    $instanceId = (int) $instance->id();

    $this->notifier()->enqueueGated($instance, CancellationNotifier::REINSTATE_KEY);
    $this->assertSame(1, $this->countQueued(CancellationNotifier::REINSTATE_KEY, $instanceId));

    $this->notifier()->enqueueModificationGated(
      $instance,
      '2999-01-01T12:00:00',
      '2999-02-01T12:00:00',
    );

    $this->assertSame(0, $this->countQueued(CancellationNotifier::REINSTATE_KEY, $instanceId), 'The stale reinstatement was superseded.');
    $this->assertSame(1, $this->countQueued(CancellationNotifier::MODIFICATION_KEY, $instanceId), 'The modification notice remains.');
  }

  /**
   * A second reinstatement supersedes an earlier one for the same registrant
   * and occurrence — only the newest reinstatement remains queued.
   */
  public function testReinstateSupersedesEarlierReinstate(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'sup2'), $instance);
    $instanceId = (int) $instance->id();

    $this->notifier()->enqueueGated($instance, CancellationNotifier::REINSTATE_KEY);
    $this->notifier()->enqueueGated($instance, CancellationNotifier::REINSTATE_KEY);

    $this->assertSame(1, $this->countQueued(CancellationNotifier::REINSTATE_KEY, $instanceId));
  }

  /**
   * A cancellation notice for the same registrant is NOT collapsed by a later
   * reinstatement: a cancel then a reinstate is a legitimate pair and both
   * must reach the registrant.
   */
  public function testCancellationIsNotSuperseded(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'sup3'), $instance);
    $instanceId = (int) $instance->id();

    $this->notifier()->enqueueGated($instance, CancellationNotifier::KEY);
    $this->notifier()->enqueueGated($instance, CancellationNotifier::REINSTATE_KEY);

    $this->assertSame(1, $this->countQueued(CancellationNotifier::KEY, $instanceId), 'The cancellation notice survives.');
    $this->assertSame(1, $this->countQueued(CancellationNotifier::REINSTATE_KEY, $instanceId), 'The reinstatement notice is also queued.');
  }

  /**
   * A queued notice to the same email for a DIFFERENT occurrence is NOT
   * collapsed: the supersede match is scoped to a single occurrence via the
   * stamped instance id, so an unrelated event's notice to the same person is
   * left untouched.
   */
  public function testDifferentInstanceSameEmailIsNotSuperseded(): void {
    $user = $this->createUser([], 'sup4');

    $instanceA = $this->createRegistrableInstance();
    $instanceB = $this->createRegistrableInstance();
    $this->registerUser($user, $instanceA);
    $this->registerUser($user, $instanceB);
    $idA = (int) $instanceA->id();
    $idB = (int) $instanceB->id();

    // Queue a reinstatement for occurrence B (to this same email).
    $this->notifier()->enqueueGated($instanceB, CancellationNotifier::REINSTATE_KEY);
    $this->assertSame(1, $this->countQueued(CancellationNotifier::REINSTATE_KEY, $idB));

    // Now a modification for occurrence A — must not touch B's queued notice.
    $this->notifier()->enqueueModificationGated(
      $instanceA,
      '2999-01-01T12:00:00',
      '2999-02-01T12:00:00',
    );

    $this->assertSame(1, $this->countQueued(CancellationNotifier::REINSTATE_KEY, $idB), "Occurrence B's notice is untouched.");
    $this->assertSame(1, $this->countQueued(CancellationNotifier::MODIFICATION_KEY, $idA), "Occurrence A's modification is queued.");
  }

}
