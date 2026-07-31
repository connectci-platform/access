<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Controller\AnnouncementApiController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
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
    'text',
    'filter',
    'taxonomy',
    'workflows',
    'content_moderation',
    'access_affinitygroup',
    'access_news',
    'key',
  ];

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

    // field_choose_where_to_share_this (on access_news) — a list_string read by
    // affinityGroupBroadcast() in access_news_entity_update when a node is
    // (re)saved published. Left empty in tests so the broadcast returns early.
    FieldStorageConfig::create([
      'field_name' => 'field_choose_where_to_share_this',
      'entity_type' => 'node',
      'type' => 'list_string',
      'cardinality' => -1,
      'settings' => [
        'allowed_values' => [
          'email_to_your_affinity_group' => 'email to your Affinity Group',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_choose_where_to_share_this',
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

    // The 'affinity-group' vocabulary + field_affinity_group taxonomy field, both
    // required by access_news_node_presave() → update_affinity_group(): for each
    // referenced group node it loads a same-named term and appends it here.
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
        // Task 8's tests never hit this because their coordinator was uid 1
        // (which bypasses access); Task 9's multi-user tests do.
        'use editorial transition create_new_draft',
        'use editorial transition publish',
      ],
    );
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
   * Used by the pure-helper tests (Task 7): the helper reads field_coordinator
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
    $request = Request::create('/api/1.0/announcements', 'POST', [], [], [], [], json_encode($body));
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
  // Task 7: the coordinator-authorization helper (kept green).
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
  // Task 8: write endpoints — create / update / delete.
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

    $request = Request::create('/api/1.0/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode([
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

  /**
   * update-nonowner-denied: a different non-owner cannot update someone else's
   * announcement (refused via node->access('update')).
   */
  public function testUpdateNonOwnerDenied(): void {
    $coordinator = $this->createUser();
    $other = $this->createUser();
    $groupA = $this->makeSavedGroup([(int) $coordinator->id()], 'Group A');
    $uuid = $this->createDraft($coordinator, $groupA, 'Owned by coordinator');

    $request = Request::create('/api/1.0/announcements/' . $uuid, 'PATCH', [], [], [], [], json_encode([
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

    $request = Request::create('/api/1.0/announcements/' . $uuid, 'DELETE');
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

    $request = Request::create('/api/1.0/announcements/' . $uuid, 'DELETE');
    $request->attributes->set('acting_user_uid', (int) $other->id());

    $response = $this->asActingUser(
      $other,
      fn () => $this->controller()->delete($uuid, $request),
    );

    $this->assertSame(403, $response->getStatusCode());
    $this->assertInstanceOf(NodeInterface::class, Node::load($nid));
  }

  // ---------------------------------------------------------------------------
  // Task 9: read endpoint — GET /api/1.0/announcements/mine.
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

  /**
   * Builds a GET /api/1.0/announcements/mine request carrying the acting user.
   */
  protected function mineRequest(int $actingUid, ?int $limit = NULL): Request {
    $query = $limit === NULL ? [] : ['limit' => $limit];
    $request = Request::create('/api/1.0/announcements/mine', 'GET', $query);
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

}
