<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests the send-only cancellation notifier.
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
   * Enqueues one notice per future-instance registrant; never deletes them.
   */
  public function testNotifyInstanceEnqueuesAndKeepsRegistrant(): void {
    $config = \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config');
    $config->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();

    $instance = $this->createRegistrableInstance();
    $registrant = $this->registerUser($this->createUser(), $instance);
    $registrantId = $registrant->id();

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $enqueued = $notifier->notifyInstanceCancelled((int) $instance->id());

    $this->assertSame(1, $enqueued);

    // SEND-ONLY: the registrant row must still exist.
    $reloaded = \Drupal::entityTypeManager()->getStorage('registrant')->loadUnchanged($registrantId);
    $this->assertNotNull($reloaded, 'Notify must NOT delete the registrant.');

    // A queue item was created (the real worker id NotificationService
    // enqueues to; see NotificationService::addEmailNotificationToQueue()).
    $queue = \Drupal::service('queue')->get('recurring_events_registration_email_notifications_queue_worker');
    $this->assertGreaterThan(0, $queue->numberOfItems());
  }

  /**
   * A past instance is not notified.
   */
  public function testNotifyInstancePastEnqueuesNothing(): void {
    $config = \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config');
    $config->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->save();

    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $this->registerUser($this->createUser(), $pastInstance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $this->assertSame(0, $notifier->notifyInstanceCancelled((int) $pastInstance->id()));
  }

  /**
   * Enqueues across every future instance of a cancelled series.
   */
  public function testNotifySeriesEnqueuesAcrossFutureInstancesOnly(): void {
    $config = \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config');
    $config->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->save();

    $futureInstance = $this->createRegistrableInstance();
    $futureRegistrant = $this->registerUser($this->createUser(), $futureInstance);
    $futureRegistrantId = $futureRegistrant->id();

    $pastInstance = $this->createRegistrableInstance(pastDate: TRUE);
    $pastRegistrant = $this->registerUser($this->createUser(), $pastInstance);
    $pastRegistrantId = $pastRegistrant->id();

    // Move the past instance into the same series as the future one so a
    // single series spans both a future and an already-ended occurrence.
    $seriesId = (int) $futureInstance->get('eventseries_id')->target_id;
    $pastInstance->set('eventseries_id', $seriesId)->save();

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $enqueued = $notifier->notifySeriesCancelled($seriesId);

    $this->assertSame(1, $enqueued);

    $registrantStorage = \Drupal::entityTypeManager()->getStorage('registrant');
    $this->assertNotNull($registrantStorage->loadUnchanged($futureRegistrantId), 'Notify must NOT delete the future registrant.');
    $this->assertNotNull($registrantStorage->loadUnchanged($pastRegistrantId), 'Notify must NOT delete the past registrant.');
  }

  /**
   * A non-existent series enqueues nothing rather than erroring.
   */
  public function testNotifySeriesMissingReturnsZero(): void {
    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $this->assertSame(0, $notifier->notifySeriesCancelled(999999));
  }

}
