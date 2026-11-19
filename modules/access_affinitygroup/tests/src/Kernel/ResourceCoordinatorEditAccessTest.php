<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests coordinator edit access to resource submissions (D8-2415).
 *
 * A coordinator of the affinity group whose term is on a "resource" webform
 * submission may update that submission. Everyone else falls through to the
 * normal webform access rules.
 *
 * @covers ::access_affinitygroup_entity_access
 * @group access_affinitygroup
 */
class ResourceCoordinatorEditAccessTest extends KernelTestBase {

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
    'access',
    'access_affinitygroup',
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
    $this->installEntitySchema('webform_submission');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'filter', 'user', 'taxonomy', 'webform']);

    NodeType::create([
      'type' => 'affinity_group',
      'name' => 'Affinity Group',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_ag_private',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ag_private',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
    foreach (['field_ag_private_users', 'field_coordinator'] as $userRef) {
      FieldStorageConfig::create([
        'field_name' => $userRef,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => $userRef,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    // access_affinitygroup_entity_presave() fires on every affinity_group save.
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();
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
    foreach (['field_group_slug', 'field_ext_email_list', 'field_list_id'] as $agString) {
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

    // The term reference that populates taxonomy_index on node save.
    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => ['target_bundles' => ['affinity_groups' => 'affinity_groups']],
      ],
    ])->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    foreach (['resource', 'other_form'] as $id) {
      Webform::create([
        'id' => $id,
        'title' => $id,
        'elements' => "affinity_group:\n  '#type': textfield\n",
      ])->save();
    }

    // Burn uid 1 (the superuser, which bypasses access checks).
    $this->createUser();
  }

  /**
   * Creates an affinity group term.
   */
  protected function createTerm(): Term {
    $term = Term::create(['vid' => 'affinity_groups', 'name' => $this->randomMachineName()]);
    $term->save();
    return $term;
  }

  /**
   * Creates a published affinity group node tagged with a term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The tagging term.
   * @param int[] $coordinatorUids
   *   User ids for field_coordinator.
   */
  protected function createGroup(Term $term, array $coordinatorUids): NodeInterface {
    $group = Node::create([
      'type' => 'affinity_group',
      'title' => $term->getName(),
      'status' => 1,
      'field_ag_private' => 0,
      'field_coordinator' => $coordinatorUids,
      'field_affinity_group' => $term->id(),
      'field_group_slug' => 'group-' . $term->id(),
      'field_use_ext_email_list' => 0,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates a submission.
   *
   * @param string $webformId
   *   The webform id.
   * @param int|null $tid
   *   The affinity_group element value, or NULL for none.
   */
  protected function createSubmission(string $webformId, ?int $tid): WebformSubmission {
    $data = $tid === NULL ? [] : ['affinity_group' => $tid];
    $submission = WebformSubmission::create([
      'webform_id' => $webformId,
      'data' => $data,
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Resets the submission access handler's static cache.
   */
  protected function resetAccessCache(): void {
    \Drupal::entityTypeManager()->getAccessControlHandler('webform_submission')->resetCache();
  }

  /**
   * A coordinator of the tagged group can update, with proper cacheability.
   */
  public function testCoordinatorCanUpdate(): void {
    $coordinator = $this->createUser(['access content']);
    $term = $this->createTerm();
    $group = $this->createGroup($term, [(int) $coordinator->id()]);
    $submission = $this->createSubmission('resource', (int) $term->id());

    $result = access_affinitygroup_entity_access($submission, 'update', $coordinator);
    $this->assertTrue($result->isAllowed());
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains($submission->getCacheTags()[0], $result->getCacheTags());
    foreach ($group->getCacheTags() as $tag) {
      $this->assertContains($tag, $result->getCacheTags());
    }

    $this->resetAccessCache();
    $this->assertTrue($submission->access('update', $coordinator));
  }

  /**
   * A non-coordinator gets a neutral result and no overall access.
   */
  public function testNonCoordinatorIsNeutral(): void {
    $coordinator = $this->createUser(['access content']);
    $other = $this->createUser(['access content']);
    $term = $this->createTerm();
    $this->createGroup($term, [(int) $coordinator->id()]);
    $submission = $this->createSubmission('resource', (int) $term->id());

    $result = access_affinitygroup_entity_access($submission, 'update', $other);
    $this->assertFalse($result->isAllowed());
    $this->assertFalse($result->isForbidden());
    $this->assertTrue($result->isNeutral());

    $this->resetAccessCache();
    $this->assertFalse($submission->access('update', $other));
  }

  /**
   * A submission without an affinity_group value is neutral.
   */
  public function testSubmissionWithoutAffinityGroupIsNeutral(): void {
    $coordinator = $this->createUser(['access content']);
    $term = $this->createTerm();
    $this->createGroup($term, [(int) $coordinator->id()]);
    $submission = $this->createSubmission('resource', NULL);

    $result = access_affinitygroup_entity_access($submission, 'update', $coordinator);
    $this->assertTrue($result->isNeutral());

    $this->resetAccessCache();
    $this->assertFalse($submission->access('update', $coordinator));
  }

  /**
   * A group without coordinators is neutral.
   */
  public function testGroupWithoutCoordinatorsIsNeutral(): void {
    $user = $this->createUser(['access content']);
    $term = $this->createTerm();
    $this->createGroup($term, []);
    $submission = $this->createSubmission('resource', (int) $term->id());

    $result = access_affinitygroup_entity_access($submission, 'update', $user);
    $this->assertTrue($result->isNeutral());

    $this->resetAccessCache();
    $this->assertFalse($submission->access('update', $user));
  }

  /**
   * Submissions of other webforms are neutral even for a coordinator.
   */
  public function testOtherWebformIsNeutral(): void {
    $coordinator = $this->createUser(['access content']);
    $term = $this->createTerm();
    $this->createGroup($term, [(int) $coordinator->id()]);
    $submission = $this->createSubmission('other_form', (int) $term->id());

    $result = access_affinitygroup_entity_access($submission, 'update', $coordinator);
    $this->assertTrue($result->isNeutral());

    $this->resetAccessCache();
    $this->assertFalse($submission->access('update', $coordinator));
  }

  /**
   * Only the update operation is granted to coordinators.
   */
  public function testViewAndDeleteAreNotGranted(): void {
    $coordinator = $this->createUser(['access content']);
    $term = $this->createTerm();
    $this->createGroup($term, [(int) $coordinator->id()]);
    $submission = $this->createSubmission('resource', (int) $term->id());

    foreach (['view', 'delete'] as $operation) {
      $result = access_affinitygroup_entity_access($submission, $operation, $coordinator);
      $this->assertFalse($result !== NULL && $result->isAllowed(), "Hook does not grant $operation.");
      $this->resetAccessCache();
      $this->assertFalse($submission->access($operation, $coordinator), "Coordinator cannot $operation.");
    }
  }

  /**
   * A coordinator of a different group cannot update.
   */
  public function testCoordinatorOfDifferentGroupIsNotAllowed(): void {
    $coordinatorA = $this->createUser(['access content']);
    $coordinatorB = $this->createUser(['access content']);
    $termA = $this->createTerm();
    $termB = $this->createTerm();
    $this->createGroup($termA, [(int) $coordinatorA->id()]);
    $this->createGroup($termB, [(int) $coordinatorB->id()]);
    $submission = $this->createSubmission('resource', (int) $termA->id());

    $result = access_affinitygroup_entity_access($submission, 'update', $coordinatorB);
    $this->assertFalse($result->isAllowed());

    $this->resetAccessCache();
    $this->assertFalse($submission->access('update', $coordinatorB));
    $this->resetAccessCache();
    $this->assertTrue($submission->access('update', $coordinatorA));
  }

}
