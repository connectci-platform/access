<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\CancellationNotifier;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;

/**
 * Tests that queued event notices carry the EVENT's domain, not the request's.
 *
 * D8-2811: a notice enqueued from a request on domain A for an event that
 * lives on domain B rendered its cancel-registration link — and every other
 * absolute URL in the body — on domain A's host, sending recipients to the
 * wrong site. Contrib renders subject/body at ENQUEUE time
 * (NotificationService::addEmailNotificationToQueue()) and bakes them into the
 * queue item, so the domain has to be right there, not when cron drains the
 * queue.
 *
 * Unlike the sibling CancellationNotifierTest, this suite installs the real
 * domain module and declares domain_access as a genuine entity_reference to
 * domain entities (that sibling stubs it as a plain string), because the fix
 * resolves an actual Domain entity off the field.
 *
 * @coversDefaultClass \Drupal\access_events\EventDomainContext
 * @group access_events
 */
class CancellationNotifierDomainTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The domain module on top of the base list — it supplies the Domain entity
   * type, the domain.negotiator service and the domain_access field's target.
   */
  protected static $modules = [
    'domain',
  ];

  /**
   * The host the enqueueing "request" is on — the WRONG host for the event.
   */
  private const HOST_REQUEST = 'requesting-site.example.com';

  /**
   * The host the event's own domain lives on — the host links must carry.
   */
  private const HOST_EVENT = 'event-site.example.com';

  /**
   * The event's domain entity.
   */
  private Domain $eventDomain;

  /**
   * The domain the enqueueing request is on.
   */
  private Domain $requestDomain;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['domain']);

    // [registrant:delete_url] resolves through $registrant->toUrl(
    // 'delete-form'), which needs the router populated in a kernel env.
    \Drupal::service('router.builder')->rebuild();

    $this->requestDomain = Domain::create([
      'id' => 'requesting_site',
      'hostname' => self::HOST_REQUEST,
      'name' => 'Requesting site',
      'scheme' => 'https',
      'status' => 1,
    ]);
    $this->requestDomain->save();

    $this->eventDomain = Domain::create([
      'id' => 'event_site',
      'hostname' => self::HOST_EVENT,
      'name' => 'Event site',
      'scheme' => 'https',
      'status' => 1,
    ]);
    $this->eventDomain->save();

    // Model the bug's starting condition: the process doing the enqueueing is
    // on HOST_REQUEST. Both the negotiator (mail transport) and the router
    // request context (absolute link hosts) have to be set — they are
    // independent, which is the whole point of the fix.
    $this->putRequestOn($this->requestDomain);

    $this->enableCancellationNotices();
  }

  /**
   * {@inheritdoc}
   *
   * The base seeds domain_access as a plain string field — enough for suites
   * that only need access_events_entity_presave() not to fatal on it. This
   * suite is specifically about resolving a real Domain entity off that
   * field, so it declares domain_access the way the site really configures
   * it: a multi-valued entity_reference to domain. The remaining
   * presave-touched fields are seeded exactly as the base does.
   */
  protected function attachInstancePresaveFields(): void {
    foreach (['eventseries', 'eventinstance'] as $entityType) {
      if (!FieldStorageConfig::loadByName($entityType, 'domain_access')) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => 'domain_access',
          'type' => 'entity_reference',
          'cardinality' => -1,
          'settings' => ['target_type' => 'domain'],
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => 'domain_access',
          'bundle' => 'default',
          'label' => 'Domain access',
        ])->save();
      }
    }

    $fields = [
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
  }

  /**
   * A notice for an event on another domain renders links on THAT domain.
   *
   * The regression under test: before the fix the body carried
   * HOST_REQUEST because [registrant:delete_url] and [eventinstance:url]
   * resolve through the router request context, which
   * DomainNegotiator::setActiveDomain() does not touch.
   */
  public function testQueuedNoticeRendersLinksOnTheEventsOwnDomain(): void {
    $instance = $this->createInstanceOnEventDomain();
    $this->registerUser($this->createUser(), $instance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $this->assertSame(1, $notifier->enqueueGated($instance, CancellationNotifier::KEY));

    $body = $this->lastQueuedItem()->params['body'];
    $this->assertStringContainsString('https://' . self::HOST_EVENT . '/', $body);
    $this->assertStringNotContainsString(self::HOST_REQUEST, $body);
  }

  /**
   * The reschedule path (enqueueModificationGated) switches domain too.
   *
   * It is a separate entry point with its own registrant loop, so a fix
   * applied to only one of the two would still ship the bug on date edits —
   * the exact scenario the ticket verified live.
   */
  public function testQueuedModificationNoticeRendersLinksOnTheEventsOwnDomain(): void {
    $instance = $this->createInstanceOnEventDomain();
    $this->registerUser($this->createUser(), $instance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $enqueued = $notifier->enqueueModificationGated($instance, '2999-01-01T12:00:00', '2999-02-01T12:00:00');
    $this->assertSame(1, $enqueued);

    $body = $this->lastQueuedItem()->params['body'];
    $this->assertStringContainsString('https://' . self::HOST_EVENT . '/', $body);
    $this->assertStringNotContainsString(self::HOST_REQUEST, $body);
  }

  /**
   * The switch is undone afterwards.
   *
   * A link generated after the enqueue is back on the requesting domain, and
   * so is the active domain.
   *
   * A leaked domain switch is worse than the bug being fixed — it would send
   * every later link and every later mail in the same process to the wrong
   * site.
   */
  public function testDomainContextIsRestoredAfterEnqueue(): void {
    $instance = $this->createInstanceOnEventDomain();
    $this->registerUser($this->createUser(), $instance);

    \Drupal::service('access_events.cancellation_notifier')
      ->enqueueGated($instance, CancellationNotifier::KEY);

    $context = \Drupal::service('router.request_context');
    $this->assertSame(self::HOST_REQUEST, $context->getHost());
    $this->assertSame('https', $context->getScheme());
    $this->assertSame(
      $this->requestDomain->id(),
      \Drupal::service('domain.negotiator')->getActiveDomain()->id()
    );
  }

  /**
   * An event with no domain assigned still enqueues, on the request's host.
   *
   * Not every occurrence carries a domain_access value, and the fix must not
   * turn "no domain" into "no notification".
   */
  public function testInstanceWithoutDomainFallsBackToTheRequestHost(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser(), $instance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $this->assertSame(1, $notifier->enqueueGated($instance, CancellationNotifier::KEY));

    $body = $this->lastQueuedItem()->params['body'];
    $this->assertStringContainsString('https://' . self::HOST_REQUEST . '/', $body);
  }

  /**
   * Creates a registrable future instance assigned to the event's domain.
   *
   * The domain is deliberately set on the INSTANCE only:
   * access_events_entity_presave() overwrites an instance's domain_access
   * from its series whenever the series has one, so leaving the series' value
   * empty is what lets the instance keep the value set here.
   */
  private function createInstanceOnEventDomain(): EventInstance {
    $instance = $this->createRegistrableInstance();
    $instance->set('domain_access', [['target_id' => $this->eventDomain->id()]]);
    $instance->save();
    return $instance;
  }

  /**
   * Turns on the notification keys this suite enqueues under.
   *
   * Their bodies carry the two absolute-URL tokens the ticket named. They are
   * set directly via getEditable() rather than installConfig() for the reason
   * documented in CancellationNotifierTest::setUp().
   */
  private function enableCancellationNotices(): void {
    $body = 'Cancel your registration: [registrant:delete_url] Event: [eventinstance:url]';
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.' . CancellationNotifier::KEY . '.enabled', TRUE)
      ->set('notifications.' . CancellationNotifier::KEY . '.subject', 'Event cancelled')
      ->set('notifications.' . CancellationNotifier::KEY . '.body', $body)
      ->set('notifications.' . CancellationNotifier::MODIFICATION_KEY . '.enabled', TRUE)
      ->set('notifications.' . CancellationNotifier::MODIFICATION_KEY . '.subject', 'Event rescheduled')
      ->set('notifications.' . CancellationNotifier::MODIFICATION_KEY . '.body', $body)
      ->save();
  }

  /**
   * Points the whole request at a domain, as a real inbound request would.
   *
   * Both the negotiator (which decides the mail transport) and the router
   * request context (which decides absolute link hosts) have to be set: they
   * are independent, which is the whole point of the fix.
   */
  private function putRequestOn(Domain $domain): void {
    \Drupal::service('domain.negotiator')->setActiveDomain($domain);
    $context = \Drupal::service('router.request_context');
    $context->setScheme('https');
    $context->setHost($domain->getHostname());
    $context->setHttpPort(80);
    $context->setHttpsPort(443);
    $context->setCompleteBaseUrl(rtrim($domain->getPath(), '/'));
  }

  /**
   * Returns the most recently queued notification item.
   */
  private function lastQueuedItem(): \stdClass {
    $data = \Drupal::database()->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', 'recurring_events_registration_email_notifications_queue_worker')
      ->orderBy('item_id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $this->assertNotFalse($data, 'A notification item was queued.');
    // Only the plain \stdClass item contrib queues is expected here; naming
    // it keeps this off the unrestricted-unserialize footgun.
    return unserialize((string) $data, ['allowed_classes' => [\stdClass::class]]);
  }

}
