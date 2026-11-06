<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\Routing\Route;

/**
 * Tests the Resources for Affinity Group block (D8-2415).
 *
 * The block lists KB resources on an affinity group page, from the node's
 * resources reference field and from submissions tagged with the group's
 * taxonomy term. Unapproved resources only show on private groups.
 *
 * @coversDefaultClass \Drupal\access_affinitygroup\Plugin\Block\ResourcesForAffinityGroup
 * @group access_affinitygroup
 */
class ResourcesForAffinityGroupTest extends KernelTestBase implements TrustedCallbackInterface {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'taxonomy',
    'webform',
    'block',
    'access',
    'access_affinitygroup',
    'key',
  ];

  /**
   * The text of the approval link.
   */
  const ALLOW_TEXT = 'Allow on this Affinity Group';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'filter', 'user', 'webform']);

    // Stub the heavy skill level service.
    $this->container->set('access_misc.skillLevel', new class() {

      /**
       * Returns no image markup.
       */
      public function getSkillsImage(array $skills): string {
        return '';
      }

    });

    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity Group'])->save();
    foreach (['field_ag_private', 'field_use_ext_email_list'] as $name) {
      $this->addNodeField($name, 'boolean');
    }
    foreach (['field_group_slug', 'field_ext_email_list', 'field_list_id'] as $name) {
      $this->addNodeField($name, 'string');
    }
    foreach ([
      'field_coordinator' => 'user',
      'field_resources_entity_reference' => 'webform_submission',
    ] as $name => $target) {
      $this->addNodeField($name, 'entity_reference', -1, ['target_type' => $target]);
    }
    $this->addNodeField('field_affinity_group', 'entity_reference', 1, ['target_type' => 'taxonomy_term']);
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Needed by access_affinitygroup_entity_presave() on node save.
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();
    Role::create(['id' => 'match_pm', 'label' => 'Match PM'])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();

    Webform::create([
      'id' => 'resource',
      'title' => 'Knowledge Base Resources',
      'elements' => <<<YAML
approved:
  '#type': checkbox
title:
  '#type': textfield
affinity_group:
  '#type': textfield
skill_level:
  '#type': checkboxes
  '#options': {}
tags:
  '#type': checkboxes
  '#options': {}
resource_allowed_on_affinity_group:
  '#type': checkbox
YAML,
    ])->save();

    // Burn uid 1 (the superuser).
    $this->createUser();
  }

  /**
   * Adds a node field to the affinity_group bundle.
   */
  protected function addNodeField(string $name, string $type, int $cardinality = 1, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
  }

  /**
   * Creates an affinity group node and makes it the current route's node.
   */
  protected function createGroup(bool $private, array $referenced = [], array $coordinators = []): NodeInterface {
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Access Group',
      'status' => 1,
      'field_ag_private' => (int) $private,
      'field_group_slug' => 'access-group',
      'field_use_ext_email_list' => 0,
      'field_resources_entity_reference' => $referenced,
      'field_coordinator' => array_map(fn($account) => ['target_id' => $account->id()], $coordinators),
    ]);
    $group->save();

    $route_match = new RouteMatch('entity.node.canonical', new Route('/node/{node}'), ['node' => $group], ['node' => $group->id()]);
    $this->container->set('current_route_match', $route_match);
    return $group;
  }

  /**
   * Creates a resource submission.
   */
  protected function createResource(string $title, array $data = []): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => 'resource',
      'data' => $data + [
        'approved' => 1,
        'title' => $title,
        'tags' => [],
        'skill_level' => [],
        'resource_allowed_on_affinity_group' => 0,
      ],
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Renders the block as the given user and returns the markup.
   */
  protected function renderBlock(?object $account = NULL): string {
    if ($account) {
      $this->setCurrentUser($account);
    }
    $block = $this->container->get('plugin.manager.block');
    assert($block instanceof BlockManagerInterface);
    $renderer = $this->container->get('renderer');
    assert($renderer instanceof RendererInterface);
    // The block calls the renderer itself, so it needs a render context.
    $build = $renderer->executeInRenderContext(new RenderContext(), fn() => $block->createInstance('resources_for_affinity_group')->build());
    return (string) $renderer->renderRoot($build);
  }

  /**
   * Returns the group's taxonomy term id.
   */
  protected function groupTid(NodeInterface $group): string {
    return (string) $group->get('field_affinity_group')->target_id;
  }

  /**
   * Approved resources are listed by tag and by reference.
   */
  public function testApprovedResourcesListed(): void {
    $referenced = $this->createResource('Referenced resource');
    $group = $this->createGroup(FALSE, [['target_id' => $referenced->id()]]);
    $this->createResource('Tagged resource', ['affinity_group' => $this->groupTid($group)]);
    $this->createResource('Unrelated resource', ['affinity_group' => '99999']);

    $html = $this->renderBlock($this->createUser(['access content']));
    $this->assertStringContainsString('Referenced resource', $html);
    $this->assertStringContainsString('Tagged resource', $html);
    $this->assertStringNotContainsString('Unrelated resource', $html);
  }

  /**
   * Unapproved resources on a public group are hidden from everyone.
   */
  public function testUnapprovedHiddenOnPublicGroup(): void {
    $group = $this->createGroup(FALSE);
    $this->createResource('Pending resource', [
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 1,
      'affinity_group' => $this->groupTid($group),
    ]);

    $admin = $this->createUser([], NULL, TRUE);
    $admin->addRole('administrator');
    $admin->save();
    foreach ([$this->createUser(['access content']), $admin] as $account) {
      $this->assertStringNotContainsString('Pending resource', $this->renderBlock($account));
    }
  }

  /**
   * Allowed unapproved resources on a private group show without a link.
   */
  public function testAllowedUnapprovedShownOnPrivateGroup(): void {
    $group = $this->createGroup(TRUE);
    $this->createResource('Allowed resource', [
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 1,
      'affinity_group' => $this->groupTid($group),
    ]);

    $html = $this->renderBlock($this->createUser(['access content']));
    $this->assertStringContainsString('Allowed resource', $html);
    $this->assertStringNotContainsString(self::ALLOW_TEXT, $html);
  }

  /**
   * Creates a private group with one pending, not-allowed resource.
   */
  protected function pendingSetup(array $coordinators = []): NodeInterface {
    $group = $this->createGroup(TRUE, [], $coordinators);
    $this->createResource('Disallowed resource', [
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 0,
      'affinity_group' => $this->groupTid($group),
    ]);
    return $group;
  }

  /**
   * Creates a user with the given roles.
   */
  protected function userWithRole(string $rid) {
    $account = $this->createUser(['access content']);
    $account->addRole($rid);
    $account->save();
    return $account;
  }

  /**
   * Regular users never see not-allowed pending resources.
   */
  public function testDisallowedHiddenFromMembers(): void {
    $this->pendingSetup();
    $this->assertStringNotContainsString('Disallowed resource', $this->renderBlock($this->createUser(['access content'])));
  }

  /**
   * A leader of a different group sees nothing.
   */
  public function testLeaderOfOtherGroupSeesNothing(): void {
    $leader = $this->userWithRole('affinity_group_leader');
    $other = Node::create([
      'type' => 'affinity_group',
      'title' => 'Other',
      'field_group_slug' => 'other',
      'field_use_ext_email_list' => 0,
      'field_coordinator' => [['target_id' => $leader->id()]],
    ]);
    $other->save();
    $this->pendingSetup();
    $html = $this->renderBlock($leader);
    $this->assertStringNotContainsString('Disallowed resource', $html);
    $this->assertStringNotContainsString(self::ALLOW_TEXT, $html);
  }

  /**
   * This group's coordinator sees the resource with the Allow link.
   */
  public function testCoordinatorSeesAllowLink(): void {
    $coordinator = $this->createUser(['access content']);
    $this->pendingSetup([$coordinator]);
    $html = $this->renderBlock($coordinator);
    $this->assertStringContainsString('Disallowed resource', $html);
    $this->assertStringContainsString(self::ALLOW_TEXT, $html);
  }

  /**
   * Match PMs see the resource but get no Allow link.
   */
  public function testMatchPmSeesNoAllowLink(): void {
    $this->pendingSetup();
    $html = $this->renderBlock($this->userWithRole('match_pm'));
    $this->assertStringContainsString('Disallowed resource', $html);
    $this->assertStringNotContainsString(self::ALLOW_TEXT, $html);
  }

  /**
   * Administrators see the resource with the Allow link.
   */
  public function testAdministratorSeesAllowLink(): void {
    $submission_group = $this->pendingSetup();
    $html = $this->renderBlock($this->userWithRole('administrator'));
    $this->assertStringContainsString('Disallowed resource', $html);
    $this->assertStringContainsString(self::ALLOW_TEXT, $html);
    $this->assertStringContainsString('/edit', $html);
    $this->assertNotNull($submission_group->id());
  }

  /**
   * A resource both referenced and tagged is listed once.
   */
  public function testDedupe(): void {
    $resource = $this->createResource('Dupe resource');
    $group = $this->createGroup(FALSE, [['target_id' => $resource->id()]]);
    $resource->setElementData('affinity_group', $this->groupTid($group));
    $resource->save();
    $html = $this->renderBlock($this->createUser(['access content']));
    $this->assertSame(1, substr_count($html, 'Dupe resource'));
  }

  /**
   * Rows are sorted by title, case-insensitively.
   */
  public function testSortedByTitle(): void {
    $group = $this->createGroup(FALSE);
    $tid = $this->groupTid($group);
    foreach (['banana', 'Cherry', 'apple'] as $title) {
      $this->createResource($title, ['affinity_group' => $tid]);
    }
    $html = $this->renderBlock($this->createUser(['access content']));
    $this->assertLessThan(strpos($html, 'banana'), strpos($html, 'apple'));
    $this->assertLessThan(strpos($html, 'Cherry'), strpos($html, 'banana'));
  }

  /**
   * Without an affinity group on the route, output is empty.
   */
  public function testNoNodeOnRoute(): void {
    $this->container->set('current_route_match', new RouteMatch('<none>', new Route('/')));
    $this->assertSame('', trim($this->renderBlock($this->createUser(['access content']))));
  }

  /**
   * Un-ticking private clears the render-cached block.
   */
  public function testRenderCacheInvalidatedByNodeSave(): void {
    $group = $this->createGroup(TRUE);
    $this->createResource('Cached resource', [
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 1,
      'affinity_group' => $this->groupTid($group),
    ]);
    $this->setCurrentUser($this->createUser(['access content']));

    $html = $this->renderCached();
    $this->assertStringContainsString('Cached resource', $html);
    // Same output served from the render cache (stale-able).
    $this->assertStringContainsString('Cached resource', $this->renderCached());

    $group->set('field_ag_private', 0);
    $group->save();
    $this->assertStringNotContainsString('Cached resource', $this->renderCached());
  }

  /**
   * Renders the block with render caching.
   */
  protected function renderCached(): string {
    $element = [
      '#cache' => ['keys' => ['rag_test'], 'contexts' => ['user', 'route']],
      '#pre_render' => [[$this, 'preRender']],
    ];
    return (string) $this->container->get('renderer')->renderRoot($element);
  }

  /**
   * Pre-render callback building the block.
   */
  public function preRender(array $element): array {
    $block = $this->container->get('plugin.manager.block')->createInstance('resources_for_affinity_group');
    $build = $block->build();
    // Keep the block's own cache metadata (the "+" union would drop it).
    $element['#cache'] = array_merge_recursive($element['#cache'], $build['#cache']);
    unset($build['#cache']);
    return $element + $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

  /**
   * The block declares its cache tag and contexts.
   */
  public function testCacheMetadata(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('resources_for_affinity_group');
    $this->assertContains('affinity_group_ci_links', $block->getCacheTags());
    $this->assertContains('route', $block->getCacheContexts());
    $this->assertContains('user', $block->getCacheContexts());
    $this->assertNotContains('user.roles', $block->getCacheContexts());

    $group = $this->createGroup(TRUE);
    $build = $block->build();
    $this->assertContains('node:' . $group->id(), $build['#cache']['tags']);
    $this->assertContains('user', $build['#cache']['contexts']);
  }

}
