<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\CancellationNotifier;
use Drupal\access_events\EventDomainContext;
use Drupal\access_events\Plugin\QueueWorker\DomainAwareEmailNotificationsQueueWorker;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\symfony_mailer\Address;
use Drupal\symfony_mailer_test\MailerTestTrait;

/**
 * Tests that a queued notice mails through the EVENT's domain, not cron's.
 *
 * D8-2835: EmailNotificationsQueueWorker::processItem() (contrib) just hands
 * the pre-rendered subject/body to mailManager->mail() — it never switches
 * \Drupal::service('domain.negotiator'), so access_misc_mailer_build()
 * (hook_mailer_build) resolves From/Reply-To/transport off whichever domain
 * is active when CRON drains the queue, not the domain the event belongs
 * to. The fix stamps the resolved domain id onto the queue item's params at
 * ENQUEUE time (access_events_recurring_events_registration_message_params_
 * alter(), via EventDomainContext::resolveDomain()) and swaps in
 * DomainAwareEmailNotificationsQueueWorker (access_events_queue_info_alter())
 * to put that domain active again for the duration of the SEND.
 *
 * Unlike CancellationNotifierDomainTest (which only asserts link hosts in
 * the rendered body), this suite drives a queued item all the way through
 * the real queue worker plugin and inspects the actual sent Symfony Mailer
 * Email — the domain-based From/Reply-To swap in access_misc_mailer_build()
 * only fires post-render, at SEND time, so it cannot be observed from the
 * enqueue-time body alone.
 *
 * @coversDefaultClass \Drupal\access_events\Plugin\QueueWorker\DomainAwareEmailNotificationsQueueWorker
 * @group access_events
 */
class QueuedNotificationDomainMailTest extends EventKernelTestBase {

  use MailerTestTrait;

  /**
   * {@inheritdoc}
   *
   * Domain supplies the Domain entity type + domain.negotiator, exactly as
   * CancellationNotifierDomainTest.
   * Symfony_mailer (+ its mailer_transport placeholder dependency) swaps
   * plugin.manager.mail for Drupal\symfony_mailer\MailManagerReplacement
   * (see SymfonyMailerServiceProvider), which is what makes
   * access_misc_mailer_build() (a hook_mailer_build implementation) fire at
   * all — core's default MailManager never calls it.
   * Symfony_mailer_test is contrib's own hidden test module. Its
   * MailerTestService forces transport to null:// (postRender) and records
   * every sent Email into state (postSend); MailerTestTrait reads them back.
   * This is the "minimal viable capture mechanism" the ticket asks for — no
   * bespoke test module needed, contrib already ships one for exactly this.
   */
  protected static $modules = [
    'domain',
    'symfony_mailer',
    'mailer_transport',
    'symfony_mailer_test',
  ];

  /**
   * The event's own domain — ccmnet_org, matching access_misc's real map.
   *
   * The id has to be the literal 'ccmnet_org' (not an arbitrary test id):
   * access_misc_mailer_build()'s $domain_transport_map is keyed on this exact
   * domain id.
   */
  private const EVENT_DOMAIN_ID = 'ccmnet_org';

  /**
   * The domain active when the notice is ENQUEUED — the wrong one.
   */
  private const ENQUEUEING_DOMAIN_ID = 'requesting_site';

  /**
   * The domain active when CRON drains the queue — also the wrong one.
   *
   * Distinct from ENQUEUEING_DOMAIN_ID so the two "wrong domain" moments
   * (enqueue-time request, send-time cron) can never be confused for each
   * other in the assertions.
   */
  private const CRON_DOMAIN_ID = 'cron_site';

