<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatch;
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
class ResourcesForAffinityGroupTest extends KernelTestBase {

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
  protected function createGroup(bool $private, array $referenced = []): NodeInterface {
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Access Group',
      'status' => 1,
      'field_ag_private' => (int) $private,
      'field_group_slug' => 'access-group',
      'field_use_ext_email_list' => 0,
      'field_resources_entity_reference' => $referenced,
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
   * Disallowed unapproved resources on a private group need a privileged role.
   */
  public function testDisallowedUnapprovedOnPrivateGroup(): void {
    $group = $this->createGroup(TRUE);
    $submission = $this->createResource('Disallowed resource', [
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 0,
      'affinity_group' => $this->groupTid($group),
    ]);

    $this->assertStringNotContainsString('Disallowed resource', $this->renderBlock($this->createUser(['access content'])));

    foreach (['administrator', 'affinity_group_leader', 'match_pm'] as $rid) {
      $account = $this->createUser();
      $account->addRole($rid);
      $account->save();
      $html = $this->renderBlock($account);
      $this->assertStringContainsString('Disallowed resource', $html, $rid);
      $this->assertStringContainsString(self::ALLOW_TEXT, $html, $rid);
      $this->assertStringContainsString('/admin/structure/webform/manage/resource/submission/' . $submission->id() . '/edit', $html, $rid);
    }
  }

  /**
   * The block declares its cache tag and contexts.
   */
  public function testCacheMetadata(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('resources_for_affinity_group');
    $this->assertContains('affinity_group_ci_links', $block->getCacheTags());
    $this->assertContains('route', $block->getCacheContexts());
    $this->assertContains('user.roles', $block->getCacheContexts());
  }

}
