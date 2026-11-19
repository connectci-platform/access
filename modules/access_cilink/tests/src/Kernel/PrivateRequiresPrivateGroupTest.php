<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests that a private resource requires a private affinity group.
 *
 * @group access_cilink
 */
class PrivateRequiresPrivateGroupTest extends KbResourceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once $this->root . '/modules/custom/access/modules/access_cilink/access_cilink.module';

    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity group'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_ag_private',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity groups'])->save();
    foreach (['field_ag_private', 'field_affinity_group'] as $name) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * Creates a group tagged with a new term.
   */
  protected function createTaggedGroup(int $private): Term {
    $term = $this->createTerm('affinity_groups', 'Term ' . $this->randomMachineName());
    Node::create([
      'type' => 'affinity_group',
      'title' => 'Group',
      'status' => 1,
      'field_ag_private' => $private,
      'field_affinity_group' => [['target_id' => $term->id()]],
    ])->save();
    return $term;
  }

  /**
   * Runs the validator and returns the form errors.
   *
   * @return array<string, mixed>
   *   The errors keyed by element name.
   */
  protected function validate(int $private, $tids): array {
    $form_state = new FormState();
    $form_state->setValues(['private' => $private, 'affinity_group' => $tids]);
    $form = [];
    access_cilink_private_requires_private_group_validate($form, $form_state);
    return $form_state->getErrors();
  }

  /**
   * A private resource on a public group is rejected.
   */
  public function testPrivateWithPublicGroupFails(): void {
    $term = $this->createTaggedGroup(0);
    $this->assertArrayHasKey('private', $this->validate(1, $term->id()));
  }

  /**
   * A private resource on a private group is accepted.
   */
  public function testPrivateWithPrivateGroupPasses(): void {
    $term = $this->createTaggedGroup(1);
    $this->assertSame([], $this->validate(1, $term->id()));
  }

  /**
   * A private resource needs a group.
   */
  public function testPrivateWithoutGroupFails(): void {
    foreach ([NULL, '', [], 99999] as $tids) {
      $this->assertArrayHasKey('private', $this->validate(1, $tids));
    }
  }

  /**
   * One public group among several tagged groups is rejected.
   */
  public function testPrivateWithAnyPublicGroupFails(): void {
    $term = $this->createTaggedGroup(1);
    // A second group tagged with the same term, but public.
    Node::create([
      'type' => 'affinity_group',
      'title' => 'Second',
      'status' => 1,
      'field_ag_private' => 0,
      'field_affinity_group' => [['target_id' => $term->id()]],
    ])->save();
    $this->assertArrayHasKey('private', $this->validate(1, $term->id()));
  }

  /**
   * A non-private resource is not checked.
   */
  public function testNotPrivateIsNotChecked(): void {
    $term = $this->createTaggedGroup(0);
    $this->assertSame([], $this->validate(0, $term->id()));
    $this->assertSame([], $this->validate(0, NULL));
  }

}
