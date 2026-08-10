<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Controller\EventDetailApiController;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field_inheritance\Entity\FieldInheritance;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\recurring_events_registration\Entity\RegistrantType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\workflows\Entity\Workflow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared kernel-test scaffolding for the access_events registration routes.
 *
 * Provides the module list, entity-schema/config install, two seeded users,
 * and the registrable/non-registrable instance + registrant helpers that both
 * RegistrationStateTest (A1) and EventDetailApiControllerTest (A2) rely on.
 * Also provides the moderation + node + coordinator scaffolding (workflows,
 * an `affinity_group` node type with `field_coordinator`, and coordinator
 * series/instance builders) that the upcoming event-CRUD endpoint tests need.
 */
abstract class EventKernelTestBase extends KernelTestBase {

  use UserCreationTrait;
  use ContentModerationTestTrait;

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

    // Fixture users implicitly have the authenticated role; install its config
    // + grant the prod permissions so ->access()/createAccess() evaluate as in
    // production (otherwise every positive owner path is DENIED). Runs after the
    // user entity schema install above so the authenticated role config resolves.
    $this->installConfig(['user']);
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      ['add registrant entities', 'delete own registrant entities'],
    );

    // --- Moderation + node + coordinator scaffolding -----------------------
    //
    // The two content-moderation workflows below (editorial, editorial_
    // eventinstance) are hand-maintained site config, not shipped in any
    // module's config/install|optional — access_events itself ships no config
    // at all. So there is nothing to installConfig() here; build the same
    // workflow shape ContentModerationTestTrait::createEditorialWorkflow()
    // builds (as the announcement test does) and attach it to the eventseries
    // "default" bundle, then build a second workflow keyed
    // 'editorial_eventinstance' and attach it to the eventinstance "default"
    // bundle. This mirrors the two states/transitions the site's real
    // workflows.workflow.editorial(_eventinstance) configs define (draft,
    // published, archived + create_new_draft/publish/archive), which is all
    // the endpoint tests need.
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);

    $seriesWorkflow = $this->createEditorialWorkflow();
    // The stock createEditorialWorkflow() ships only draft/published/archived
    // and no review step. The live editorial workflow adds a
    // ready_for_review state reached by a send_for_review transition (from
    // draft), which is exactly the review-needed signal the event-CRUD write
    // envelope reports: a draft author who lacks publish still holds
    // send_for_review. Add that state + transition so getValidTransitions()
    // resolves as it does in production. Kept minimal — the site's
    // needs_adjustment/request_adjustment/review_to_review branches are not
    // needed by the write endpoints.
    $seriesTypePlugin = $seriesWorkflow->getTypePlugin();
    $seriesTypePlugin->addState('ready_for_review', 'Ready for Review');
    $seriesConfig = $seriesTypePlugin->getConfiguration();
    $seriesConfig['states']['ready_for_review']['published'] = FALSE;
    $seriesConfig['states']['ready_for_review']['default_revision'] = FALSE;
    $seriesConfig['transitions']['send_for_review'] = [
      'label' => 'Send for Review',
      // Matches live config: send_for_review is valid from BOTH draft and
      // needs_adjustment. Omitting needs_adjustment would hide that arm from
      // tests AND make isTransitionValid throw for a needs_adjustment series in
      // the kernel env (the controller's source-state guard admits it), whereas
      // production allows it.
      'from' => ['draft', 'needs_adjustment'],
      'to' => 'ready_for_review',
      'weight' => 5,
    ];
    // The live editorial workflow also has a needs_adjustment state (a
    // DEFAULT-revision unpublished state) reached by request_adjustment from
    // published. A series can therefore be published once, then moved to
    // needs_adjustment, which is the current DEFAULT state — with a published
    // revision still in history. There is no needs_adjustment → archived
    // transition, so the delete endpoint must NOT throw when it sees such a
    // series (see testDeleteWasPublishedNowNeedsAdjustment*). Kept minimal to
    // just the state + the published → needs_adjustment transition.
    $seriesConfig['states']['needs_adjustment'] = [
      'label' => 'Needs Adjustment',
      'published' => FALSE,
      'default_revision' => TRUE,
      'weight' => 6,
    ];
    $seriesConfig['transitions']['request_adjustment'] = [
      'label' => 'Request Adjustment',
      'from' => ['published'],
      'to' => 'needs_adjustment',
      'weight' => 6,
    ];
    $seriesTypePlugin->setConfiguration($seriesConfig);
    $seriesWorkflow->getTypePlugin()->addEntityTypeAndBundle('eventseries', 'default');
    $seriesWorkflow->save();

    if (!Workflow::load('editorial_eventinstance')) {
      $instanceWorkflow = Workflow::create([
        'type' => 'content_moderation',
        'id' => 'editorial_eventinstance',
        'label' => 'Editorial Workflow for Event Instances',
        'type_settings' => [
          'states' => [
            'archived' => [
              'label' => 'Archived',
              'weight' => 5,
              'published' => FALSE,
              'default_revision' => TRUE,
            ],
            'draft' => [
              'label' => 'Draft',
              'published' => FALSE,
              'default_revision' => FALSE,
              'weight' => -5,
            ],
            // The live editorial_eventinstance workflow has a needs_adjustment
            // state reached by request_adjustment from ready_for_review. An
            // instance can therefore be sent for review and then bounced back
            // to needs_adjustment, which becomes the current state — with a
            // published revision still in history. There is no
            // needs_adjustment → archived transition, so the cancel-occurrence
            // endpoint must NOT throw when it sees such an instance; it
            // refuses invalid_state instead (see
            // testCancelDraftOccurrenceRefusesInvalidStateNo500, which drives
            // the instance to this genuinely non-published state — draft is a
            // forward revision here (default_revision FALSE), so it never
            // becomes the current state on its own).
            //
            // default_revision is FALSE: an instance flagged
            // individually_cancelled and moved to needs_adjustment must not
            // overwrite the default (published or archived) revision the
            // flag's coherence rule depends on.
            'needs_adjustment' => [
              'label' => 'Needs Adjustment',
              'published' => FALSE,
              'default_revision' => FALSE,
              'weight' => 7,
            ],
            'published' => [
              'label' => 'Published',
              'published' => TRUE,
              'default_revision' => TRUE,
              'weight' => 0,
            ],
            // The kernel instance workflow otherwise lacks the review arm the
            // live site has (an instance moving through draft → review before
            // publish, mirroring the series workflow).
            'ready_for_review' => [
              'label' => 'Ready for Review',
              'published' => FALSE,
              'default_revision' => FALSE,
              'weight' => 6,
            ],
          ],
          'transitions' => [
            'archive' => [
              'label' => 'Archive',
              'from' => ['published'],
              'to' => 'archived',
              'weight' => 2,
            ],
            // A restored (published→archived) occurrence can be re-cancelled
            // without leaving the archived state, so an already-archived
            // occurrence stays representable as its own current revision.
            'archived_archived' => [
              'label' => 'Archived',
              'from' => ['archived'],
              'to' => 'archived',
              'weight' => 2,
            ],
            'request_adjustment' => [
              'label' => 'Request Adjustment',
              'from' => ['ready_for_review'],
              'to' => 'needs_adjustment',
              'weight' => 6,
            ],
            'archived_draft' => [
              // Matches production (workflows.workflow.editorial_eventinstance.yml):
              // valid from BOTH archived and needs_adjustment. The needs_
              // adjustment arm is what the standalone moderation form's
              // reorder-a-non-publishing-option-first treatment targets — a
              // needs_adjustment instance's ONLY lower-weight-than-publish
              // alternative is this transition, so it must be the one the
              // form_alter promotes ahead of Published in #options.
              'label' => 'Restore to Draft',
              'from' => ['archived', 'needs_adjustment'],
              'to' => 'draft',
              'weight' => 3,
            ],
            'archived_published' => [
              'label' => 'Restore',
              'from' => ['archived'],
              'to' => 'published',
              'weight' => 4,
            ],
            'create_new_draft' => [
              'label' => 'Create New Draft',
              'to' => 'draft',
              'weight' => 0,
              'from' => ['draft', 'published'],
            ],
            'publish' => [
              'label' => 'Publish',
              'to' => 'published',
              'weight' => 1,
              'from' => ['draft', 'needs_adjustment', 'published', 'ready_for_review'],
            ],
            'send_for_review' => [
              'label' => 'Send for Review',
              'from' => ['draft', 'needs_adjustment'],
              'to' => 'ready_for_review',
              'weight' => 5,
            ],
            'review_to_review' => [
              'label' => 'Review to Review',
              'from' => ['ready_for_review'],
              'to' => 'ready_for_review',
              'weight' => 7,
            ],
          ],
        ],
      ]);
      $instanceWorkflow->getTypePlugin()->addEntityTypeAndBundle('eventinstance', 'default');
      $instanceWorkflow->save();
    }

    // Rediscover moderation_state on eventseries/eventinstance now that both
    // workflows are attached.
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // The affinity_group node type + field_coordinator (entity_reference →
    // user, multi-value) — mirrors AnnouncementApiControllerTest::setUp().
    NodeType::create([
      'type' => 'affinity_group',
      'name' => 'Affinity Group',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();

    Role::create(['id' => 'news_pm', 'label' => 'News PM'])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();

    // access_affinitygroup_entity_presave() fires whenever an affinity_group
    // node is saved (see createAffinityGroupNode() below, which always
    // saves). It is orthogonal to what the event-CRUD endpoints test, but it
    // reads a handful of fields and needs the affinity_group_leader role +
    // the 'affinity_groups' vocab to complete without erroring — the same
    // fixture AnnouncementApiControllerTest seeds for the same reason. CC
    // calls are disabled by default (isCCEnabled() → FALSE), so the hook
    // returns before its Constant Contact work.
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();

    // Seed the event entity + moderation-transition permissions each role holds
    // on the LIVE site (user.role.*.yml), so $series->access($op) and the
    // content_moderation transition gates resolve exactly as they do in
    // production. Kept faithful to config: news_pm is the events editor; a
    // plain author (authenticated) may draft and send for review but not
    // publish or archive; an affinity_group_leader may publish a series.
    // news_pm — edits/deletes events + the full transition set on both workflows.
    user_role_grant_permissions('news_pm', [
      'edit eventseries entity',
      'delete eventseries entity',
      'edit eventinstance entity',
      'delete eventinstance entity',
      'use editorial transition archive',
      'use editorial transition archived_draft',
      'use editorial transition archived_published',
      'use editorial transition create_new_draft',
      'use editorial transition publish',
      'use editorial transition request_adjustment',
      'use editorial_eventinstance transition archive',
      'use editorial_eventinstance transition archived_archived',
      'use editorial_eventinstance transition archived_draft',
      'use editorial_eventinstance transition archived_published',
      'use editorial_eventinstance transition create_new_draft',
      'use editorial_eventinstance transition publish',
      'use editorial_eventinstance transition request_adjustment',
    ]);
    // affinity_group_leader — series publish only; nothing on the instance workflow.
    user_role_grant_permissions('affinity_group_leader', [
      'use editorial transition publish',
    ]);
    // authenticated — every logged-in author. Draft + send-for-review, NOT
    // publish, NOT archive, on the series workflow; publish on the instance
    // workflow (present in real config, unused by the write tools here).
    user_role_grant_permissions(AccountInterface::AUTHENTICATED_ROLE, [
      'use editorial transition archived_draft',
      'use editorial transition create_new_draft',
      'use editorial transition review_to_review',
      'use editorial transition send_for_review',
      'use editorial_eventinstance transition publish',
      'use editorial_eventinstance transition review_to_review',
      'use editorial_eventinstance transition send_for_review',
    ]);

    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();
    foreach (['field_group_slug'] as $stringField) {
      FieldStorageConfig::create([
        'field_name' => $stringField,
        'entity_type' => 'node',
        'type' => 'string',
        'cardinality' => 1,
      ])->save();
      FieldConfig::create([
        'field_name' => $stringField,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_use_ext_email_list',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_use_ext_email_list',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
    foreach (['field_ext_email_list', 'field_list_id'] as $agString) {
      FieldStorageConfig::create([
        'field_name' => $agString,
        'entity_type' => 'node',
        'type' => 'string',
        'cardinality' => 1,
      ])->save();
      FieldConfig::create([
        'field_name' => $agString,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    // field_affinity_group_node (on eventseries, ref node → affinity_group) —
    // the coordinator series helpers below use this to tie a series to the
    // affinity group whose field_coordinator authorizes edits to it. Mirrors
    // the site's real eventseries.default.field_affinity_group_node config
    // field, same pattern already used for field_registration/field_tags
    // elsewhere in this base.
    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group_node',
      'entity_type' => 'eventseries',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_affinity_group_node',
      'entity_type' => 'eventseries',
      'bundle' => 'default',
      'settings' => ['handler_settings' => ['target_bundles' => ['affinity_group' => 'affinity_group']]],
    ])->save();

    // access_events is enabled by default in this base, so
    // access_events_entity_presave() (reads domain_access, post_survey_url,
    // field_post_survey_*) and access_events_entity_access() (reads
    // field_other_authors, unconditionally, no hasField guard) fire on every
    // eventseries/eventinstance save from here on. Seed the site-level fields
    // those hooks touch, empty, so their reads return empty and their
    // conditional blocks are skipped rather than fataling.
    $this->attachInstancePresaveFields();
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
  }

  /**
   * Attaches the empty site-level fields access_events_entity_presave() reads.
   */
  protected function attachInstancePresaveFields(): void {
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
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Runs $callable with the account switched to $user (mirrors the subscriber).
   */
  protected function asActingUser($user, callable $callable) {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($user);
    try {
      return $callable();
    }
    finally {
      $switcher->switchBack();
    }
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
    $this->publishModerated($series);

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => $date,
    ]);
    $instance->save();
    $this->publishModerated($instance);

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
    $this->publishModerated($series);

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();
    $this->publishModerated($instance);

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
    $this->publishModerated($series);

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();
    $this->publishModerated($instance);

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
    $this->publishModerated($series);

    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-01-01T10:00:00',
        'end_value' => '2999-01-01T12:00:00',
      ],
    ]);
    $instance->save();
    $this->publishModerated($instance);

    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($instance, (int) $series->id());

    return $instance;
  }

  /**
   * Publishes a moderated eventseries/eventinstance, when applicable.
   *
   * The pre-existing instance/series builders in this base predate
   * content_moderation being installed and, by convention, produce
   * PUBLISHED entities (several existing tests rely on that default). Now
   * that the editorial/editorial_eventinstance workflows are attached to
   * eventseries.default/eventinstance.default, a freshly-created moderated
   * entity computes moderation_state to the workflow's default state
   * (draft) even with no explicit value, which drives status back to
   * unpublished on save. Call this right after ->save() to restore the
   * pre-existing "these builders yield published entities" contract.
   */
  protected function publishModerated(EntityInterface $entity): void {
    if ($entity->hasField('moderation_state')) {
      $entity->set('moderation_state', 'published')->save();
    }
  }

  /**
   * Counts queued email_notifications items carrying the given notification key.
   */
  protected function assertQueueCount(string $key, int $expected): void {
    // The real queue id and stdClass item shape, per contrib
    // NotificationService::addEmailNotificationToQueue().
    $queue = \Drupal::queue('recurring_events_registration_email_notifications_queue_worker');
    $found = 0;
    $items = [];
    while ($item = $queue->claimItem()) {
      $items[] = $item;
      if (($item->data->key ?? NULL) === $key) {
        $found++;
      }
    }
    foreach ($items as $item) {
      $queue->releaseItem($item);
    }
    $this->assertSame($expected, $found, "queue items for $key");
  }

  protected function reloadInstance(EventInstance $instance): EventInstance {
    return \Drupal::entityTypeManager()->getStorage('eventinstance')->loadUnchanged($instance->id());
  }

  /**
   * Enables the notification machinery the kernel env leaves unset.
   */
  protected function enableEventNotifications(): void {
    // subject/body must be set (not just enabled): NotificationService::
    // getConfigValue() reads $notifications[$key][$name] with no isset()
    // guard, so a missing key -- not just an empty one -- is a PHP warning
    // that kernel tests promote to a fatal.
    \Drupal::configFactory()->getEditable('recurring_events_registration.registrant.config')
      ->set('email_notifications', TRUE)
      ->set('email_notifications_queue', TRUE)
      ->set('notifications.event_cancelled_notification.enabled', TRUE)
      ->set('notifications.event_cancelled_notification.subject', 'Event cancelled')
      ->set('notifications.event_cancelled_notification.body', 'The event has been cancelled.')
      ->set('notifications.event_reinstated_notification.enabled', TRUE)
      ->set('notifications.event_reinstated_notification.subject', 'Event reinstated')
      ->set('notifications.event_reinstated_notification.body', 'The event is back on.')
      ->set('notifications.instance_modification_notification.enabled', TRUE)
      ->set('notifications.instance_modification_notification.subject', 'Event modified')
      ->set('notifications.instance_modification_notification.body', 'The event has been modified.')
      ->save();
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
   * Registers a user against an instance that is NOT currently published,
   * modeling legacy registration data that predates the registrant-presave
   * publish gate (e.g. a registrant who signed up while the occurrence was
   * briefly live, before it was pulled back to draft/archived).
   *
   * The gate only refuses a NEW registrant save against a non-published
   * instance — it does not (and must not) reach back and invalidate
   * registrants that already exist, so this models that legitimate data
   * shape by publishing the instance via a SYNCING save (bypassing the
   * gate, exactly like a revert/rebuild would), registering, then restoring
   * the instance to its original moderation_state via another syncing save.
   * Several delete/cancel-guard tests need a registrant sitting on a
   * currently-non-published instance to prove the guard itself (not the
   * registrant-creation gate) is what's under test.
   */
  protected function registerUserOnDraftInstance(User $user, EventInstance $instance, bool $waitlist = FALSE): Registrant {
    $originalState = $instance->hasField('moderation_state') ? $instance->get('moderation_state')->value : NULL;

    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'published');
    $instance->save();

    $registrant = $this->registerUser($user, $instance, $waitlist);

    $instance = $this->reloadInstance($instance);
    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', $originalState);
    $instance->save();

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
   * acting_user_uid attribute the ActingUserAccess gate would set, then
   * calls the controller method directly (the gate is covered separately in
   * A4). This mirrors A2's direct-controller invocation.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $instance
   *   The event instance to register for.
   * @param \Drupal\user\Entity\User $actingUser
   *   The acting user whose uid is bound to acting_user_uid.
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
    $request->attributes->set('acting_user_uid', (int) $actingUser->id());
    // The contrib RegistrantAccessControlHandler::checkCreateAccess reads the
    // `eventinstance` request attribute (the route's param converter sets it in
    // prod; a direct controller call must set it here) to resolve the instance
    // and confirm registration is enabled.
    $request->attributes->set('eventinstance', $instance);
    \Drupal::requestStack()->push($request);

    return EventDetailApiController::create(\Drupal::getContainer())
      ->register($instance, $request);
  }

  /**
   * Creates a SAVED affinity_group node coordinated by the given user ids.
   *
   * @param int[] $coordinatorUids
   *   User ids to place in field_coordinator.
   */
  protected function createAffinityGroupNode(array $coordinatorUids): NodeInterface {
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Coordinator Group',
      'field_coordinator' => $coordinatorUids,
      // Read by the access_affinitygroup entity_presave fixture.
      'field_group_slug' => 'coordinator-group',
      'field_use_ext_email_list' => 0,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates a CUSTOM, DRAFT eventseries coordinated by $c (via its group).
   *
   * The series' field_affinity_group_node references an affinity_group node
   * coordinated by $c, so the coordinator-authorization checks the upcoming
   * endpoints run against it resolve TRUE for $c.
   */
  protected function makeCoordinatorSeries(User $c): EventSeries {
    $group = $this->createAffinityGroupNode([(int) $c->id()]);

    $series = EventSeries::create([
      'title' => 'Coordinator Event',
      'body' => 'A coordinator-owned event.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
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

    return $series;
  }

  /**
   * Like makeCoordinatorSeries(), but published.
   *
   * The series AND its instance are transitioned to moderation_state =
   * published.
   */
  protected function makePublishedCoordinatorSeries(User $c): EventSeries {
    $series = $this->makeCoordinatorSeries($c);
    $series->set('moderation_state', 'published')->save();

    foreach ($this->loadInstances($series) as $instance) {
      $instance->set('moderation_state', 'published')->save();
    }

    return $series;
  }

  /**
   * Like makePublishedCoordinatorSeries(), but two instances.
   */
  protected function makePublishedCoordinatorSeriesWithTwoInstances(User $c): EventSeries {
    $series = $this->makePublishedCoordinatorSeries($c);

    $second = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => [
        'value' => '2999-02-01T10:00:00',
        'end_value' => '2999-02-01T12:00:00',
      ],
    ]);
    $second->save();
    \Drupal::service('recurring_events.event_creation_service')
      ->configureDefaultInheritances($second, (int) $series->id());
    $second->set('moderation_state', 'published')->save();

    return $series;
  }

  /**
   * A PUBLISHED custom series seeded with one custom_date, coordinated by $c.
   *
   * Unlike makeCoordinatorSeries() (which hand-builds one instance and leaves
   * custom_date empty), this seeds the singular custom_date daterange field so
   * the recurring_events insert hook OWNS the instance it spawns. That matters
   * for the add_occurrence path: appending a second custom_date to a PUBLISHED
   * custom series is a recur-config change, so on save the module's
   * RecreateEventInstanceCreator regenerates instances from the full
   * custom_dates set — the durable, module-owned append the endpoint relies on.
   * The series and its spawned instance(s) are transitioned to published.
   */
  protected function makePublishedCustomSeriesWithDate(User $c): EventSeries {
    $group = $this->createAffinityGroupNode([(int) $c->id()]);

    $series = EventSeries::create([
      'title' => 'Coordinator Custom Event',
      'body' => 'A published custom coordinator-owned event.',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
      'custom_date' => [
        ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ],
    ]);
    // The insert hook spawns one instance from the seeded custom_date.
    $series->save();
    $series->set('moderation_state', 'published')->save();

    foreach ($this->loadInstances($series) as $instance) {
      $instance->set('moderation_state', 'published')->save();
    }

    return $series;
  }

  /**
   * A rule-based series coordinated by $c, via its affinity_group.
   *
   * Recur_type = weekly_recurring_date.
   */
  protected function makeCoordinatorRuleSeries(User $c): EventSeries {
    $group = $this->createAffinityGroupNode([(int) $c->id()]);

    // A rule series' insert hook calculates instances, which formats times via
    // the core html_time date_format (and html_date). Those config entities ship
    // in system's config/install but are not present in this minimal kernel env,
    // so create the ones the weekly-rule instance pipeline needs to avoid a
    // getPattern()-on-null during save. custom/rule-refusal aside, this keeps a
    // rule series constructible at all in the test env.
    foreach ([
      'html_time' => 'H:i:s',
      'html_date' => 'Y-m-d',
    ] as $formatId => $pattern) {
      if (!\Drupal::entityTypeManager()->getStorage('date_format')->load($formatId)) {
        \Drupal::entityTypeManager()->getStorage('date_format')->create([
          'id' => $formatId,
          'label' => $formatId,
          'locked' => TRUE,
          'pattern' => $pattern,
        ])->save();
      }
    }

    $series = EventSeries::create([
      'title' => 'Coordinator Recurring Event',
      'body' => 'A rule-based coordinator-owned event.',
      'recur_type' => 'weekly_recurring_date',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
      // Seed a VALID weekly rule so the series insert hook's calculateInstances()
      // has the days/time/duration it iterates (an empty weekly_recurring_date
      // makes WeeklyRecurringDate::calculateInstances() foreach over NULL and the
      // save throws). A one-week bounded window keeps the spawned instance count
      // small; the exact instances do not matter to the rule-refusal test — only
      // that the series exists as a rule series.
      'weekly_recurring_date' => [
        'value' => '2999-01-04T00:00:00',
        'end_value' => '2999-01-10T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
        'days' => 'monday,wednesday',
      ],
    ]);
    $series->save();

    return $series;
  }

  /**
   * Loads all eventinstance entities belonging to $series.
   *
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   *   The series' event instances.
   */
  protected function loadInstances(EventSeries $series): array {
    $ids = \Drupal::entityTypeManager()->getStorage('eventinstance')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('eventseries_id', $series->id())
      ->execute();
    return EventInstance::loadMultiple($ids);
  }

  /**
   * Maps a CRUD op name to its EventCrudApiController method name.
   *
   * This map is the single place that work binds against: doCrud()/
   * doOccurrence() below resolve the method off the live class by name at
   * call time, so calling a do*() helper before the controller exists (were
   * it ever removed) would fail with a clear "class not found" rather than
   * silently no-op'ing.
   *
   * @return array<string, string>
   *   Op name => EventCrudApiController method name.
   */
  protected function crudOpMethodMap(): array {
    return [
      // The create endpoint is createEvent(), not create(): ControllerBase's
      // static create(ContainerInterface) factory occupies the create() name,
      // so the instance endpoint cannot reuse it (mirrors
      // AnnouncementApiController::createAnnouncement).
      'create' => 'createEvent',
      'update' => 'update',
      'delete' => 'delete',
      'restore' => 'restore',
      'sendForReview' => 'sendForReview',
      // Instance-level (occurrence) op: cancel_occurrence archives one instance.
      'cancel' => 'cancelOccurrence',
      // Instance-level: restore_occurrence un-cancels one instance. Distinct
      // op key from the series-level 'restore' above (same method-map, two
      // different controller methods).
      'restoreOccurrence' => 'restoreOccurrence',
      // Instance-level: edit_occurrence changes one instance's date/location.
      'edit' => 'editOccurrence',
      // Series-level: add_occurrence directly creates one instance.
      'add' => 'addOccurrence',
    ];
  }

  /**
   * Dispatches a series create/update/delete op, acting as $actingUser.
   *
   * Builds a Request and sends it to EventCrudApiController. Models
   * doRegister().
   *
   * @param string $op
   *   One of the keys in crudOpMethodMap().
   * @param int|null $id
   *   The eventseries id being acted on; NULL for create.
   * @param \Drupal\user\Entity\User $actingUser
   *   The acting user whose uid is bound to acting_user_uid.
   * @param array $body
   *   The decoded JSON body.
   * @param array $query
   *   Query-string parameters.
   */
  protected function doCrud(string $op, ?int $id, User $actingUser, array $body, array $query = []): JsonResponse {
    $path = '/api/1.0/events' . ($id !== NULL ? '/' . $id : '');
    $method = $body || $op === 'create' ? 'POST' : 'GET';
    $request = Request::create($path, $method, $query, [], [], [], $body ? json_encode($body) : NULL);
    $request->attributes->set('acting_user_uid', (int) $actingUser->id());
    if ($id !== NULL) {
      $request->attributes->set('eventseries', EventSeries::load($id));
    }

    return $this->asActingUser(
      $actingUser,
      fn () => $this->dispatchCrud($op, $request, $id),
    );
  }

  /**
   * Dispatches an eventinstance (occurrence) op, acting as $actingUser.
   *
   * Builds a Request and sends it to EventCrudApiController. Models
   * doRegister().
   *
   * @param string $op
   *   The occurrence op name (e.g. 'publish', 'archive', 'update').
   * @param int $instanceId
   *   The eventinstance id being acted on.
   * @param \Drupal\user\Entity\User $actingUser
   *   The acting user whose uid is bound to acting_user_uid.
   * @param array $query
   *   Query-string parameters.
   * @param array $body
   *   The decoded JSON body.
   */
  protected function doOccurrence(string $op, int $id, User $actingUser, array $query = [], array $body = []): JsonResponse {
    // add_occurrence targets a SERIES (POST /api/2.3/event-series/{id}/
    // occurrence), not an instance: it directly creates a new instance on the
    // series. The edit/cancel/restoreOccurrence ops target an INSTANCE
    // (/api/2.3/event-occurrences/{id}[/restore]). Branch on the op so the
    // right entity attribute is bound and the right path is built. In every
    // branch the query params are baked into the URL (http_build_query)
    // rather than passed as Request::create()'s $parameters: for a non-GET
    // method that arg lands in the request (POST) bag, not ->query, so the
    // controller's $request->query->get('force'/'confirmed') would MISS it —
    // silently dropping the destructive force-gate.
    if ($op === 'add') {
      $path = '/api/2.3/event-series/' . $id . '/occurrence';
      if ($query) {
        $path .= '?' . http_build_query($query);
      }
      $request = Request::create($path, 'POST', [], [], [], [], $body ? json_encode($body) : NULL);
      $request->attributes->set('acting_user_uid', (int) $actingUser->id());
      $request->attributes->set('eventseries', EventSeries::load($id));

      return $this->asActingUser(
        $actingUser,
        fn () => $this->dispatchCrud($op, $request, $id),
      );
    }

    if ($op === 'restoreOccurrence') {
      $path = '/api/2.3/event-occurrences/' . $id . '/restore';
      if ($query) {
        $path .= '?' . http_build_query($query);
      }
      $request = Request::create($path, 'POST', [], [], [], [], $body ? json_encode($body) : NULL);
      $request->attributes->set('acting_user_uid', (int) $actingUser->id());
      $request->attributes->set('eventinstance', EventInstance::load($id));

      return $this->asActingUser(
        $actingUser,
        fn () => $this->dispatchCrud($op, $request, $id),
      );
    }

    // Instance-targeting ops (cancel = DELETE, edit = POST/PATCH-shaped).
    $path = '/api/2.3/event-occurrences/' . $id;
    if ($query) {
      $path .= '?' . http_build_query($query);
    }
    $request = Request::create($path, $body ? 'POST' : 'DELETE', [], [], [], [], $body ? json_encode($body) : NULL);
    $request->attributes->set('acting_user_uid', (int) $actingUser->id());
    $request->attributes->set('eventinstance', EventInstance::load($id));

    return $this->asActingUser(
      $actingUser,
      fn () => $this->dispatchCrud($op, $request, $id),
    );
  }

  /**
   * Resolves EventCrudApiController by name and invokes the mapped method.
   *
   * Deliberately late-bound (no `use` import, no compile-time reference) so
   * this base compiles before the controller exists; adding the class makes
   * these calls start working unchanged.
   */
  private function dispatchCrud(string $op, Request $request, ?int $id): JsonResponse {
    $controllerClass = 'Drupal\\access_events\\Controller\\EventCrudApiController';
    if (!class_exists($controllerClass)) {
      throw new \RuntimeException(sprintf(
        '%s does not exist yet; the do*() dispatch helpers are scaffolding for the event-CRUD write endpoints.',
        $controllerClass,
      ));
    }
    $method = $this->crudOpMethodMap()[$op] ?? $op;
    $controller = $controllerClass::create(\Drupal::getContainer());
    return $id === NULL ? $controller->$method($request) : $controller->$method($id, $request);
  }

}
