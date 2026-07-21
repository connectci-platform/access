<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Controller\EventDetailApiController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field_inheritance\Entity\FieldInheritance;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\recurring_events_registration\Entity\RegistrantType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared kernel-test scaffolding for the access_events registration routes.
 *
 * Provides the module list, entity-schema/config install, two seeded users,
 * and the registrable/non-registrable instance + registrant helpers that both
 * RegistrationStateTest (A1) and EventDetailApiControllerTest (A2) rely on.
 */
abstract class EventKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
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
  ];

  /**
   * The acting user.
   */
  protected User $owner;

  /**
   * A user unrelated to any registration.
   */
  protected User $stranger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('eventseries');
    $this->installEntitySchema('eventinstance');
    $this->installEntitySchema('registrant');
    $this->installEntitySchema('taxonomy_term');
    // installConfig(['recurring_events']) imports the two default
    // field_inheritance config entities (eventinstance_default_title and
    // eventinstance_default_description), whose source fields (title, body) are
    // eventseries BASE fields — so those two inherited detail fields resolve in
    // this minimal kernel env once the per-instance inheritance state is set
    // (see configureDefaultInheritances() in createRegistrableInstance()). The
    // remaining site detail fields (location/event_type/skill_level/speakers/
    // tags/registration) inherit from CONFIGURED eventseries fields that are
    // site-level (not shipped by the contrib module), so they are absent here
    // and the controller degrades them to null — asserted in A2.
    $this->installConfig(['field_inheritance', 'recurring_events']);

    // The computed inherited fields (title, description, …) are attached in
    // field_inheritance_entity_bundle_field_info_alter(), which reads the
    // field_inheritance config entities. Those entities were imported by the
    // installConfig() above, so the bundle field definitions cached during
    // schema install predate them — clear the cache so the computed fields are
    // (re)discovered and hasField('title') is TRUE.
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Only the "default" registrant bundle is needed. Installing the full
    // recurring_events_registration config also installs
    // recurring_events_registration.registrant.config with
    // email_notifications: true, which fires the registration-notification mail
    // pipeline on every registrant save — unwanted machinery unrelated to what
    // is under test. recurring_events_registration_install() auto-creates a
    // "default" registrant_type for each eventseries_type, so it may already
    // exist by the time setUp() runs.
    if (!RegistrantType::load('default')) {
      RegistrantType::create([
        'id' => 'default',
        'label' => 'Default',
      ])->save();
    }

    $this->owner = User::create([
      'name' => 'owner',
      'mail' => 'owner@example.com',
      'status' => 1,
    ]);
    $this->owner->save();

    $this->stranger = User::create([
      'name' => 'stranger',
      'mail' => 'stranger@example.com',
      'status' => 1,
    ]);
    $this->stranger->save();
  }

  /**
   * Creates a registrable eventseries + eventinstance and returns the instance.
   *
   * The series' event_registration base field is populated so the contrib
   * RegistrationCreationService reports the instance as registrable:
   *  - registration = 1 (enabled)
   *  - registration_type = 'instance' (all enabled ACCESS series use this)
   *  - registration_dates = 'open' (window is now → instance start), so a
   *    future instance is open and a past instance is closed.
   *
   * The series title/body base fields are seeded, and per-instance field
   * inheritance state is configured, so the inherited detail fields (title,
   * description) resolve non-empty for the A2 detail assertions.
   *
   * @param int $capacity
   *   Seat capacity.
   * @param bool $waitlist
   *   Whether the waitlist is enabled.
   * @param bool $pastDate
   *   When TRUE, the instance date is in the past; with registration_dates =
   *   'open' the window is now → instance start, so a past instance is closed
   *   and registrationIsOpen() returns FALSE (A3 registration_closed case).
   * @param string[] $permittedRoles
   *   Role machine names permitted to register. Empty = open to all. The
   *   contrib stores this as the comma-delimited event_registration
   *   ->permitted_roles string and registrationPermittedRoles() splits it back
   *   into an array (A3 not_permitted / permitted cases).
   */
  protected function createRegistrableInstance(int $capacity = 60, bool $waitlist = FALSE, bool $pastDate = FALSE, array $permittedRoles = []): EventInstance {
    $date = $pastDate
      ? ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00']
      : ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'];

    $registration = [
      'registration' => 1,
      'registration_type' => 'instance',
      'registration_dates' => 'open',
      'capacity' => $capacity,
      'waitlist' => $waitlist ? 1 : 0,
    ];
    if ($permittedRoles) {
      $registration['permitted_roles'] = implode(',', $permittedRoles);
    }

    $series = EventSeries::create([
      'title' => 'Registrable Event',
      'body' => 'The full event description.',
      'recur_type' => 'custom',
      'type' => 'default',
      'event_registration' => $registration,
    ]);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => $date,
    ]);
    $instance->save();

    // Populate the field_inheritance keyValue state mapping this instance's
    // uuid to its source series, so the computed inherited fields (title,
    // description) resolve. Normally recurring_events does this when the series
    // insert hook auto-creates instances; here the instance is created directly,
    // so configure it explicitly.
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, (int) $series->id());

    return $instance;
  }

  /**
   * Creates an eventseries + eventinstance with registration disabled.
   */
  protected function createNonRegistrableInstance(): EventInstance {
    $series = EventSeries::create([
      'title' => 'Non-Registrable Event',
      'body' => 'A non-registrable event.',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();

    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, (int) $series->id());

    return $instance;
  }

  /**
   * Creates an instance whose series carries a registration LINK and TAGS.
   *
   * Seeds the eventseries `field_registration` (link, WITH title text so the
   * generic-string-reader mangle is exercised) and `field_tags` (multi-value
   * taxonomy reference), wires the `eventinstance_default_registration` and
   * `eventinstance_default_tags` field-inheritance config entities (these are
   * site-level, not shipped by the contrib module, so they are created here),
   * and returns the instance. The controller reads the INSTANCE computed fields
   * `registration` / `tags`, which inherit from these SERIES source fields.
   *
   * @param string $linkUri
   *   The registration link URI.
   * @param string $linkTitle
   *   The registration link title text (the mangle trigger).
   * @param string[] $tagNames
   *   Taxonomy term names to seed and reference.
   */
  protected function createInstanceWithLinkAndTags(string $linkUri, string $linkTitle, array $tagNames): EventInstance {
    // Source fields on eventseries: field_registration (link) + field_tags
    // (entity_reference → taxonomy_term, multi-value), mirroring the site.
    if (!FieldStorageConfig::loadByName('eventseries', 'field_registration')) {
      FieldStorageConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_registration',
        'type' => 'link',
      ])->save();
      FieldConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_registration',
        'bundle' => 'default',
        'label' => 'Registration',
      ])->save();
    }
    if (!FieldStorageConfig::loadByName('eventseries', 'field_tags')) {
      FieldStorageConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_tags',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_tags',
        'bundle' => 'default',
        'label' => 'Tags',
      ])->save();
    }

    // Inheritance config: registration (link, fallback) + tags
    // (entity_reference plugin, inherit). Destination computed fields are
    // `registration` and `tags` (id minus the eventinstance_default_ prefix).
    if (!FieldInheritance::load('eventinstance_default_registration')) {
      // Use `inherit` (not the site's `fallback`) so no destination field is
      // required on the eventinstance — the minimal kernel env has none. The
      // reader-under-test (link ->uri) is identical either way; only the
      // source→instance flow differs, and inherit is sufficient here.
      FieldInheritance::create([
        'id' => 'eventinstance_default_registration',
        'label' => 'Registration',
        'type' => 'inherit',
        'sourceEntityType' => 'eventseries',
        'sourceEntityBundle' => 'default',
        'sourceField' => 'field_registration',
        'destinationEntityType' => 'eventinstance',
        'destinationEntityBundle' => 'default',
        'destinationField' => '',
        'plugin' => 'default_inheritance',
      ])->save();
    }
    if (!FieldInheritance::load('eventinstance_default_tags')) {
      FieldInheritance::create([
        'id' => 'eventinstance_default_tags',
        'label' => 'Tags',
        'type' => 'inherit',
        'sourceEntityType' => 'eventseries',
        'sourceEntityBundle' => 'default',
        'sourceField' => 'field_tags',
        'destinationEntityType' => 'eventinstance',
        'destinationEntityBundle' => 'default',
        'destinationField' => '',
        'plugin' => 'entity_reference_inheritance',
      ])->save();
    }

    // Rediscover the newly-attached computed fields (registration, tags).
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    $vocab = Vocabulary::load('tags');
    if (!$vocab) {
      $vocab = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
      $vocab->save();
    }
    $tagRefs = [];
    foreach ($tagNames as $name) {
      $term = Term::create(['vid' => 'tags', 'name' => $name]);
      $term->save();
      $tagRefs[] = ['target_id' => $term->id()];
    }

    $series = EventSeries::create([
      'title' => 'Linked Event',
      'body' => 'An event with a registration link and tags.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_registration' => ['uri' => $linkUri, 'title' => $linkTitle],
      'field_tags' => $tagRefs,
    ]);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();

    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, (int) $series->id());

    return $instance;
  }

  /**
   * Creates an instance whose series carries list_string event_type/skill_level.
   *
   * Seeds the eventseries `field_event_type` and `field_skill_level` as
   * `list_string` (option) fields whose allowed_values map a stored KEY to a
   * human LABEL — mirroring the site config where event_type maps the internal
   * sort-hack key `zz_other` to the label `Other`. Wires the matching
   * `eventinstance_default_event_type` / `eventinstance_default_skill_level`
   * field-inheritance config entities (site-level, not shipped by the contrib
   * module) so the controller-read INSTANCE computed fields inherit them. The
   * seeded values are the KEYS; the controller must emit the LABELS.
   *
   * @param string $eventTypeKey
   *   The stored event_type option key (e.g. 'zz_other').
   * @param string $skillLevelKey
   *   The stored skill_level option key (e.g. 'Beginner').
   */
  protected function createInstanceWithOptionFields(string $eventTypeKey, string $skillLevelKey): EventInstance {
    // Source list_string fields on eventseries with a KEY→LABEL allowed_values
    // map. event_type deliberately maps the internal 'zz_other' key to 'Other'.
    if (!FieldStorageConfig::loadByName('eventseries', 'field_event_type')) {
      FieldStorageConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_event_type',
        'type' => 'list_string',
        'settings' => [
          'allowed_values' => [
            'Conference' => 'Conference',
            'Training' => 'Training',
            'Office Hours' => 'Office Hours',
            'zz_other' => 'Other',
          ],
        ],
      ])->save();
      FieldConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_event_type',
        'bundle' => 'default',
        'label' => 'Event Type',
      ])->save();
    }
    if (!FieldStorageConfig::loadByName('eventseries', 'field_skill_level')) {
      FieldStorageConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_skill_level',
        'type' => 'list_string',
        'settings' => [
          'allowed_values' => [
            'Beginner' => 'Beginner',
            'Intermediate' => 'Intermediate',
            'Advanced' => 'Advanced',
          ],
        ],
      ])->save();
      FieldConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_skill_level',
        'bundle' => 'default',
        'label' => 'Skill Level',
      ])->save();
    }

    // Inheritance config: event_type + skill_level. Destination computed fields
    // are `event_type` and `skill_level` (id minus the eventinstance_default_
    // prefix). `inherit` needs no destination field on the eventinstance.
    if (!FieldInheritance::load('eventinstance_default_event_type')) {
      FieldInheritance::create([
        'id' => 'eventinstance_default_event_type',
        'label' => 'Event Type',
        'type' => 'inherit',
        'sourceEntityType' => 'eventseries',
        'sourceEntityBundle' => 'default',
        'sourceField' => 'field_event_type',
        'destinationEntityType' => 'eventinstance',
        'destinationEntityBundle' => 'default',
        'destinationField' => '',
        'plugin' => 'default_inheritance',
      ])->save();
    }
    if (!FieldInheritance::load('eventinstance_default_skill_level')) {
      FieldInheritance::create([
        'id' => 'eventinstance_default_skill_level',
        'label' => 'Skill Level',
        'type' => 'inherit',
        'sourceEntityType' => 'eventseries',
        'sourceEntityBundle' => 'default',
        'sourceField' => 'field_skill_level',
        'destinationEntityType' => 'eventinstance',
        'destinationEntityBundle' => 'default',
        'destinationField' => '',
        'plugin' => 'default_inheritance',
      ])->save();
    }

    // Rediscover the newly-attached computed fields (event_type, skill_level).
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    $series = EventSeries::create([
      'title' => 'Option-Field Event',
      'body' => 'An event carrying list_string option fields.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_event_type' => $eventTypeKey,
      'field_skill_level' => $skillLevelKey,
    ]);
    $series->save();

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();

    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, (int) $series->id());

    return $instance;
  }

  /**
   * Registers a user for an instance and returns the saved registrant.
   */
  protected function registerUser(User $user, EventInstance $instance, bool $waitlist = FALSE): Registrant {
    $registrant = Registrant::create([
      'user_id' => $user->id(),
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'email' => $user->getEmail(),
      'waitlist' => $waitlist ? 1 : 0,
      'type' => 'default',
    ]);
    $registrant->save();
    return $registrant;
  }

  /**
   * Counts registrants attached to an instance.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $instance
   *   The event instance.
   *
   * @return int
   *   The number of registrant entities referencing the instance.
   */
  protected function countRegistrants(EventInstance $instance): int {
    $ids = \Drupal::entityTypeManager()->getStorage('registrant')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('eventinstance_id', $instance->id())
      ->execute();
    return count($ids);
  }

  /**
   * Invokes EventDetailApiController::register() as an acting user.
   *
   * Builds a POST Request carrying the JSON body and the
   * rp_account_effective_uid attribute the RpAccountAccess gate would set, then
   * calls the controller method directly (the gate is covered separately in
   * A4). This mirrors A2's direct-controller invocation.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $instance
   *   The event instance to register for.
   * @param \Drupal\user\Entity\User $actingUser
   *   The acting user whose uid is bound to rp_account_effective_uid.
   * @param array $body
   *   The decoded JSON body (e.g. ['confirmed' => TRUE]); [] = preview.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The controller's response.
   */
  protected function doRegister(EventInstance $instance, User $actingUser, array $body): JsonResponse {
    $request = Request::create(
      '/api/1.0/events/' . $instance->id() . '/register',
      'POST',
      [],
      [],
      [],
      [],
      json_encode($body),
    );
    $request->attributes->set('rp_account_effective_uid', (int) $actingUser->id());
    \Drupal::requestStack()->push($request);

    return EventDetailApiController::create(\Drupal::getContainer())
      ->register($instance, $request);
  }

}
