<?php

namespace Drupal\Tests\access_outages\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests access_outages_preprocess_layout().
 *
 * The function is loaded directly from the .module file so the test does not
 * need to enable the full dependency tree of the access module.
 *
 * @group access_outages
 */
#[RunTestsInSeparateProcesses]
class OutagesPreprocessLayoutTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    require_once __DIR__ . '/../../../access_outages.module';

    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity group'])->save();
    NodeType::create(['type' => 'access_resource', 'name' => 'Resource'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'bundle' => 'access_resource',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
      'settings' => [
        'handler' => 'default:node',
        'handler_settings' => ['target_bundles' => ['access_resource' => 'access_resource']],
      ],
    ])->save();
  }

  /**
   * Creates a CIDER resource node with the given global resource ID.
   */
  protected function createResource(string $id): Node {
    $node = Node::create([
      'type' => 'access_resource',
      'title' => "Resource $id",
      'field_access_global_resource_id' => $id,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Runs the preprocess function against an entity-backed layout.
   *
   * @return array<string, mixed>
   *   The altered variables.
   */
  protected function preprocess(?Node $entity): array {
    $vars = ['content' => $entity ? ['#entity' => $entity] : []];
    access_outages_preprocess_layout($vars);
    return $vars;
  }

  /**
   * A layout without #entity (e.g. Navigation sidebar) is left untouched.
   */
  public function testLayoutWithoutEntityAttachesNothing(): void {
    $vars = $this->preprocess(NULL);
    $this->assertArrayNotHasKey('#attached', $vars);
  }

  /**
   * Nodes that are not affinity groups are left untouched.
   */
  public function testNonAffinityGroupAttachesNothing(): void {
    $vars = $this->preprocess($this->createResource('r1'));
    $this->assertArrayNotHasKey('#attached', $vars);
  }

  /**
   * An affinity group with no CIDER resources attaches nothing.
   */
  public function testAffinityGroupWithoutResourcesAttachesNothing(): void {
    $group = Node::create(['type' => 'affinity_group', 'title' => 'Empty']);
    $group->save();
    $vars = $this->preprocess($group);
    $this->assertArrayNotHasKey('#attached', $vars);
  }

  /**
   * An affinity group with CIDER resources gets the library and cider IDs.
   */
  public function testAffinityGroupWithResourcesAttachesLibraryAndIds(): void {
    $a = $this->createResource('a.example.org');
    $b = $this->createResource('b.example.org');
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Group',
      'field_cider_resources' => [
        ['target_id' => $a->id()],
        ['target_id' => $b->id()],
      ],
    ]);
    $group->save();

    $vars = $this->preprocess($group);
    $this->assertSame(['access_outages/outages_library'], $vars['#attached']['library']);
    $this->assertSame(
      ['ciderIds' => ['a.example.org', 'b.example.org']],
      $vars['#attached']['drupalSettings']
    );
  }

  /**
   * References to deleted CIDER nodes are skipped.
   */
  public function testDeletedResourceReferenceIsSkipped(): void {
    $a = $this->createResource('a.example.org');
    $b = $this->createResource('b.example.org');
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => 'Group',
      'field_cider_resources' => [
        ['target_id' => $a->id()],
        ['target_id' => $b->id()],
      ],
    ]);
    $group->save();
    $a->delete();

    $vars = $this->preprocess($group);
    $this->assertSame(['ciderIds' => ['b.example.org']], $vars['#attached']['drupalSettings']);

    // With every referenced resource gone, nothing is attached.
    $b->delete();
    $vars = $this->preprocess($group);
    $this->assertArrayNotHasKey('#attached', $vars);
  }

}