  /**
   * The event's domain entity.
   */
  private Domain $eventDomain;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['domain']);
    $this->installConfig(['symfony_mailer']);
    \Drupal::service('router.builder')->rebuild();

    foreach ([self::ENQUEUEING_DOMAIN_ID, self::EVENT_DOMAIN_ID, self::CRON_DOMAIN_ID] as $id) {
      Domain::create([
        'id' => $id,
        'hostname' => $id . '.example.com',
        'name' => $id,
        'scheme' => 'https',
        'status' => 1,
      ])->save();
    }
    $this->eventDomain = Domain::load(self::EVENT_DOMAIN_ID);

    $this->enableCancellationNotices();
  }

  /**
   * {@inheritdoc}
   *
   * Same reason as CancellationNotifierDomainTest: domain_access needs to be
   * a genuine entity_reference to domain for EventDomainContext::
   * resolveDomain() to have something real to resolve.
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
   * Enqueuing on domain A for an event on domain B stamps the EVENT's id.
   *
   * The negotiator is left on ENQUEUEING_DOMAIN_ID for this test (modeling
   * an API request on one site creating/cancelling an event that lives on
   * another) — the stamped param must carry the EVENT's domain regardless
   * of what is active while enqueuing.
   */
  public function testEnqueueStampsTheEventsDomainId(): void {
    \Drupal::service('domain.negotiator')->setActiveDomain(Domain::load(self::ENQUEUEING_DOMAIN_ID));

    $instance = $this->createInstanceOnEventDomain();
    $this->registerUser($this->createUser(), $instance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $this->assertSame(1, $notifier->enqueueGated($instance, CancellationNotifier::KEY));

    $item = $this->lastQueuedItem();
    $this->assertSame(self::EVENT_DOMAIN_ID, $item->params[EventDomainContext::DOMAIN_PARAM] ?? NULL);
  }

  /**
   * Draining the queue on cron's domain still mails through the EVENT's.
   *
   * Enqueues on ENQUEUEING_DOMAIN_ID, points the negotiator at CRON_DOMAIN_ID
   * (modeling drush cron's own process), claims the item, and runs it
   * through the plugin the queue manager actually resolves for this plugin
   * id — proving access_events_queue_info_alter() really swapped the class,
   * not just that the subclass works in isolation. The sent Email's From/
   * Reply-To must be ccmnet_org's, exactly what access_misc_mailer_build()
   * maps that domain id to.
   */
  public function testQueueWorkerSendsThroughTheEventsDomain(): void {
    \Drupal::service('domain.negotiator')->setActiveDomain(Domain::load(self::ENQUEUEING_DOMAIN_ID));

    $instance = $this->createInstanceOnEventDomain();
    $this->registerUser($this->createUser(), $instance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $notifier->enqueueGated($instance, CancellationNotifier::KEY);

    // Model cron: a different active domain than either the enqueueing
    // request or the event.
    $cronDomain = Domain::load(self::CRON_DOMAIN_ID);
    \Drupal::service('domain.negotiator')->setActiveDomain($cronDomain);

    $queueWorker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance('recurring_events_registration_email_notifications_queue_worker');
    $this->assertInstanceOf(
      DomainAwareEmailNotificationsQueueWorker::class,
      $queueWorker,
      'access_events_queue_info_alter() swapped in the domain-aware subclass.'
    );

    $queue = \Drupal::queue('recurring_events_registration_email_notifications_queue_worker');
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $queueWorker->processItem($item->data);

    // readMail() populates $this->email (used by assertAddress()/
    // assertReplyTo() below) but itself returns the underlying Symfony Mime
    // Email, which carries no assertion helpers — so it is not chained off.
    $this->readMail();
    $this->assertAddress('from', new Address('info@mg.ccmnet.org', 'CCMNet'));
    $this->assertReplyTo('info@ccmnet.org', 'CCMNet');

    // The active domain is restored to cron's own once the send completes —
    // a leaked domain switch would misroute every later mail in this process.
    $this->assertSame(
      self::CRON_DOMAIN_ID,
      \Drupal::service('domain.negotiator')->getActiveDomain()->id(),
      'The active domain is restored after processItem().'
    );
  }

  /**
   * An item with no stamped domain id sends with pre-fix behavior.
   *
   * Models a queue item enqueued BEFORE this fix shipped — the one case the
   * fallback in DomainAwareEmailNotificationsQueueWorker::processItem()
   * exists for. access_events_eventseries_presave()'s own domain guard fills
   * an empty domain_access from the active domain on every CREATE, so a
   * freshly-created instance always resolves *some* domain and the alter
   * always stamps it; a truly unstamped item can only be a pre-fix legacy
   * row. Enqueue normally on the event's own domain, then strip
   * DOMAIN_PARAM from the queued row directly to reproduce that legacy
   * shape, rather than fight the presave guard to (unrealistically)
   * construct an eventinstance with no domain at all.
   */
  public function testItemWithNoStampedDomainSendsWithTodaysBehavior(): void {
    $instance = $this->createInstanceOnEventDomain();
    $this->registerUser($this->createUser(), $instance);

    $notifier = \Drupal::service('access_events.cancellation_notifier');
    $notifier->enqueueGated($instance, CancellationNotifier::KEY);

    $item = $this->lastQueuedItem();
    $this->assertArrayHasKey(
      EventDomainContext::DOMAIN_PARAM,
      $item->params,
      'Fixture premise: the normal enqueue path did stamp a domain id.'
    );
    unset($item->params[EventDomainContext::DOMAIN_PARAM]);
    $this->overwriteLastQueuedItem($item);

    $cronDomain = Domain::load(self::CRON_DOMAIN_ID);
    \Drupal::service('domain.negotiator')->setActiveDomain($cronDomain);

    $queueWorker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance('recurring_events_registration_email_notifications_queue_worker');

    $queue = \Drupal::queue('recurring_events_registration_email_notifications_queue_worker');
    $queued = $queue->claimItem();
    $this->assertNotFalse($queued);
    $queueWorker->processItem($queued->data);

    // cron_site has no entry in access_misc's domain_transport_map, so the
    // default From (whatever the site's system.site mail is) is used — the
    // point under test is only that it is NOT the ccmnet_org override.
    $this->readMail();
    $fromEmails = array_map(
      static fn ($address) => $address->getEmail(),
      $this->email->getAddress('from')
    );
    $this->assertNotContains('info@mg.ccmnet.org', $fromEmails, 'The ccmnet_org override was not applied.');
  }

  /**
   * Creates a registrable future instance that belongs to the event's domain.
   *
   * Mirrors CancellationNotifierDomainTest::createInstanceOnEventDomain().
   */
  private function createInstanceOnEventDomain(): EventInstance {
    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $series->set('domain_access', [['target_id' => $this->eventDomain->id()]]);
    $series->save();
    $instance->save();
    $this->assertSame(
      $this->eventDomain->id(),
      $instance->get('domain_access')->target_id,
      'Fixture premise: the instance is on the event domain.'
    );
    return $instance;
  }

  /**
   * Turns on the notification key this suite enqueues under.
   */
  private function enableCancellationNotices(): void {
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('notifications.' . CancellationNotifier::KEY . '.enabled', TRUE)
      ->set('notifications.' . CancellationNotifier::KEY . '.subject', 'Event cancelled')
      ->set('notifications.' . CancellationNotifier::KEY . '.body', 'Your event was cancelled.')
      ->save();
  }

  /**
   * Returns the most recently queued notification item, unserialized.
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
    return unserialize((string) $data, ['allowed_classes' => [\stdClass::class]]);
  }

  /**
   * Overwrites the most recently queued item's serialized data.
   *
   * Used only to reproduce a pre-fix legacy queue row (no stamped domain
   * param) from an otherwise normal enqueue — see
   * testItemWithNoStampedDomainSendsWithTodaysBehavior().
   */
  private function overwriteLastQueuedItem(\stdClass $item): void {
    $itemId = \Drupal::database()->select('queue', 'q')
      ->fields('q', ['item_id'])
      ->condition('name', 'recurring_events_registration_email_notifications_queue_worker')
      ->orderBy('item_id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $this->assertNotFalse($itemId);
    \Drupal::database()->update('queue')
      ->fields(['data' => serialize($item)])
      ->condition('item_id', $itemId)
      ->execute();
  }

}
