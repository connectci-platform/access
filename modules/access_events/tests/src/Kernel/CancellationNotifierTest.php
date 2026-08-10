<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;

/**
 * Tests the send-only cancellation notifier.
 *
 * Covers enqueueGated() — the sole entry point, used by both the state-
 * reaction orchestration in EventStateReactions (the cancellation-email reaction and the reinstatement reaction, series-cancel and
 * series-restore sweeps) and the occurrence-level cancel/restore endpoints.
 *
 * @coversDefaultClass \Drupal\access_events\CancellationNotifier
 * @group access_events
 */
class CancellationNotifierTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The base module list plus the modules access_events needs to compile.
   * The notifier service (access_events.cancellation_notifier) only lands in
   * the container when access_events is enabled; key + content_moderation +
   * access_misc are hard service-compile dependencies of access_events.
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
    // field_other_authors on every series access check) now that
    // access_events is enabled. Seed the empty site-level fields those hooks
    // touch, mirroring EventCrudCancelOccurrenceTest.
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
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // The base setUp() deliberately skips installing the module's own
    // registrant config (it defaults email_notifications on and would fire
    // the mail pipeline on every registrant save in unrelated tests). This
    // suite is specifically about that config-gated send path, but
    // installConfig(['recurring_events_registration']) is not usable here:
    // its config/install ships recurring_events_registration.registrant_
    // type.default, colliding with the "default" registrant_type the base
    // setUp() already created programmatically (throwing inside a DB
    // transaction and corrupting the transaction stack for the rest of the
    // test). Each test below sets only the config keys it needs directly via
    // getEditable() instead, exactly like NotificationService reads them.
  }

  /**
   * enqueueGated() with the notification key disabled enqueues nothing and
   * the queue does not grow.
   *
   * The site master switch (email_notifications) is ON but the specific
   * key's own enabled flag is OFF — gateOpen() requires BOTH.
   */
  public function testEnqueueGatedKeyDisabledEnqueuesNothingWithoutQueueGrowth(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', FALSE)
      ->save();

    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $enqueued = $notifier->enqueueGated($instance, \Drupal\access_events\CancellationNotifier::KEY);

    $this->assertSame(0, $enqueued);
    $this->assertSame($before, $queue->numberOfItems());
  }

  /**
   * enqueueGated() with the site master switch off enqueues nothing.
   *
   * The key's own flag is ON but the master email_notifications switch is
   * OFF — gateOpen() requires BOTH, so this must still refuse to loop over
   * registrants.
   */
  public function testEnqueueGatedMasterSwitchOffEnqueuesNothingWithoutQueueGrowth(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', FALSE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->save();

    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $enqueued = $notifier->enqueueGated($instance, \Drupal\access_events\CancellationNotifier::KEY);

    $this->assertSame(0, $enqueued);
    $this->assertSame($before, $queue->numberOfItems());
  }

  /**
   * enqueueGated() with both gates open enqueues one notice per registrant
   * whose occurrence is NOT VERIFIABLY past — the permissive
   * RegistrantCounter::endIsNotVerifiablyPast() boundary.
   *
   * A NULL end date is "not verifiably past" and must count. The entity API
   * refuses a NULL-end date.value save (validation constraints on the
   * daterange field), so the NULL end is seeded via a direct DB update on
   * the instance's field-data table, mirroring how a legacy/malformed row
   * could exist in production, followed by resetCache() so the reload picks
   * it up.
   */
  public function testEnqueueGatedCountsNotVerifiablyPastIncludingNullEndRow(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();

    // A normal future instance — counts.
    $future = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $future);

    // A second instance whose end date is forced to NULL directly on the
    // storage table — not verifiably past, so it must ALSO count, unlike
    // the stricter instanceIsFuture() boundary (which treats an empty end as
    // NOT future).
    $nullEnd = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $nullEnd);
    $this->setInstanceEndDateNull($nullEnd);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $futureEnqueued = $notifier->enqueueGated($future, \Drupal\access_events\CancellationNotifier::KEY);
    $nullEnqueued = $notifier->enqueueGated($nullEnd, \Drupal\access_events\CancellationNotifier::KEY);

    $this->assertSame(1, $futureEnqueued);
    $this->assertSame(1, $nullEnqueued);
  }

  /**
   * enqueueGated() with a verifiably-past instance (a real, parseable end
   * date in the past) enqueues nothing.
   */
  public function testEnqueueGatedVerifiablyPastEnqueuesNothing(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->save();

    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $this->registerUser($this->createUser(), $pastInstance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $this->assertSame(0, $notifier->enqueueGated($pastInstance, \Drupal\access_events\CancellationNotifier::KEY));
  }

  /**
   * addEmailNotificationToQueue() always queues (never sends synchronously)
   * regardless of the email_notifications_queue config flag — there is no
   * force-queue mechanism in CancellationNotifier to bypass, since contrib's
   * own addEmailNotificationToQueue() only gates on email_notifications +
   * notifications.<key>.enabled (verified directly against its source), not
   * the queue-vs-immediate flag. This asserts the queue behavior holds even
   * with email_notifications_queue explicitly OFF.
   */
  public function testEnqueueGatedQueuesRegardlessOfEmailNotificationsQueueFlag(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', FALSE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();

    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $instance);

    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $before = $queue->numberOfItems();

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $enqueued = $notifier->enqueueGated($instance, \Drupal\access_events\CancellationNotifier::KEY);

    $this->assertSame(1, $enqueued);
    $this->assertSame($before + 1, $queue->numberOfItems());
  }

  /**
   * Forces an event instance's date.end_value to NULL via a direct DB
   * update on its field-data table, then resets the entity storage cache so
   * a subsequent load sees it.
   *
   * The entity API's own validation constraints refuse a NULL end_value save
   * on the daterange field, so this models a legacy/malformed row the way
   * only a direct write can.
   */
  private function setInstanceEndDateNull(EventInstance $instance): void {
    $connection = \Drupal::database();
    $connection->update('eventinstance_field_data')
      ->fields(['date__end_value' => NULL])
      ->condition('id', $instance->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache([(int) $instance->id()]);
  }

}
