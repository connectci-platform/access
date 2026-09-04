<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;

/**
 * Tests that a post-survey send carries the EVENT's domain, not the request's.
 *
 * D8-2811: EventDomainContext::forEntity() was pulled out of
 * PostSurvey::getEventDomain() so the active-domain switch (mail transport)
 * and the request-context switch (link hosts) happen together and are
 * restored together, instead of getEventDomain() mutating the negotiator
 * from inside a getter and leaking the last event's domain into the rest of
 * the process. This mirrors CancellationNotifierDomainTest::
 * testDomainContextIsRestoredAfterEnqueue() for the survey send path, which
 * had no test coverage at all — before or after the refactor — until now.
 *
 * The instance under test has no registrants: sendSurveyToRegistrants()
 * resolves the event's domain and enters/exits the domain context (the
 * thing under test) before it ever touches the registrant loop, so the loop
 * body itself — which needs a full registrant/mail-policy fixture unrelated
 * to this refactor — does not need to be exercised here.
 *
 * @coversDefaultClass \Drupal\access_events\EventDomainContext
 * @group access_events
 */
class PostSurveyDomainTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The domain module on top of the base list — it supplies the Domain
   * entity type, the domain.negotiator service and the domain_access
   * field's target.
   */
  protected static $modules = [
    'domain',
  ];

  /**
   * The host the cron/drush run is "on" — the WRONG host for the event.
   */
  private const HOST_REQUEST = 'requesting-site.example.com';

  /**
   * The host the event's own domain lives on — the host the send must use.
   */
  private const HOST_EVENT = 'event-site.example.com';

  /**
   * The event's domain entity.
   */
  private Domain $eventDomain;

  /**
   * The domain the cron/drush run is on.
   */
  private Domain $requestDomain;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['domain']);
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

    // Model the bug's starting condition: the process running the cron job
    // is on HOST_REQUEST. Both the negotiator (mail transport) and the
    // router request context (absolute link hosts) have to be set — they
    // are independent, which is the whole point of EventDomainContext.
    $this->putRequestOn($this->requestDomain);
  }

  /**
   * {@inheritdoc}
   *
   * The base seeds domain_access as a plain string field — enough for
   * suites that only need access_events_entity_presave() not to fatal on
   * it. This suite is specifically about resolving a real Domain entity off
   * that field, so it declares domain_access the way the site really
   * configures it: a multi-valued entity_reference to domain. Also adds
   * post_survey_email_text, which sendSurveyToRegistrants() reads
   * unconditionally (even with no registrants), before the base's
   * FieldStorageConfig list.
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
      ['eventinstance', 'post_survey_email_text', 'text_long', 1],
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
   * The domain switch is undone after a post-survey send.
   *
   * A leaked domain switch would send every later mail and every later link
   * in the same cron run to the wrong site — worse than the bug being
   * fixed. Deleting EventDomainContext::forDomain()'s
   * $this->negotiator->setActiveDomain($domain) call (the exact regression
   * risk D8-2811 flagged) would still leave THIS test green, because the
   * point under test is the RESTORE; EventDomainContextTest::
   * testForDomainPointsTheNegotiatorAtTheDomainDuringTheCallback() is what
   * catches that deletion.
   */
  public function testDomainContextIsRestoredAfterPostSurveySend(): void {
    $this->createSurveyEligibleInstanceOnEventDomain();

    \Drupal::service('access_events.post_survey')->postSurveyEmail();

    $context = \Drupal::service('router.request_context');
    $this->assertSame(self::HOST_REQUEST, $context->getHost());
    $this->assertSame('https', $context->getScheme());
    $this->assertSame(
      $this->requestDomain->id(),
      \Drupal::service('domain.negotiator')->getActiveDomain()->id()
    );
  }

  /**
   * Creates a past, unsent, unarchived instance assigned to the event domain.
   *
   * No registrants are attached: sendSurveyToRegistrants() resolves the
   * domain and enters/exits the domain context before it ever loads a
   * registrant, so the domain switch under test runs either way, without a
   * full registrant fixture.
   *
   * The domain is set on the INSTANCE only — access_events_entity_presave()
   * overwrites an instance's domain_access from its series whenever the
   * series has one, so leaving the series' value empty is what lets the
   * instance keep the value set here.
   */
  private function createSurveyEligibleInstanceOnEventDomain(): EventInstance {
    $instance = $this->createRegistrableInstance(pastDate: TRUE);
    $instance->set('domain_access', [['target_id' => $this->eventDomain->id()]]);
    $instance->set('field_post_survey_sent', 0);
    $instance->save();
    return $instance;
  }

  /**
   * Points the whole request at a domain, as a real cron/drush run would.
   *
   * Both the negotiator (which decides the mail transport) and the router
   * request context (which decides absolute link hosts) have to be set:
   * they are independent, which is the whole point of the fix.
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

}
