<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Controller\AnnouncementApiController;
use Drupal\access_misc\Plugin\Util\SiteTools;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the announcement (access_news) write endpoints + the coordinator helper.
 *
 * @group access_affinitygroup
 */
class AnnouncementApiControllerTest extends KernelTestBase {

  use UserCreationTrait;
  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'options',
    'datetime',
    'dblog',
    'text',
    'filter',
    'taxonomy',
    'workflows',
    'content_moderation',
    'access',
    'access_affinitygroup',
    'access_news',
    'key',
    'domain',
  ];

  /**
   * {@inheritdoc}
   *
   * access_affinitygroup.acting_user_access depends on access.access_id_resolver,
   * which is defined in the base `access` module (access.services.yml). This kernel
   * test does not enable `access` (info.yml dependencies are not auto-loaded for the
   * service compile), so register just that one service — it needs only
   * entity_type.manager + database, both present here — rather than pulling in the
   * whole `access` module and its subscribers/access_llm cascade.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('access.access_id_resolver', 'Drupal\access\AccessIdResolver')
      ->addArgument(new Reference('entity_type.manager'))
      ->addArgument(new Reference('database'));
    // The group email broadcast reads the active domain through this.
    $container->register('access_misc.sitetools', SiteTools::class)
      ->addArgument(new Reference('entity_type.manager'))
      ->addArgument(new Reference('token'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['filter']);

    NodeType::create([
      'type' => 'affinity_group',
      'name' => 'Affinity Group',
    ])->save();
    NodeType::create([
      'type' => 'access_news',
      'name' => 'ACCESS News',
    ])->save();

    // field_coordinator (on affinity_group, ref user) — the helper's input.
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

    // The affinity_group entity_presave hook (access_affinitygroup_entity_presave)
    // fires whenever an affinity_group node is saved. It is orthogonal to what
    // this test proves, but it reads a handful of fields and needs the
    // affinity_group_leader role + the 'affinity_groups' vocab to complete
    // without erroring. Provide the minimal fixture it reads; CC calls are
    // disabled by default (isCCEnabled() → FALSE), so the hook returns before its
    // Constant Contact work. These are NOT part of the endpoint contract.
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
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
    // check_ext_email_list() reads field_ext_email_list; the CC path reads
    // field_list_id. Both plain string fields, left empty.
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

    // field_affinity_group_node (on access_news, ref node) — carries the group
    // selection the endpoint resolves by UUID.
    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group_node',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_affinity_group_node',
      'entity_type' => 'node',
      'bundle' => 'access_news',
      'settings' => ['handler_settings' => ['target_bundles' => ['affinity_group' => 'affinity_group']]],
    ])->save();

    // field_tags (on access_news, ref taxonomy_term) — the endpoint resolves
    // the caller's tag UUIDs to term ids and sets them.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'access_news',
      'settings' => ['handler_settings' => ['target_bundles' => ['tags' => 'tags']]],
    ])->save();

    // field_choose_where_to_share_this (on access_news) — a list_string read
    // by access_news_affinity_group_broadcast() in access_news_entity_update
    // when a node is (re)saved published. Left empty in tests so the broadcast
    // returns early.
    FieldStorageConfig::create([
      'field_name' => 'field_choose_where_to_share_this',
      'entity_type' => 'node',
      'type' => 'list_string',
      'cardinality' => -1,
      'settings' => [
        'allowed_values' => [
          'on_the_announcements_page' => 'on the Announcements page',
          'in_the_access_support_bi_weekly_digest' => 'in the ACCESS Support Bi-Weekly Digest',
          'on_your_affinity_group_page' => 'on your Affinity Group page',
          'email_to_your_affinity_group' => 'email to your Affinity Group',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_choose_where_to_share_this',
      'entity_type' => 'node',
      'bundle' => 'access_news',
    ])->save();

    // field_domain_access (on access_news, ref domain) — the endpoint pins
    // every announcement to the ACCESS Support domain. domain_access itself is
    // not enabled: only the stored reference is under test, not the grants.
    FieldStorageConfig::create([
      'field_name' => 'field_domain_access',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'domain'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_domain_access',
      'entity_type' => 'node',
      'bundle' => 'access_news',
    ])->save();

    // body (on access_news) — the main content field.
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'access_news',
    ])->save();

    // The 'affinity-group' vocabulary + field_affinity_group taxonomy field,
    // both required by access_news_node_presave() →
    // access_news_update_affinity_group(): for each referenced group node it
    // loads a same-named term and appends it here.
    Vocabulary::create(['vid' => 'affinity-group', 'name' => 'Affinity Group'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'bundle' => 'access_news',
      'settings' => ['handler_settings' => ['target_bundles' => ['affinity-group' => 'affinity-group']]],
    ])->save();
    // Also on affinity_group: add_ag_taxonomy_term() (in the AG presave) sets
    // field_affinity_group on the AG node itself.
    FieldConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();

    // Apply the editorial content-moderation workflow to access_news so
    // moderation_state='draft' resolves to an unpublished node.
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'access_news');
    $workflow->save();

    // Grant the prod node-access permissions to the authenticated role so
    // ->access('create'/'update'/'delete') evaluate as in production.
    $this->installConfig(['user']);
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      [
        // Basic node-view permission; required for ->access('create') and for
        // the mine read query on any user other than uid 1.
        'access content',
        'create access_news content',
        'edit own access_news content',
        'delete own access_news content',
        'view own unpublished content',
        // Content-moderation gates node create/save on the initial transition;
        // without these a non-root user (uid > 1) fails ->access('create').
        // The write-endpoint tests never hit this because their coordinator
        // was uid 1 (which bypasses access); the multi-user read tests do.
        'use editorial transition create_new_draft',
      ],
    );
    // On the live site only these roles may publish, so only they can keep a
    // published announcement published when they edit it.
    $this->grantPermissions(Role::load('affinity_group_leader'), ['use editorial transition publish']);

    $this->attachRuleFields();
  }

  /**
   * Attaches the fields the review, sharing and broadcast rules read.
   */
  protected function attachRuleFields(): void {
    $fields = [
      [
        'node',
        'access_news',
        'field_affiliation',
        'list_string',
        [
          'allowed_values' => [
            'ACCESS Collaboration' => 'ACCESS Collaboration',
            'Community' => 'Community',
          ],
        ],
      ],
      ['node', 'access_news', 'field_published_date', 'datetime', ['datetime_type' => 'date']],
      ['node', 'affinity_group', 'field_list_id', 'string', []],
      ['node', 'affinity_group', 'field_affinity_group_category', 'string', []],
      // Read by the affinity group view access check.
      ['node', 'affinity_group', 'field_ag_private', 'boolean', []],
      ['node', 'affinity_group', 'field_ag_private_users', 'entity_reference', ['target_type' => 'user']],
    ];
    foreach ($fields as [$entityType, $bundle, $name, $type, $settings]) {
      if (!FieldStorageConfig::loadByName($entityType, $name)) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => $entityType,
          'type' => $type,
          'settings' => $settings,
        ])->save();
      }
      if (!FieldConfig::loadByName($entityType, $bundle, $name)) {
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => $entityType,
          'bundle' => $bundle,
        ])->save();
      }
    }
    if (!FilterFormat::load('basic_html')) {
      FilterFormat::create(['format' => 'basic_html', 'name' => 'Basic HTML'])->save();
    }
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
   * Creates an UNSAVED affinity_group node coordinated by the given user ids.
   *
   * Used by the coordinator-helper tests: the helper reads field_coordinator
   * off an already-loaded node and never saves it, so we skip ->save() to avoid
   * the affinity_group entity_presave fixture demand.
   *
   * @param int[] $coordinatorUids
   *   User ids to place in field_coordinator.
   */
  protected function makeGroup(array $coordinatorUids): NodeInterface {
    return Node::create([
      'type' => 'affinity_group',
      'title' => 'Group',
      'field_coordinator' => $coordinatorUids,
    ]);
  }

  /**
   * Creates a SAVED, titled affinity_group node + its matching taxonomy term.
   *
   * The endpoint resolves groups by UUID (so the node must be saved/loadable),
   * and access_news_node_presave() looks up a same-named term in 'affinity-group'
   * (so the term must exist), else the presave errors.
   *
   * @param int[] $coordinatorUids
   *   User ids to place in field_coordinator.
   * @param string $title
   *   The group title (also the term name).
   */
  protected function makeSavedGroup(array $coordinatorUids, string $title): NodeInterface {
    Term::create(['vid' => 'affinity-group', 'name' => $title])->save();
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => $title,
      'field_coordinator' => $coordinatorUids,
      // Read by the AG presave hook (field_group_slug is a required field there).
      'field_group_slug' => strtolower(str_replace(' ', '-', $title)),
      // check_ext_email_list() reads [0]['value'] of this field; set it so the
      // index exists and the external-list branch is skipped.
      'field_use_ext_email_list' => 0,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Builds a Request carrying the acting-user attribute + a JSON body.
   */
  protected function jsonRequest(int $actingUid, array $body): Request {
    $request = Request::create('/api/2.3/announcements', 'POST', [], [], [], [], json_encode($body));
    $request->attributes->set('acting_user_uid', $actingUid);
    return $request;
  }

  /**
   * Returns a controller bound to the container.
   */
  protected function controller(): AnnouncementApiController {
    return AnnouncementApiController::create($this->container);
  }

  // ---------------------------------------------------------------------------
  // The coordinator-authorization helper.
  // ---------------------------------------------------------------------------

  public function testCoordinatorMayPostToOwnGroup(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeGroup([(int) $coordinator->id()]);

    $this->assertTrue(
      $this->controller()->userMayPostToGroups($coordinator, [$groupA])
    );
  }

  public function testStrangerMayNotPost(): void {
    $coordinator = $this->createUser();
    $stranger = $this->createUser();
    $groupA = $this->makeGroup([(int) $coordinator->id()]);

    $this->assertFalse(
      $this->controller()->userMayPostToGroups($stranger, [$groupA])
    );
  }

  public function testNewsPmMayPostToAnyGroup(): void {
    $coordinator = $this->createUser();
    $newsPm = $this->createUser([], NULL, FALSE, ['roles' => [$this->createRole([], 'news_pm')]]);
    $groupA = $this->makeGroup([(int) $coordinator->id()]);

    $this->assertFalse(in_array((int) $newsPm->id(), [(int) $coordinator->id()], TRUE));
    $this->assertTrue(
      $this->controller()->userMayPostToGroups($newsPm, [$groupA])
    );
  }

  public function testCoordinatorMayNotPostToForeignGroup(): void {
    $coordinatorA = $this->createUser();
    $coordinatorB = $this->createUser();
    $groupB = $this->makeGroup([(int) $coordinatorB->id()]);

    $this->assertFalse(
      $this->controller()->userMayPostToGroups($coordinatorA, [$groupB])
    );
  }

  public function testAdministratorMayPostToAnyGroup(): void {
    $coordinator = $this->createUser();
    $admin = $this->createUser([], NULL, FALSE, ['roles' => [$this->createRole([], 'administrator')]]);
    $groupA = $this->makeGroup([(int) $coordinator->id()]);

    $this->assertTrue(
      $this->controller()->userMayPostToGroups($admin, [$groupA])
    );
  }

  // ---------------------------------------------------------------------------
  // Write endpoints — create / update / delete.
  // ---------------------------------------------------------------------------

  /**
   * create-positive: a coordinator posts to their own group → draft node owned
   * by the coordinator.
   */
  public function testCreatePositive(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');

    $request = $this->jsonRequest((int) $coordinator->id(), [
      'title' => 'Hello world',
      'body' => ['value' => 'Body text here.'],
      'field_affinity_group_node' => [$groupA->uuid()],
    ]);

    $response = $this->asActingUser(
      $coordinator,
      fn () => $this->controller()->createAnnouncement($request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertNotEmpty($data['uuid']);
    $this->assertNotEmpty($data['nid']);
    $this->assertSame('Hello world', $data['title']);
    $this->assertStringContainsString('/node/' . $data['nid'] . '/edit', $data['edit_url']);

    $node = Node::load($data['nid']);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame('access_news', $node->bundle());
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertFalse($node->isPublished());
    $this->assertSame((int) $coordinator->id(), (int) $node->getOwnerId());
    // Domain 3.x no longer defaults the domain outside entity forms, so the
    // endpoint must set it or the announcement drops out of the news views.
    $this->assertSame(
      [SiteTools::DOMAIN_ACCESS_SUPPORT],
      array_column($node->get('field_domain_access')->getValue(), 'target_id'),
    );
  }

  public function testCreateSetsTagsFromUuids(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $tag1 = Term::create(['vid' => 'tags', 'name' => 'gpu']);
    $tag1->save();
    $tag2 = Term::create(['vid' => 'tags', 'name' => 'hpc']);
    $tag2->save();

    $request = $this->jsonRequest((int) $coordinator->id(), [
      'title' => 'Tagged',
      'body' => ['value' => 'Body.'],
      'field_affinity_group_node' => [$groupA->uuid()],
      'field_tags' => [$tag1->uuid(), $tag2->uuid()],
    ]);

    $response = $this->asActingUser(
      $coordinator,
      fn () => $this->controller()->createAnnouncement($request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $node = Node::load($data['nid']);
    $tagIds = array_map(
      fn (array $ref) => (int) $ref['target_id'],
      $node->get('field_tags')->getValue(),
    );
    $this->assertSame([(int) $tag1->id(), (int) $tag2->id()], $tagIds);
  }

  /**
   * create-coordinator-denied: a stranger (not coordinator/admin/news_pm) is
   * refused and NO node is created.
   */
  public function testCreateCoordinatorDenied(): void {
    $coordinator = $this->createUser();
    $stranger = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');

    $request = $this->jsonRequest((int) $stranger->id(), [
      'title' => 'Sneaky',
      'body' => ['value' => 'Nope.'],
      'field_affinity_group_node' => [$groupA->uuid()],
    ]);

    $response = $this->asActingUser(
      $stranger,
      fn () => $this->controller()->createAnnouncement($request),
    );

    $this->assertContains($response->getStatusCode(), [403, 409]);
    $count = \Drupal::entityQuery('node')
      ->condition('type', 'access_news')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(0, (int) $count);
  }

  /**
   * create-draft-lock: a caller-supplied moderation_state='published' is ignored;
   * the saved node is still draft/unpublished.
   */
  public function testCreateDraftLock(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');

    $request = $this->jsonRequest((int) $coordinator->id(), [
      'title' => 'Trying to self-publish',
      'body' => ['value' => 'Body.'],
      'field_affinity_group_node' => [$groupA->uuid()],
      // Rogue fields the endpoint must ignore.
      'moderation_state' => 'published',
      'status' => 1,
    ]);

    $response = $this->asActingUser(
      $coordinator,
      fn () => $this->controller()->createAnnouncement($request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $node = Node::load($data['nid']);
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertFalse($node->isPublished());
  }

  /**
   * update-owner: the owner edits their own announcement's title/body →
   * succeeds, moderation_state unchanged (still draft).
   */
  public function testUpdateOwner(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $uuid = $this->createDraft($coordinator, $groupA, 'Original title');

    $request = Request::create('/api/2.3/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode([
      'title' => 'Edited title',
      'body' => ['value' => 'Edited body.'],
    ]));
    $request->attributes->set('acting_user_uid', (int) $coordinator->id());

    $response = $this->asActingUser(
      $coordinator,
      fn () => $this->controller()->update($uuid, $request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);

    $node = $this->loadByUuid($uuid);
    $this->assertSame('Edited title', $node->getTitle());
    $this->assertSame('Edited body.', $node->get('body')->value);
    $this->assertSame('draft', $node->get('moderation_state')->value);
  }

  public function testUpdateSummaryOnlyPreservesBodyValue(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $uuid = $this->createDraft($coordinator, $groupA, 'Title');
    // Give it a real body value first.
    $seed = Request::create('/api/2.3/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode([
      'body' => ['value' => 'The full body text.', 'summary' => 'Old summary.'],
    ]));
    $seed->attributes->set('acting_user_uid', (int) $coordinator->id());
    $this->asActingUser($coordinator, fn () => $this->controller()->update($uuid, $seed));

    // Now change ONLY the summary.
    $request = Request::create('/api/2.3/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode([
      'body' => ['summary' => 'New summary.'],
    ]));
    $request->attributes->set('acting_user_uid', (int) $coordinator->id());
    $this->asActingUser($coordinator, fn () => $this->controller()->update($uuid, $request));

    $node = $this->loadByUuid($uuid);
    $this->assertSame('The full body text.', $node->get('body')->value, 'Body value preserved on summary-only update.');
    $this->assertSame('New summary.', $node->get('body')->summary);
  }

  public function testMineReturnsTagNamesNotIds(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $tag = Term::create(['vid' => 'tags', 'name' => 'gpu']);
    $tag->save();

    $create = $this->jsonRequest((int) $coordinator->id(), [
      'title' => 'Tagged draft',
      'body' => ['value' => 'Body.'],
      'field_affinity_group_node' => [$groupA->uuid()],
      'field_tags' => [$tag->uuid()],
    ]);
    $this->asActingUser($coordinator, fn () => $this->controller()->createAnnouncement($create));

    $request = $this->mineRequest((int) $coordinator->id());
    $response = $this->asActingUser($coordinator, fn () => $this->controller()->mine($request));
    $data = json_decode($response->getContent(), TRUE);

    $item = $data['items'][0];
    $this->assertSame(['gpu'], $item['tags'], 'mine returns tag NAMES, not ids/uuids.');
  }

  /**
   * update-nonowner-denied: a different non-owner cannot update someone else's
   * announcement (refused via node->access('update')).
   */
  public function testUpdateNonOwnerDenied(): void {
    $coordinator = $this->createUser();
    $other = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $uuid = $this->createDraft($coordinator, $groupA, 'Owned by coordinator');

    $request = Request::create('/api/2.3/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode([
      'title' => 'Hijacked',
    ]));
    $request->attributes->set('acting_user_uid', (int) $other->id());

    $response = $this->asActingUser(
      $other,
      fn () => $this->controller()->update($uuid, $request),
    );

    $this->assertSame(403, $response->getStatusCode());
    $node = $this->loadByUuid($uuid);
    $this->assertSame('Owned by coordinator', $node->getTitle());
  }

  /**
   * delete-owner: the owner deletes their own announcement → succeeds.
   */
  public function testDeleteOwner(): void {
    $coordinator = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $uuid = $this->createDraft($coordinator, $groupA, 'To delete');
    $nid = (int) $this->loadByUuid($uuid)->id();

    $request = Request::create('/api/2.3/announcements/' . $uuid, 'DELETE');
    $request->attributes->set('acting_user_uid', (int) $coordinator->id());

    $response = $this->asActingUser(
      $coordinator,
      fn () => $this->controller()->delete($uuid, $request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $this->assertNull(Node::load($nid));
  }

  /**
   * delete-nonowner-denied: a non-owner cannot delete someone else's
   * announcement (refused via node->access('delete')).
   */
  public function testDeleteNonOwnerDenied(): void {
    $coordinator = $this->createUser();
    $other = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $uuid = $this->createDraft($coordinator, $groupA, 'Owned by coordinator');
    $nid = (int) $this->loadByUuid($uuid)->id();

    $request = Request::create('/api/2.3/announcements/' . $uuid, 'DELETE');
    $request->attributes->set('acting_user_uid', (int) $other->id());

    $response = $this->asActingUser(
      $other,
      fn () => $this->controller()->delete($uuid, $request),
    );

    $this->assertSame(403, $response->getStatusCode());
    $this->assertInstanceOf(NodeInterface::class, Node::load($nid));
  }

  // ---------------------------------------------------------------------------
  // Read endpoint — GET /api/2.3/announcements/mine.
  // ---------------------------------------------------------------------------

  /**
   * mine own-drafts-only: acting as A, mine returns A's draft and NOT B's.
   *
   * This is the per-user scoping proof (the god-mode fix): running switched as
   * A with accessCheck(TRUE) + condition('uid', A) means B's draft never leaks.
   */
  public function testMineOwnDraftsOnly(): void {
    $userA = $this->createUser();
    $userB = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $userA->id()], 'Group A');
    $groupB = $this->makeSavedGroup([(int) $userB->id()], 'Group B');

    $uuidA = $this->createDraft($userA, $groupA, 'A draft');
    $uuidB = $this->createDraft($userB, $groupB, 'B draft');

    $request = $this->mineRequest((int) $userA->id());
    $response = $this->asActingUser(
      $userA,
      fn () => $this->controller()->mine($request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $uuids = array_column($data['items'], 'uuid');
    $this->assertContains($uuidA, $uuids);
    $this->assertNotContains($uuidB, $uuids);
  }

  /**
   * mine includes own published too: acting as A, mine returns A's draft AND
   * A's published announcement.
   */
  public function testMineIncludesOwnPublished(): void {
    $userA = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $userA->id()], 'Group A');

    $draftUuid = $this->createDraft($userA, $groupA, 'A draft');
    $publishedUuid = $this->createPublished($userA, $groupA, 'A published');

    $request = $this->mineRequest((int) $userA->id());
    $response = $this->asActingUser(
      $userA,
      fn () => $this->controller()->mine($request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $uuids = array_column($data['items'], 'uuid');
    $this->assertContains($draftUuid, $uuids);
    $this->assertContains($publishedUuid, $uuids);
  }

  /**
   * mine status is a STRING: 'draft' for a draft, 'published' for a published
   * node — never a boolean (the MCP-passthrough double-transform guard).
   */
  public function testMineStatusIsString(): void {
    $userA = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $userA->id()], 'Group A');

    $draftUuid = $this->createDraft($userA, $groupA, 'A draft');
    $publishedUuid = $this->createPublished($userA, $groupA, 'A published');

    $request = $this->mineRequest((int) $userA->id());
    $response = $this->asActingUser(
      $userA,
      fn () => $this->controller()->mine($request),
    );

    $data = json_decode($response->getContent(), TRUE);
    $byUuid = [];
    foreach ($data['items'] as $item) {
      $byUuid[$item['uuid']] = $item;
    }
    $this->assertSame('draft', $byUuid[$draftUuid]['status']);
    $this->assertSame('published', $byUuid[$publishedUuid]['status']);
  }

  /**
   * mine pagination: with limit=2 and 4 owned announcements, mine returns AT
   * MOST limit+1 (=3) items, proving range(0, limit+1) for has_more.
   */
  public function testMinePaginationLimitPlusOne(): void {
    $userA = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $userA->id()], 'Group A');

    for ($i = 0; $i < 4; $i++) {
      $this->createDraft($userA, $groupA, 'Draft ' . $i);
    }

    $request = $this->mineRequest((int) $userA->id(), 2);
    $response = $this->asActingUser(
      $userA,
      fn () => $this->controller()->mine($request),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(3, $data['items']);
  }

  /**
   * mine summary is HTML-stripped: a body of '<p>Hello <b>world</b></p>' yields
   * a summary with no tags.
   */
  public function testMineSummaryStripsHtml(): void {
    $userA = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $userA->id()], 'Group A');

    $request = $this->jsonRequest((int) $userA->id(), [
      'title' => 'HTML body',
      'body' => ['value' => '<p>Hello <b>world</b></p>'],
      'field_affinity_group_node' => [$groupA->uuid()],
    ]);
    $this->asActingUser(
      $userA,
      fn () => $this->controller()->createAnnouncement($request),
    );

    $mineRequest = $this->mineRequest((int) $userA->id());
    $response = $this->asActingUser(
      $userA,
      fn () => $this->controller()->mine($mineRequest),
    );

    $data = json_decode($response->getContent(), TRUE);
    $summary = $data['items'][0]['summary'];
    $this->assertStringNotContainsString('<', $summary);
    $this->assertStringContainsString('Hello world', $summary);
  }

  // ---------------------------------------------------------------------------
  // D8-3000: the same review and group rules as the web form.
  // ---------------------------------------------------------------------------

  /**
   * Creates an author who is not uid 1, which bypasses every permission.
   *
   * @param string[] $roles
   *   Extra roles for the author.
   */
  protected function author(array $roles = []) {
    if (!\Drupal::entityTypeManager()->getStorage('user')->load(1)) {
      $this->createUser([], 'site-admin');
    }
    return $this->createUser([], NULL, FALSE, $roles ? ['roles' => $roles] : []);
  }

  /**
   * Sends PATCH /api/2.3/announcements/{uuid} as $user.
   */
  protected function patch($user, string $uuid, array $body): array {
    $request = Request::create('/api/2.3/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode($body));
    $request->attributes->set('acting_user_uid', (int) $user->id());
    $response = $this->asActingUser($user, fn () => $this->controller()->update($uuid, $request));
    return [$response->getStatusCode(), json_decode($response->getContent(), TRUE)];
  }

  /**
   * Sends POST /api/2.3/announcements as $user.
   */
  protected function create($user, array $body): array {
    $request = $this->jsonRequest((int) $user->id(), $body + ['title' => 'Announcement', 'body' => ['value' => 'Body.']]);
    $response = $this->asActingUser($user, fn () => $this->controller()->createAnnouncement($request));
    return [$response->getStatusCode(), json_decode($response->getContent(), TRUE)];
  }

  /**
   * Loads the newest revision of an announcement, pending or not.
   */
  protected function latestRevision(string $uuid): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $node = $this->loadByUuid($uuid);
    return $storage->loadRevision($storage->getLatestRevisionId($node->id()));
  }

  /**
   * Asserts a 409 refusal with $code and a message for the author.
   */
  protected function assertRefused(array $result, string $code): void {
    [$status, $data] = $result;
    $this->assertSame(409, $status);
    $this->assertSame($code, $data['error']);
    $this->assertNotEmpty($data['message']);
  }

  /**
   * An owner who may not publish edits a published announcement into a draft.
   *
   * The live version stays as it was until an editor approves the draft.
   */
  public function testPublishedEditWithoutPublishRightBecomesPendingDraft(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createPublished($owner, $group, 'Live title');

    [$status, $data] = $this->patch($owner, $uuid, ['title' => 'Edited title']);

    $this->assertSame(200, $status);
    $this->assertTrue($data['review_required']);
    $live = $this->loadByUuid($uuid);
    $this->assertTrue($live->isPublished());
    $this->assertSame('Live title', $live->getTitle());
    $pending = $this->latestRevision($uuid);
    $this->assertSame('draft', $pending->get('moderation_state')->value);
    $this->assertSame('Edited title', $pending->getTitle());
  }

  /**
   * An owner who may publish keeps the announcement published, as on the form.
   */
  public function testPublishedEditWithPublishRightStaysPublished(): void {
    $owner = $this->author(['affinity_group_leader']);
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createPublished($owner, $group, 'Live title');

    [$status, $data] = $this->patch($owner, $uuid, ['title' => 'Edited title']);

    $this->assertSame(200, $status);
    $this->assertFalse($data['review_required']);
    $live = $this->loadByUuid($uuid);
    $this->assertTrue($live->isPublished());
    $this->assertSame('Edited title', $live->getTitle());
  }

  /**
   * A second edit builds on the pending draft instead of discarding it.
   */
  public function testSecondEditKeepsTheFirstPendingChange(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createPublished($owner, $group, 'Live title');

    $this->patch($owner, $uuid, ['title' => 'Edited title']);
    $this->patch($owner, $uuid, ['body' => ['value' => 'Edited body.']]);

    $pending = $this->latestRevision($uuid);
    $this->assertSame('Edited title', $pending->getTitle());
    $this->assertSame('Edited body.', $pending->get('body')->value);
    $this->assertSame('Live title', $this->loadByUuid($uuid)->getTitle());
  }

  /**
   * Group email is checked against the stored groups when the PATCH omits them.
   */
  public function testFormerCoordinatorMayNotAddGroupEmail(): void {
    $owner = $this->author(['affinity_group_leader']);
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createPublished($owner, $group, 'Live title');
    $group->set('field_coordinator', [])->save();

    $result = $this->patch($owner, $uuid, ['field_choose_where_to_share_this' => ['email_to_your_affinity_group']]);

    $this->assertRefused($result, 'not_coordinator');
    $this->assertSame([], array_column($this->latestRevision($uuid)->get('field_choose_where_to_share_this')->getValue(), 'value'));
  }

  /**
   * A group sharing option with no group is refused on create.
   */
  public function testGroupSharingWithoutGroupRefusedOnCreate(): void {
    $owner = $this->author();

    $result = $this->create($owner, ['field_choose_where_to_share_this' => ['on_your_affinity_group_page']]);

    $this->assertRefused($result, 'group_required');
    $this->assertEmpty(\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'access_news']));
  }

  /**
   * A group sharing option with no group is refused on update.
   */
  public function testGroupSharingWithoutGroupRefusedOnUpdate(): void {
    $owner = $this->author();
    [, $created] = $this->create($owner, ['field_choose_where_to_share_this' => ['on_the_announcements_page']]);

    $result = $this->patch($owner, $created['uuid'], ['field_choose_where_to_share_this' => ['email_to_your_affinity_group']]);

    $this->assertRefused($result, 'group_required');
  }

  /**
   * An unknown group on create is refused rather than silently dropped.
   */
  public function testUnknownGroupRefusedOnCreate(): void {
    $owner = $this->author();

    $result = $this->create($owner, ['field_affinity_group_node' => ['00000000-0000-4000-8000-000000000000']]);

    $this->assertRefused($result, 'unknown_group');
  }

  /**
   * An unknown group on update is refused and the stored groups survive.
   */
  public function testUnknownGroupOnUpdateKeepsExistingGroups(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createDraft($owner, $group, 'Title');

    $result = $this->patch($owner, $uuid, [
      'field_affinity_group_node' => [$group->uuid(), '00000000-0000-4000-8000-000000000000'],
    ]);

    $this->assertRefused($result, 'unknown_group');
    $this->assertSame([(string) $group->id()], array_map('strval', array_column($this->latestRevision($uuid)->get('field_affinity_group_node')->getValue(), 'target_id')));
  }

  /**
   * A group id that is not a UUID string is refused, and groups survive.
   */
  public function testNonStringGroupIdRefusedAndGroupsKept(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createDraft($owner, $group, 'Title');

    $result = $this->patch($owner, $uuid, ['field_affinity_group_node' => [(int) $group->id()]]);

    $this->assertRefused($result, 'unknown_group');
    $stored = array_column($this->latestRevision($uuid)->get('field_affinity_group_node')->getValue(), 'target_id');
    $this->assertSame([(string) $group->id()], array_map('strval', $stored));
  }

  /**
   * Every API edit keeps history in a new revision, as form saves do.
   */
  public function testEachEditCreatesNewRevision(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createDraft($owner, $group, 'Title');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nid = (int) $this->loadByUuid($uuid)->id();
    $before = count($storage->revisionIds($this->loadByUuid($uuid)));

    $this->patch($owner, $uuid, ['title' => 'Edit one']);
    $this->patch($owner, $uuid, ['title' => 'Edit two']);

    $storage->resetCache();
    $this->assertSame($before + 2, count($storage->revisionIds($storage->load($nid))));
  }

  /**
   * A news_pm editing a published announcement keeps it published.
   */
  public function testNewsPmEditOfPublishedStaysPublished(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createPublished($owner, $group, 'Live title');
    $this->createRole(['edit any access_news content', 'use editorial transition publish'], 'news_pm');
    $newsPm = $this->author(['news_pm']);

    [$status, $data] = $this->patch($newsPm, $uuid, ['title' => 'Edited by news_pm']);

    $this->assertSame(200, $status);
    $this->assertFalse($data['review_required']);
    $this->assertSame('Edited by news_pm', $this->loadByUuid($uuid)->getTitle());
  }

  /**
   * A non-publisher adding group email creates a draft and sends nothing.
   *
   * The email goes out when an editor publishes the draft.
   */
  public function testNonPublisherDraftDoesNotSendGroupEmail(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $group->set('field_list_id', 'cc-list-1')->set('field_affinity_group_category', 'ACCESS')->save();
    $uuid = $this->createPublished($owner, $group, 'Live title');

    [$status, $data] = $this->patch($owner, $uuid, ['field_choose_where_to_share_this' => ['email_to_your_affinity_group']]);

    $this->assertSame(200, $status);
    $this->assertTrue($data['review_required']);
    $this->assertFalse($this->broadcastLogged());
    $this->assertSame([], array_column($this->loadByUuid($uuid)->get('field_choose_where_to_share_this')->getValue(), 'value'));
  }

  /**
   * A private group the author no longer coordinates keeps only its uuid.
   *
   * Private groups are visible to their coordinators and listed users only.
   */
  public function testMineHidesTitleOfPrivateGroup(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Private Group');
    $uuid = $this->createDraft($owner, $group, 'Title');
    $group->set('field_ag_private', 1)
      ->set('field_ag_private_users', [])
      ->set('field_coordinator', [])
      ->save();

    $item = $this->mineItem($owner, $uuid);

    $this->assertSame([['uuid' => $group->uuid(), 'title' => NULL]], $item['affinity_groups']);
  }

  /**
   * Whether the group email sender ran, from its log entry.
   */
  protected function broadcastLogged(): bool {
    return (bool) \Drupal::database()->select('watchdog', 'w')
      ->condition('type', 'access_affinitygroup')
      ->condition('message', 'Email broadcast initiated%', 'LIKE')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * An affiliation outside the allowed values is refused.
   */
  public function testInvalidAffiliationRefused(): void {
    $owner = $this->author();

    $this->assertRefused($this->create($owner, ['field_affiliation' => 'Galactic Empire']), 'invalid_affiliation');
  }

  /**
   * A sharing option outside the allowed values is refused.
   */
  public function testInvalidSharingOptionRefused(): void {
    $owner = $this->author();

    $this->assertRefused($this->create($owner, ['field_choose_where_to_share_this' => ['on_a_billboard']]), 'invalid_sharing');
  }

  // ---------------------------------------------------------------------------
  // D8-3001: /mine shows affiliation, sharing and groups.
  // ---------------------------------------------------------------------------

  /**
   * Each /mine item carries affiliation, sharing values and groups.
   */
  public function testMineIncludesAffiliationSharingAndGroups(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    [, $created] = $this->create($owner, [
      'field_affiliation' => 'Community',
      'field_choose_where_to_share_this' => ['on_the_announcements_page', 'on_your_affinity_group_page'],
      'field_affinity_group_node' => [$group->uuid()],
    ]);

    $item = $this->mineItem($owner, $created['uuid']);

    $this->assertSame('Community', $item['affiliation']);
    $this->assertSame(['on_the_announcements_page', 'on_your_affinity_group_page'], $item['where_to_share']);
    $this->assertSame([['uuid' => $group->uuid(), 'title' => 'Group A']], $item['affinity_groups']);
  }

  /**
   * Unset affiliation, sharing and groups come back empty, not missing.
   */
  public function testMineEmptyValues(): void {
    $owner = $this->author();
    [, $created] = $this->create($owner, []);

    $item = $this->mineItem($owner, $created['uuid']);

    $this->assertNull($item['affiliation']);
    $this->assertSame([], $item['where_to_share']);
    $this->assertSame([], $item['affinity_groups']);
  }

  /**
   * A group the user may not view keeps its uuid but not its title.
   *
   * Dropping it would let a replace-all update remove the group unseen.
   */
  public function testMineHidesTitleOfGroupUserCannotView(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Hidden Group');
    $uuid = $this->createDraft($owner, $group, 'Title');
    $group->setUnpublished()->save();

    $item = $this->mineItem($owner, $uuid);

    $this->assertSame([['uuid' => $group->uuid(), 'title' => NULL]], $item['affinity_groups']);
  }

  /**
   * Returns the /mine item for $uuid as seen by $user.
   */
  protected function mineItem($user, string $uuid): array {
    $response = $this->asActingUser($user, fn () => $this->controller()->mine($this->mineRequest((int) $user->id())));
    foreach (json_decode($response->getContent(), TRUE)['items'] as $item) {
      if ($item['uuid'] === $uuid) {
        return $item;
      }
    }
    $this->fail("No /mine item for $uuid.");
  }

  // ---------------------------------------------------------------------------
  // D8-2997: group email sends whatever position the option is in.
  // ---------------------------------------------------------------------------

  /**
   * Publishes an announcement with the given sharing options in that order.
   *
   * A send clears the email option and saves the node, so the stored options
   * afterwards show whether the send ran.
   */
  protected function publishWithSharing(array $options): array {
    // The email links back to the announcement on the active domain.
    $this->installConfig(['domain']);
    Domain::create([
      'id' => 'support',
      'hostname' => 'support.example.com',
      'name' => 'Support',
      'scheme' => 'https',
      'status' => 1,
    ])->save();
    \Drupal::service('domain.negotiator')->setActiveDomain(Domain::load('support'));
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $group->set('field_list_id', 'cc-list-1')->set('field_affinity_group_category', 'ACCESS')->save();
    $node = Node::create([
      'type' => 'access_news',
      'title' => 'Broadcast test',
      'uid' => $owner->id(),
      'body' => ['value' => '<p>Body.</p>', 'format' => 'basic_html'],
      'field_published_date' => '2026-11-08',
      'field_affinity_group_node' => [$group->id()],
      'field_choose_where_to_share_this' => $options,
      'moderation_state' => 'published',
    ]);
    $node->save();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    return array_column(Node::load($node->id())->get('field_choose_where_to_share_this')->getValue(), 'value');
  }

  /**
   * Email as the only option sends.
   */
  public function testGroupEmailAloneSends(): void {
    $this->assertSame([], $this->publishWithSharing(['email_to_your_affinity_group']));
    $this->assertTrue($this->broadcastLogged());
  }

  /**
   * Email listed first sends and keeps the other options.
   */
  public function testGroupEmailFirstSends(): void {
    $stored = $this->publishWithSharing(['email_to_your_affinity_group', 'on_your_affinity_group_page']);

    $this->assertSame(['on_your_affinity_group_page'], $stored);
    $this->assertTrue($this->broadcastLogged());
  }

  /**
   * Email listed last sends and keeps the other options.
   */
  public function testGroupEmailLastSends(): void {
    $stored = $this->publishWithSharing(['on_your_affinity_group_page', 'email_to_your_affinity_group']);

    $this->assertSame(['on_your_affinity_group_page'], $stored);
    $this->assertTrue($this->broadcastLogged());
  }

  /**
   * Builds a GET /api/2.3/announcements/mine request carrying the acting user.
   */
  protected function mineRequest(int $actingUid, ?int $limit = NULL): Request {
    $query = $limit === NULL ? [] : ['limit' => $limit];
    $request = Request::create('/api/2.3/announcements/mine', 'GET', $query);
    $request->attributes->set('acting_user_uid', $actingUid);
    return $request;
  }

  /**
   * Creates a PUBLISHED announcement owned by $owner and returns its UUID.
   *
   * The write endpoint is draft-locked, so publish by setting moderation_state
   * directly after create (the read endpoint just reports isPublished()).
   */
  protected function createPublished($owner, NodeInterface $group, string $title): string {
    $uuid = $this->createDraft($owner, $group, $title);
    $node = $this->loadByUuid($uuid);
    $node->set('moderation_state', 'published');
    $node->save();
    return $uuid;
  }

  /**
   * Creates a draft announcement through the endpoint and returns its UUID.
   */
  protected function createDraft($owner, NodeInterface $group, string $title): string {
    $request = $this->jsonRequest((int) $owner->id(), [
      'title' => $title,
      'body' => ['value' => 'Body.'],
      'field_affinity_group_node' => [$group->uuid()],
    ]);
    $response = $this->asActingUser(
      $owner,
      fn () => $this->controller()->createAnnouncement($request),
    );
    $this->assertSame(200, $response->getStatusCode());
    return json_decode($response->getContent(), TRUE)['uuid'];
  }

  /**
   * Loads an access_news node by UUID.
   */
  protected function loadByUuid(string $uuid): ?NodeInterface {
    $nodes = \Drupal::entityTypeManager()->getStorage('node')
      ->loadByProperties(['uuid' => $uuid]);
    return $nodes ? reset($nodes) : NULL;
  }

  // ---------------------------------------------------------------------------
  // D8-3000: reads show the revision PATCH edits, plus a single-item read.
  // ---------------------------------------------------------------------------

  /**
   * Makes a published announcement whose owner's edit waits for review.
   *
   * The pending edit retitles it and adds a second group.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: string, 2: \Drupal\node\NodeInterface, 3: \Drupal\node\NodeInterface}
   *   The owner, the uuid, and the original and added groups.
   */
  protected function publishedWithPendingEdit(): array {
    $owner = $this->author();
    $groupA = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $groupB = $this->makeSavedGroup([(int) $owner->id()], 'Group B');
    $uuid = $this->createPublished($owner, $groupA, 'Live title');
    [$status, $data] = $this->patch($owner, $uuid, [
      'title' => 'Edited title',
      'field_affinity_group_node' => [$groupA->uuid(), $groupB->uuid()],
    ]);
    $this->assertSame(200, $status);
    $this->assertTrue($data['review_required']);
    return [$owner, $uuid, $groupA, $groupB];
  }

  /**
   * The /mine item shows the pending edit, the live status and the flag.
   *
   * Tools that rebuild the group list from /mine must start from the
   * revision PATCH edits, or they would undo the pending change.
   */
  public function testMineShowsPendingEdit(): void {
    [$owner, $uuid, $groupA, $groupB] = $this->publishedWithPendingEdit();

    $item = $this->mineItem($owner, $uuid);

    $this->assertSame('Edited title', $item['title']);
    $this->assertSame([$groupA->uuid(), $groupB->uuid()], array_column($item['affinity_groups'], 'uuid'));
    $this->assertSame('published', $item['status']);
    $this->assertTrue($item['has_pending_revision']);
  }

  /**
   * Drafts and published announcements with no pending edit are not flagged.
   */
  public function testMineFlagsOnlyPendingEdits(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $draft = $this->createDraft($owner, $group, 'Draft');
    $live = $this->createPublished($owner, $group, 'Live');
    $this->patch($owner, $draft, ['title' => 'Draft edited']);

    $this->assertFalse($this->mineItem($owner, $draft)['has_pending_revision']);
    $this->assertSame('Draft edited', $this->mineItem($owner, $draft)['title']);
    $this->assertFalse($this->mineItem($owner, $live)['has_pending_revision']);
  }

  /**
   * The single-item read returns the same item /mine lists.
   */
  public function testShowMatchesMineItem(): void {
    [$owner, $uuid] = $this->publishedWithPendingEdit();

    [$status, $data] = $this->show($owner, $uuid);

    $this->assertSame(200, $status);
    $this->assertSame($this->mineItem($owner, $uuid), $data);
  }

  /**
   * A news_pm may read an announcement they do not own, pending edit included.
   */
  public function testShowAllowsEditorOfAnotherOwner(): void {
    [, $uuid] = $this->publishedWithPendingEdit();
    $this->createRole(['edit any access_news content', 'use editorial transition publish'], 'news_pm');
    $newsPm = $this->author(['news_pm']);

    [$status, $data] = $this->show($newsPm, $uuid);

    $this->assertSame(200, $status);
    $this->assertSame('Edited title', $data['title']);
    $this->assertTrue($data['has_pending_revision']);
  }

  /**
   * Someone who may not edit gets exactly the answer for a missing uuid.
   *
   * Not a 403, which the MCP client reads as an expired session, and nothing
   * that tells them the announcement exists.
   */
  public function testShowRefusesUserWhoMayNotEditAsNotFound(): void {
    [, $uuid] = $this->publishedWithPendingEdit();
    $stranger = $this->author();

    $refused = $this->show($stranger, $uuid);

    $this->assertSame($this->show($stranger, '00000000-0000-4000-8000-000000000000'), $refused);
    $this->assertSame(404, $refused[0]);
    $this->assertArrayNotHasKey('title', $refused[1]);
  }

  /**
   * The owner can read a plain draft, which has no pending revision.
   */
  public function testShowOwnDraft(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');
    $uuid = $this->createDraft($owner, $group, 'Draft');

    [$status, $data] = $this->show($owner, $uuid);

    $this->assertSame(200, $status);
    $this->assertSame('Draft', $data['title']);
    $this->assertSame('draft', $data['status']);
    $this->assertFalse($data['has_pending_revision']);
  }

  /**
   * An unknown uuid, or one that is not an announcement, is not found.
   */
  public function testShowUnknownIsNotFound(): void {
    $owner = $this->author();
    $group = $this->makeSavedGroup([(int) $owner->id()], 'Group A');

    $this->assertSame(404, $this->show($owner, '00000000-0000-4000-8000-000000000000')[0]);
    $this->assertSame(404, $this->show($owner, $group->uuid())[0]);
  }

  /**
   * Sends GET /api/2.3/announcements/{uuid} as $user.
   */
  protected function show($user, string $uuid): array {
    $response = $this->asActingUser($user, fn () => $this->controller()->show($uuid));
    return [$response->getStatusCode(), json_decode($response->getContent(), TRUE)];
  }

}
