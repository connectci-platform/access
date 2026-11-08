<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\EventDomainContext;
use Drupal\access_misc\Services\SymfonyMail;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field_inheritance\Entity\FieldInheritance;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that an API registration tells people what the web form tells them.
 *
 * D8-2999: a registration made through the ACCESS Assistant used to sit
 * unapproved with nobody told. Both paths now share the registrant email and
 * the organizer digest, so these cases drive the API and the form handler and
 * assert the same outcome from each.
 *
 * The mail service is replaced by a recording stand-in, because the
 * access_misc policies that turn these variables into a message are site
 * config this kernel env does not have. testRealSendReachesTheRegistrant()
 * restores the real service to prove a message actually leaves.
 *
 * @group access_events
 */
class RegistrationNotificationParityTest extends EventKernelTestBase {

  use MailerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'domain',
    'symfony_mailer',
    'mailer_transport',
    'symfony_mailer_test',
  ];

  /**
   * The domain the API request arrives on.
   */
  private const REQUEST_DOMAIN_ID = 'requesting_site';

  /**
   * The domain the event belongs to.
   */
  private const EVENT_DOMAIN_ID = 'ccmnet_org';

  /**
   * The state key the organizer digest accumulates in.
   */
  private const DIGEST_KEY = 'access_misc_email_digest';

  /**
   * Records each send, and throws instead when told to fail.
   */
  private SymfonyMail $mail;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['domain']);
    $this->installConfig(['symfony_mailer']);
    \Drupal::service('router.builder')->rebuild();

    foreach ([self::REQUEST_DOMAIN_ID, self::EVENT_DOMAIN_ID] as $id) {
      Domain::create([
        'id' => $id,
        'hostname' => $id . '.example.com',
        'name' => $id,
        'scheme' => 'https',
        'status' => 1,
      ])->save();
    }
    \Drupal::service('domain.negotiator')->setActiveDomain(Domain::load(self::REQUEST_DOMAIN_ID));

    $this->attachRegistrationFields();

    // Both users were built before their name fields existed, so reload them
    // or they keep the old field definitions.
    $users = \Drupal::entityTypeManager()->getStorage('user');
    $users->resetCache();
    $this->owner = $users->load($this->owner->id());
    $this->stranger = $users->load($this->stranger->id());

    $this->owner->set('field_user_first_name', 'Ada');
    $this->owner->set('field_user_last_name', 'Lovelace');
    $this->owner->save();

    $this->mail = new class() extends SymfonyMail {

      /**
       * The sends so far, oldest first.
       */
      public array $sent = [];

      /**
       * When set, thrown in place of sending.
       */
      public ?\Throwable $failure = NULL;

      /**
       * {@inheritdoc}
       */
      public function email($policy, $policy_subtype, $set_email, $variables) {
        if ($this->failure) {
          throw $this->failure;
        }
        $this->sent[] = [
          'policy' => $policy,
          'subtype' => $policy_subtype,
          'to' => $set_email,
          'variables' => $variables,
          'domain' => \Drupal::service('domain.negotiator')->getActiveDomain()?->id(),
        ];
      }

    };
    $this->container->set('access_misc.symfony.mail', $this->mail);
  }

  /**
   * {@inheritdoc}
   *
   * The domain_access field has to be a real reference to domain for the
   * event's own domain to resolve, as in CancellationNotifierDomainTest.
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

    foreach ([
      ['eventinstance', 'post_survey_url', 'link'],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer'],
      ['eventinstance', 'field_post_survey_sent', 'integer'],
    ] as [$entityType, $fieldName, $type]) {
      $this->attachField($entityType, 'default', $fieldName, $type);
    }
  }

  /**
   * Attaches the site-level fields the two registration paths read and write.
   */
  private function attachRegistrationFields(): void {
    foreach ([
      ['user', 'user', 'field_user_first_name', 'string'],
      ['user', 'user', 'field_user_last_name', 'string'],
      ['registrant', 'default', 'field_first_name', 'string'],
      ['registrant', 'default', 'field_last_name', 'string'],
      ['registrant', 'default', 'field_screen_survey_sent', 'timestamp'],
      ['eventseries', 'default', 'field_contact', 'string'],
      ['eventseries', 'default', 'field_screen_survey_url', 'link'],
      ['eventseries', 'default', 'field_screen_survey_email_text', 'text_long'],
    ] as [$entityType, $bundle, $fieldName, $type]) {
      $this->attachField($entityType, $bundle, $fieldName, $type);
    }

    foreach ([
      'eventinstance_default_screen_survey_url' => 'field_screen_survey_url',
      'eventinstance_default_screen_survey_email_text' => 'field_screen_survey_email_text',
    ] as $id => $sourceField) {
      FieldInheritance::create([
        'id' => $id,
        'label' => $sourceField,
        'type' => 'inherit',
        'sourceEntityType' => 'eventseries',
        'sourceEntityBundle' => 'default',
        'sourceField' => $sourceField,
        'destinationEntityType' => 'eventinstance',
        'destinationEntityBundle' => 'default',
        'destinationField' => '',
        'plugin' => 'default_inheritance',
      ])->save();
    }

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Creates one configurable field, unless it already exists.
   */
  private function attachField(string $entityType, string $bundle, string $fieldName, string $type): void {
    if (FieldStorageConfig::loadByName($entityType, $fieldName)) {
      return;
    }
    FieldStorageConfig::create([
      'entity_type' => $entityType,
      'field_name' => $fieldName,
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entityType,
      'field_name' => $fieldName,
      'bundle' => $bundle,
      'label' => $fieldName,
    ])->save();
  }

  /**
   * Creates a registrable instance authored by $this->stranger.
   *
   * The digest is addressed to the series author, so the series needs one.
   *
   * @param int $capacity
   *   Seat capacity.
   * @param bool $waitlist
   *   Whether the waitlist is enabled.
   * @param array $seriesValues
   *   Field values to set on the series after the instance exists.
   */
  private function createAuthoredInstance(int $capacity = 60, bool $waitlist = FALSE, array $seriesValues = []): EventInstance {
    $instance = $this->asActingUser(
      $this->stranger,
      fn () => $this->createRegistrableInstance($capacity, $waitlist),
    );
    if ($seriesValues) {
      $series = $instance->getEventSeries();
      foreach ($seriesValues as $field => $value) {
        $series->set($field, $value);
      }
      $series->save();
      $instance = $this->reloadInstance($instance);
    }
    return $instance;
  }

  /**
   * Loads the only registrant the acting user holds on an instance.
   */
  private function registrantFor(EventInstance $instance): Registrant {
    $found = \Drupal::entityTypeManager()->getStorage('registrant')->loadByProperties([
      'eventinstance_id' => $instance->id(),
      'user_id' => $this->owner->id(),
    ]);
    $this->assertCount(1, $found);
    return reset($found);
  }

  /**
   * Decodes a commit response.
   */
  private function commit(EventInstance $instance, $user = NULL): array {
    $response = $this->doRegister($instance, $user ?? $this->owner, ['confirmed' => TRUE]);
    $this->assertSame(200, $response->getStatusCode());
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Runs the form's email handler the way a submitted registration does.
   */
  private function submitWebForm(EventInstance $instance, bool $waitlist = FALSE): void {
    $this->pushRequest(Request::create('/events/' . $instance->id() . '/registrations/add', 'POST'));
    $formState = new FormState();
    $formState->setValues([
      'email' => [['value' => 'owner@example.com']],
      'field_first_name' => [['value' => 'Ada']],
      'field_last_name' => [['value' => 'Lovelace']],
      'user_id' => [['target_id' => $this->owner->id()]],
      'add_to_waitlist' => $waitlist ? '1' : '0',
    ]);
    access_misc_registrant_email_submit([], $formState);
  }

  /**
   * A confirmed registration has the user's names and stays unapproved.
   */
  public function testConfirmedRegistrationCopiesNamesAndStaysUnapproved(): void {
    $instance = $this->createAuthoredInstance();

    $this->commit($instance);

    $registrant = $this->registrantFor($instance);
    $this->assertSame('Ada', $registrant->get('field_first_name')->value);
    $this->assertSame('Lovelace', $registrant->get('field_last_name')->value);
    $this->assertSame('owner@example.com', $registrant->get('email')->value);
    $this->assertSame(0, (int) $registrant->get('status')->value);
  }

  /**
   * A seated registration sends the pending email to the registrant.
   */
  public function testSeatedRegistrationSendsPendingEmail(): void {
    $instance = $this->createAuthoredInstance();

    $this->commit($instance);

    $this->assertCount(1, $this->mail->sent);
    $sent = $this->mail->sent[0];
    $this->assertSame('access_misc', $sent['policy']);
    $this->assertSame('register', $sent['subtype']);
    $this->assertSame('owner@example.com', $sent['to']);
    $this->assertSame('Ada Lovelace', $sent['variables']['name']);
    $this->assertSame('Registration Pending for Registrable Event', (string) $sent['variables']['email_title']);
  }

  /**
   * A waitlisted registration sends the waitlist email and stays pending.
   */
  public function testWaitlistedRegistrationSendsWaitlistEmail(): void {
    $instance = $this->createAuthoredInstance(capacity: 1, waitlist: TRUE);
    $this->registerUser($this->stranger, $instance);

    $data = $this->commit($instance);

    $this->assertSame('waitlisted', $data['status']);
    $this->assertSame('pending', $data['approval']);
    $this->assertSame('You have been added to the waitlist.', $data['message']);
    $this->assertCount(1, $this->mail->sent);
    $this->assertSame('waitlist', $this->mail->sent[0]['subtype']);
  }

  /**
   * The first registration adds a digest entry for the organizer.
   */
  public function testFirstRegistrationAddsDigestEntry(): void {
    $instance = $this->createAuthoredInstance();

    $this->commit($instance);

    $digest = \Drupal::state()->get(self::DIGEST_KEY, []);
    $this->assertArrayHasKey($instance->id(), $digest);
    $entry = $digest[$instance->id()];
    $this->assertSame(1, $entry['registration_count']);
    $this->assertSame('Registrable Event', $entry['event_title']);
    $this->assertSame('stranger@example.com', $entry['author_email']);
  }

  /**
   * A later registration increments the same digest entry.
   */
  public function testLaterRegistrationIncrementsDigestEntry(): void {
    $instance = $this->createAuthoredInstance();

    $this->commit($instance);
    $this->commit($instance, $this->stranger);

    $digest = \Drupal::state()->get(self::DIGEST_KEY, []);
    $this->assertSame(2, $digest[$instance->id()]['registration_count']);
  }

  /**
   * A mail failure still registers the person and still tells the organizer.
   */
  public function testMailFailureStillSucceedsAndAddsDigestEntry(): void {
    $instance = $this->createAuthoredInstance();
    $this->mail->failure = new \RuntimeException('SMTP is down');

    $data = $this->commit($instance);

    $this->assertTrue($data['success']);
    $this->assertSame('registered', $data['status']);
    $this->assertSame(1, $this->countRegistrants($instance));
    $this->assertSame(1, \Drupal::state()->get(self::DIGEST_KEY, [])[$instance->id()]['registration_count']);
  }

  /**
   * A failure entering the event's domain still returns success.
   *
   * The registrant is saved by then, so a 500 here would leave the caller
   * thinking it failed while a retry is refused as already_registered.
   */
  public function testDomainContextFailureStillSucceeds(): void {
    $instance = $this->createAuthoredInstance();
    $this->container->set('access_events.domain_context', new class(\Drupal::service('router.request_context')) extends EventDomainContext {

      /**
       * {@inheritdoc}
       */
      public function forEntity(FieldableEntityInterface $entity, callable $callback): mixed {
        throw new \RuntimeException('Domain could not be resolved');
      }

    });

    $data = $this->commit($instance);

    $this->assertTrue($data['success']);
    $this->assertSame(1, $this->countRegistrants($instance));
  }

  /**
   * A series with no owner still sends the email and returns success.
   */
  public function testMissingSeriesOwnerStillSendsEmail(): void {
    $instance = $this->createRegistrableInstance();
    $this->assertNull($instance->getEventSeries()->getOwner(), 'Fixture premise: the series has no owner.');

    $data = $this->commit($instance);

    $this->assertTrue($data['success']);
    $this->assertCount(1, $this->mail->sent);
    $this->assertSame([], \Drupal::state()->get(self::DIGEST_KEY, []));
  }

  /**
   * A user with no email address registers, and no send is attempted.
   */
  public function testUserWithoutEmailRegistersWithoutSending(): void {
    $instance = $this->createAuthoredInstance();
    $noEmail = User::create(['name' => 'no-email', 'status' => 1]);
    $noEmail->save();

    $data = $this->commit($instance, $noEmail);

    $this->assertTrue($data['success']);
    $this->assertSame([], $this->mail->sent);
    $this->assertSame(1, \Drupal::state()->get(self::DIGEST_KEY, [])[$instance->id()]['registration_count']);
  }

  /**
   * The commit response carries approval and the web form's message.
   */
  public function testCommitResponseCarriesApprovalAndFormMessage(): void {
    $instance = $this->createAuthoredInstance();

    $data = $this->commit($instance);

    $this->assertSame('registered', $data['status']);
    $this->assertSame('pending', $data['approval']);
    $this->assertSame(
      'Your registration was received. You will receive an email after your registration is approved.',
      $data['message'],
    );
  }

  /**
   * A survey event stamps the registrant and asks for the survey.
   */
  public function testSurveyEventStampsRegistrantAndSendsSurveyEmail(): void {
    $instance = $this->createAuthoredInstance(seriesValues: [
      'field_screen_survey_url' => ['uri' => 'https://survey.example.com/1'],
    ]);

    $data = $this->commit($instance);

    $this->assertNotEmpty($this->registrantFor($instance)->get('field_screen_survey_sent')->value);
    $this->assertSame(
      'You will receive a screening survey email shortly after registering. If you do not see it, please check your spam folder.',
      $data['message'],
    );
    $this->assertSame(
      'Please Complete a Pre-Screening Survey for Registrable Event',
      (string) $this->mail->sent[0]['variables']['email_title'],
    );
  }

  /**
   * An event with no survey leaves the survey stamp empty.
   */
  public function testEventWithoutSurveyLeavesStampEmpty(): void {
    $instance = $this->createAuthoredInstance();

    $this->commit($instance);

    $this->assertTrue($this->registrantFor($instance)->get('field_screen_survey_sent')->isEmpty());
  }

  /**
   * Mail and digest use the event's domain, not the one the API call hit.
   */
  public function testNotificationsUseTheEventsDomain(): void {
    $instance = $this->createAuthoredInstance();
    $series = $instance->getEventSeries();
    $series->set('domain_access', [['target_id' => self::EVENT_DOMAIN_ID]]);
    $series->save();
    $instance->save();
    $this->assertSame(
      self::EVENT_DOMAIN_ID,
      $instance->get('domain_access')->target_id,
      'Fixture premise: the instance is on the event domain.',
    );

    $this->commit($instance);

    $this->assertSame(self::EVENT_DOMAIN_ID, $this->mail->sent[0]['domain']);
    $digest = \Drupal::state()->get(self::DIGEST_KEY, []);
    $this->assertSame(self::EVENT_DOMAIN_ID, $digest[$instance->id()]['domain_id']);
    $this->assertSame(
      self::REQUEST_DOMAIN_ID,
      \Drupal::service('domain.negotiator')->getActiveDomain()->id(),
      'The request domain is active again after notifying.',
    );
  }

  /**
   * The events listing cache is invalidated, as the form handler does.
   */
  public function testCommitInvalidatesEventsFacetCache(): void {
    $instance = $this->createAuthoredInstance();
    $tag = ['config:views.view.events_facet'];
    $checksum = \Drupal::service('cache_tags.invalidator.checksum');
    $before = $checksum->getCurrentChecksum($tag);

    $this->commit($instance);

    $checksum->reset();
    $this->assertNotSame($before, $checksum->getCurrentChecksum($tag));
  }

  /**
   * A preview tells nobody anything.
   */
  public function testPreviewSendsNothing(): void {
    $instance = $this->createAuthoredInstance();

    $this->doRegister($instance, $this->owner, []);

    $this->assertSame([], $this->mail->sent);
    $this->assertSame([], \Drupal::state()->get(self::DIGEST_KEY, []));
  }

  /**
   * The web form still sends the pending email and adds the digest entry.
   */
  public function testWebFormSendsPendingEmailAndAddsDigestEntry(): void {
    $instance = $this->createAuthoredInstance();

    $this->submitWebForm($instance);

    $this->assertCount(1, $this->mail->sent);
    $sent = $this->mail->sent[0];
    $this->assertSame('register', $sent['subtype']);
    $this->assertSame('owner@example.com', $sent['to']);
    $this->assertSame('Ada Lovelace', $sent['variables']['name']);
    $this->assertSame('Registration Pending for Registrable Event', (string) $sent['variables']['email_title']);
    $digest = \Drupal::state()->get(self::DIGEST_KEY, []);
    $this->assertSame(1, $digest[$instance->id()]['registration_count']);
    $this->assertSame(self::REQUEST_DOMAIN_ID, $digest[$instance->id()]['domain_id']);
  }

  /**
   * The web form still sends the waitlist email.
   */
  public function testWebFormSendsWaitlistEmail(): void {
    $instance = $this->createAuthoredInstance();

    $this->submitWebForm($instance, waitlist: TRUE);

    $this->assertSame('waitlist', $this->mail->sent[0]['subtype']);
  }

  /**
   * The form and the API hand the mail service identical variables.
   */
  public function testFormAndApiSendTheSameVariables(): void {
    $viaForm = $this->createAuthoredInstance();
    $this->submitWebForm($viaForm);
    $formVariables = $this->mail->sent[0]['variables'];

    $viaApi = $this->createAuthoredInstance();
    $this->commit($viaApi);
    $apiVariables = $this->mail->sent[1]['variables'];

    $this->assertSame(array_keys($formVariables), array_keys($apiVariables));
    foreach (['title', 'name', 'start_date', 'contact', 'custom_body'] as $key) {
      $this->assertSame((string) $formVariables[$key], (string) $apiVariables[$key], $key);
    }
    $this->assertSame((string) $formVariables['email_title'], (string) $apiVariables['email_title']);
  }

  /**
   * With the real mail service, a message actually leaves for the registrant.
   */
  public function testRealSendReachesTheRegistrant(): void {
    $this->config('system.site')->set('mail', 'site@example.com')->save();
    $this->container->set('access_misc.symfony.mail', new SymfonyMail());
    $instance = $this->createAuthoredInstance();

    $this->commit($instance);

    $this->readMail();
    $this->assertTo('owner@example.com', 'owner');
    $this->assertNoError();
  }

}
