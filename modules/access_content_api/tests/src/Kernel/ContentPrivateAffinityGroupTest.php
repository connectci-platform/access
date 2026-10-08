<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Kernel tests for private affinity groups and anonymous rendering.
 *
 * The API must behave identically for every caller: private affinity groups
 * are never served (even to a user the group lists), and rendering happens as
 * anonymous (so field access never depends on who calls).
 *
 * The access_affinitygroup module (whose hook_entity_access reads
 * \Drupal::currentUser()) is deliberately not enabled: these tests prove the
 * API's own guard and anonymous rendering, not that hook.
 *
 * @group access_content_api
 */
class ContentPrivateAffinityGroupTest extends ContentApiKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'path_alias',
    'layout_builder',
    'layout_discovery',
    'block',
    'block_content',
    'options',
    'link',
    'taxonomy',
    'domain',
    'domain_access',
    'access_content_api',
    'access_content_api_test_access',
  ];

  /**
   * A user listed in field_ag_private_users.
   */
  private AccountInterface $listedUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Burn uid 1 (which bypasses all permission checks) so later users are
    // ordinary accounts.
    $this->createUser();
    $this->listedUser = $this->createUser(['access content']);
    $this->createAffinityGroupBundle();
  }

  /**
   * Creates a private affinity group that lists $this->listedUser.
   */
  private function createPrivateGroup(string $alias): Node {
    $node = $this->createContentNode('affinity_group', [
      'title' => 'Private Group',
      'body' => ['value' => '<p>Private group body</p>', 'format' => 'basic_html'],
      'field_ag_private' => 1,
      'field_ag_private_users' => [['target_id' => $this->listedUser->id()]],
    ]);
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $alias])->save();
    return $node;
  }

  /**
   * Creates a public affinity group.
   */
  private function createPublicGroup(): Node {
    return $this->createContentNode('affinity_group', [
      'title' => 'Public Group',
      'body' => ['value' => '<p>Public group body</p>', 'format' => 'basic_html'],
      'field_ag_private' => 0,
    ]);
  }

  /**
   * A private affinity group is a 404 by id and by path for anonymous.
   */
  public function testPrivateGroupIs404ForAnonymous(): void {
    $node = $this->createPrivateGroup('/private-group');
    // Control: the public sibling is served, so the 404 is the private flag.
    $this->assertSame(200, $this->requestById($this->createPublicGroup()->id())->getStatusCode());

    $this->assertSame(404, $this->requestById($node->id())->getStatusCode());
    $this->assertSame(404, $this->requestByPath('/private-group')->getStatusCode());
  }

  /**
   * A user listed in field_ag_private_users still gets a 404.
   *
   * Shows the API does not depend on the access hook's \Drupal::currentUser().
   */
  public function testPrivateGroupIs404ForListedUser(): void {
    $node = $this->createPrivateGroup('/private-group');
    $this->setCurrentUser($this->listedUser);

    $this->assertSame(404, $this->requestById($node->id())->getStatusCode());
    $this->assertSame(404, $this->requestByPath('/private-group')->getStatusCode());
  }

  /**
   * A private group is absent from the index, for anonymous and listed users.
   */
  public function testPrivateGroupAbsentFromIndex(): void {
    $private = $this->createPrivateGroup('/private-group');
    $public = $this->createPublicGroup();

    $anonymous_nids = $this->indexedNids($this->requestIndex());
    $this->assertContains((int) $public->id(), $anonymous_nids, 'Public group is indexed.');
    $this->assertNotContains((int) $private->id(), $anonymous_nids);

    $this->setCurrentUser($this->listedUser);
    $listed_nids = $this->indexedNids($this->requestIndex());
    $this->assertContains((int) $public->id(), $listed_nids);
    $this->assertNotContains((int) $private->id(), $listed_nids);
  }

  /**
   * Clearing the private flag serves the node and invalidates the index.
   */
  public function testUnprivatingServesNodeAndInvalidatesCachedIndex(): void {
    $node = $this->createPrivateGroup('/was-private');
    $nid = (int) $node->id();

    $index = $this->requestIndex();
    $this->assertNotContains($nid, $this->indexedNids($index));
    $this->assertSame(404, $this->requestById($nid)->getStatusCode());
    $cid = $this->cacheResponse($index, 'access_content_api_test:index');

    $node->set('field_ag_private', 0);
    $node->save();

    // The cached index (which does not list the node, so carries no node:<nid>
    // tag for it) must be invalidated by the bundle list tag alone.
    $this->assertFalse($this->isCached($cid), 'Cached index is invalidated by the save.');
    $this->assertSame(200, $this->requestById($nid)->getStatusCode());
    $this->assertSame(200, $this->requestByPath('/was-private')->getStatusCode());
    $this->assertContains($nid, $this->indexedNids($this->requestIndex()));
  }

  /**
   * Setting the private flag on a served node invalidates its cached response.
   */
  public function testMakingGroupPrivateInvalidatesCachedResponse(): void {
    $node = $this->createPublicGroup();
    $response = $this->requestById($node->id());
    $this->assertSame(200, $response->getStatusCode());
    $cid = $this->cacheResponse($response, 'access_content_api_test:by_id');
    $index_cid = $this->cacheResponse($this->requestIndex(), 'access_content_api_test:index');

    $node->set('field_ag_private', 1);
    $node->save();

    $this->assertFalse($this->isCached($cid), 'Cached per-id response is invalidated.');
    $this->assertFalse($this->isCached($index_cid), 'Cached index is invalidated.');
    $this->assertSame(404, $this->requestById($node->id())->getStatusCode());
    $this->assertNotContains((int) $node->id(), $this->indexedNids($this->requestIndex()));
  }

  /**
   * A field denied to anonymous is absent from the text even for an admin.
   *
   * The API renders as anonymous whoever calls, so the admin sees exactly what
   * a visitor would.
   */
  public function testFieldHiddenFromAnonymousIsAbsentForAdmin(): void {
    $this->createTextBundle('access_news', [
      'body' => 'text_long',
      'field_visible' => ['type' => 'string', 'label' => 'Visible'],
      'field_anon_hidden' => ['type' => 'string', 'label' => 'Hidden'],
    ]);
    $node = $this->createContentNode('access_news', [
      'body' => ['value' => '<p>News body</p>', 'format' => 'basic_html'],
      'field_visible' => 'Visible sentinel value',
      'field_anon_hidden' => 'Anonymous must not see this',
    ]);

    $admin = $this->createUser([], NULL, TRUE);
    // Precondition: field access really differs by caller.
    $this->assertTrue($node->get('field_anon_hidden')->access('view', $admin));
    $this->assertFalse($node->get('field_anon_hidden')->access('view', new AnonymousUserSession()));

    $this->setCurrentUser($admin);
    $data = $this->decode($this->requestById($node->id()));
    $this->assertStringContainsString('Visible sentinel value', $data['text']);
    $this->assertStringNotContainsString('Anonymous must not see this', $data['text']);
    // The current user is restored after rendering.
    $this->assertSame($admin->id(), \Drupal::currentUser()->id());
  }

  /**
   * Responses vary by nothing the caller controls: no user.roles context.
   */
  public function testResponsesLackRolesCacheContext(): void {
    $node = $this->createPublicGroup();
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/public-group'])->save();
    $this->setCurrentUser($this->listedUser);

    $responses = [
      'by id' => $this->requestById($node->id()),
      'by path' => $this->requestByPath('/public-group'),
      'index' => $this->requestIndex(),
    ];
    foreach ($responses as $label => $response) {
      $this->assertSame(200, $response->getStatusCode(), $label);
      $contexts = $response->getCacheableMetadata()->getCacheContexts();
      $this->assertNotContains('user.roles:anonymous', $contexts, "$label has no roles context.");
      $this->assertNotContains('user', $contexts, "$label has no user context.");
      $this->assertNotContains('user.permissions', $contexts, "$label has no permissions context.");
    }
    $this->assertContains('url.site', $responses['by id']->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('url.query_args:path', $responses['by path']->getCacheableMetadata()->getCacheContexts());
  }

}
