<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests the lockdown of "Resource allowed on Affinity Group".
 *
 * Only administrators, KB PMs and coordinators of the affinity group(s)
 * tagged with the submission's affinity_group term may set the element.
 *
 * @group access_cilink
 */
class AllowedOnGroupLockdownTest extends KbResourceKernelTestBase {

  const ELEMENT = 'resource_allowed_on_affinity_group';

  const EDIT_FORM = 'webform_submission_resource_edit_form';

  const ADD_FORM = 'webform_submission_resource_add_form';

  /**
   * Term for group A.
   */
  protected Term $termA;

  /**
   * Term for group B.
   */
  protected Term $termB;

  /**
   * Coordinator of group A only.
   */
  protected User $coordA;

  /**
   * Coordinator of group B only.
   */
  protected User $coordB;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once $this->root . '/modules/custom/access/modules/access_cilink/access_cilink.module';

    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity group'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity groups'])->save();
    foreach (['field_coordinator', 'field_affinity_group'] as $name) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
        'label' => $name,
      ])->save();
    }
    Role::create(['id' => 'affinity_group_leader', 'label' => 'Affinity group leader'])->save();

    $this->termA = $this->createTerm('affinity_groups', 'Group A');
    $this->termB = $this->createTerm('affinity_groups', 'Group B');
    $this->coordA = $this->createUser(['affinity_group_leader']);
    $this->coordB = $this->createUser(['affinity_group_leader']);
    $this->createGroup($this->termA, [$this->coordA]);
    $this->createGroup($this->termB, [$this->coordB]);
  }

  /**
   * Sets the current user.
   */
  protected function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Creates an affinity group node.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The tagged term.
   * @param \Drupal\user\Entity\User[] $coordinators
   *   The coordinators.
   */
  protected function createGroup(Term $term, array $coordinators): Node {
    $node = Node::create([
      'type' => 'affinity_group',
      'title' => 'Group ' . $term->label(),
      'status' => 1,
      'field_coordinator' => array_map(fn($user) => ['target_id' => $user->id()], $coordinators),
      'field_affinity_group' => [['target_id' => $term->id()]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds a form state whose form object returns the submission.
   */
  protected function formState(WebformSubmission $submission): FormState {
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($submission);
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    return $form_state;
  }

  /**
   * Returns a minimal form array.
   *
   * @param bool|null $access
   *   An existing #access value on the element, or NULL for none.
   *
   * @return array<string, mixed>
   *   The form.
   */
  protected function form(?bool $access = NULL): array {
    $form = ['elements' => [self::ELEMENT => ['#type' => 'checkbox']]];
    if ($access !== NULL) {
      $form['elements'][self::ELEMENT]['#access'] = $access;
    }
    return $form;
  }

  /**
   * Runs the form alter helper and returns the resulting form.
   *
   * @return array<string, mixed>
   *   The altered form.
   */
  protected function restrict(string $form_id, WebformSubmission $submission, ?bool $access = NULL): array {
    $form = $this->form($access);
    access_cilink_restrict_allowed_on_group($form, $this->formState($submission), $form_id);
    return $form;
  }

  /**
   * Creates a saved resource tagged with a group term.
   */
  protected function taggedResource(Term $term, int $uid, int $allowed = 0): WebformSubmission {
    $submission = $this->createResource([
      'affinity_group' => $term->id(),
      self::ELEMENT => $allowed,
    ], $uid);
    // Reload it as the edit form does, so getOriginalData() holds the stored
    // values the validate handler compares against.
    $storage = $this->container->get('entity_type.manager')->getStorage('webform_submission');
    $storage->resetCache([$submission->id()]);
    return $storage->load($submission->id());
  }

  /**
   * Edit form: the owner cannot see the element.
   */
  public function testEditFormOwnerDenied(): void {
    $owner = $this->createUser();
    $submission = $this->taggedResource($this->termA, (int) $owner->id());
    $this->setCurrentUser($owner);
    $form = $this->restrict(self::EDIT_FORM, $submission);
    $this->assertFalse($form['elements'][self::ELEMENT]['#access'], 'Owner is denied on edit form.');
    $this->assertContains('access_cilink_allowed_on_group_validate', $form['#validate']);
  }

  /**
   * Edit form: a coordinator of another group is denied.
   */
  public function testEditFormOtherGroupCoordinatorDenied(): void {
    $submission = $this->taggedResource($this->termA, (int) $this->createUser()->id());
    $this->setCurrentUser($this->coordB);
    $form = $this->restrict(self::EDIT_FORM, $submission);
    $this->assertFalse($form['elements'][self::ELEMENT]['#access'], 'Coordinator of group B is denied for group A.');
  }

  /**
   * Edit form: coordinator of A, administrator and kb_pm are allowed.
   */
  public function testEditFormPrivilegedAllowed(): void {
    $submission = $this->taggedResource($this->termA, (int) $this->createUser()->id());
    $users = [
      'coordinator of A' => $this->coordA,
      'administrator' => $this->createUser(['administrator']),
      'kb_pm' => $this->createUser(['kb_pm']),
    ];
    foreach ($users as $label => $user) {
      $this->setCurrentUser($user);
      $form = $this->restrict(self::EDIT_FORM, $submission);
      $this->assertTrue($form['elements'][self::ELEMENT]['#access'], "$label is allowed on edit form.");
      $this->assertContains('access_cilink_allowed_on_group_validate', $form['#validate'], "$label: validate handler attached.");
    }
  }

  /**
   * Edit form: an existing #access FALSE is never loosened.
   */
  public function testEditFormExistingAccessFalseKept(): void {
    $submission = $this->taggedResource($this->termA, (int) $this->createUser()->id());
    $this->setCurrentUser($this->coordA);
    $form = $this->restrict(self::EDIT_FORM, $submission, FALSE);
    $this->assertFalse($form['elements'][self::ELEMENT]['#access'], 'Coordinator of A stays denied when webform already hid the element.');
  }

  /**
   * Add form: plain user denied; coordinator and kb_pm allowed.
   */
  public function testAddForm(): void {
    $new = WebformSubmission::create(['webform_id' => 'resource']);
    $cases = [
      'plain user' => [$this->createUser(), FALSE],
      'coordinator of A' => [$this->coordA, TRUE],
      'coordinator of B' => [$this->coordB, TRUE],
      'kb_pm' => [$this->createUser(['kb_pm']), TRUE],
    ];
    foreach ($cases as $label => [$user, $expected]) {
      $this->setCurrentUser($user);
      $form = $this->restrict(self::ADD_FORM, $new);
      $this->assertSame($expected, $form['elements'][self::ELEMENT]['#access'], "$label on add form.");
      $this->assertContains('access_cilink_allowed_on_group_validate', $form['#validate']);
    }
  }

  /**
   * Validate: a value of 0 is untouched and raises no error.
   */
  public function testValidateZeroUntouched(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $form_state = $this->formState(WebformSubmission::create(['webform_id' => 'resource']));
    $form_state->setValues([self::ELEMENT => 0, 'affinity_group' => $this->termA->id()]);
    $form = $this->form();
    access_cilink_allowed_on_group_validate($form, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'No errors for value 0.');
    $this->assertSame(0, $form_state->getValue(self::ELEMENT), 'Value 0 untouched.');
  }

  /**
   * Validate: add form, coordinator of the wrong group gets an error.
   */
  public function testValidateAddFormWrongGroupError(): void {
    $this->setCurrentUser($this->coordB);
    $form_state = $this->formState(WebformSubmission::create(['webform_id' => 'resource']));
    $form_state->setValues([self::ELEMENT => 1, 'affinity_group' => $this->termA->id()]);
    $form = $this->form(TRUE);
    access_cilink_allowed_on_group_validate($form, $form_state);
    $this->assertArrayHasKey(self::ELEMENT, $form_state->getErrors(), 'Coordinator of B ticking for A gets a form error.');
  }

  /**
   * Validate: add form, coordinator of the right group is fine.
   */
  public function testValidateAddFormRightGroupOk(): void {
    $this->setCurrentUser($this->coordA);
    $form_state = $this->formState(WebformSubmission::create(['webform_id' => 'resource']));
    $form_state->setValues([self::ELEMENT => 1, 'affinity_group' => $this->termA->id()]);
    $form = $this->form(TRUE);
    access_cilink_allowed_on_group_validate($form, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'No error for coordinator of A on A.');
    $this->assertSame(1, $form_state->getValue(self::ELEMENT), 'Value stays 1.');
  }

  /**
   * Validate: hidden element keeps the coordinator's earlier approval.
   */
  public function testValidateHiddenKeepsApproval(): void {
    $owner = $this->createUser();
    $submission = $this->taggedResource($this->termA, (int) $owner->id(), 1);
    $this->setCurrentUser($owner);
    $form_state = $this->formState($submission);
    $form_state->setValues([self::ELEMENT => 1, 'affinity_group' => $this->termA->id()]);
    $form = $this->form(FALSE);
    access_cilink_allowed_on_group_validate($form, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'No error when approval preserved.');
    $this->assertSame(1, $form_state->getValue(self::ELEMENT), 'Approval stays 1.');
  }

  /**
   * Validate: moving to another group resets the approval.
   */
  public function testValidateHiddenGroupChangeResets(): void {
    $owner = $this->createUser();
    $submission = $this->taggedResource($this->termA, (int) $owner->id(), 1);
    $this->setCurrentUser($owner);
    // The form entity already holds the submitted values.
    $submission->setElementData('affinity_group', $this->termB->id());
    $form_state = $this->formState($submission);
    $form_state->setValues([self::ELEMENT => 1, 'affinity_group' => $this->termB->id()]);
    $form = $this->form(FALSE);
    access_cilink_allowed_on_group_validate($form, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'No error on silent reset.');
    $this->assertEquals(0, $form_state->getValue(self::ELEMENT), 'Form value reset to 0.');
    $this->assertEquals(0, $submission->getElementData(self::ELEMENT), 'Submission data reset to 0.');
  }

  /**
   * Validate: a forged value on a hidden element is reset.
   */
  public function testValidateHiddenForgedValueReset(): void {
    $owner = $this->createUser();
    $submission = $this->taggedResource($this->termA, (int) $owner->id(), 0);
    $this->setCurrentUser($owner);
    $form_state = $this->formState($submission);
    $form_state->setValues([self::ELEMENT => 1, 'affinity_group' => $this->termA->id()]);
    $form = $this->form(FALSE);
    access_cilink_allowed_on_group_validate($form, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'No error for forged value.');
    $this->assertEquals(0, $form_state->getValue(self::ELEMENT), 'Forged form value reset to 0.');
    $this->assertEquals(0, $submission->getElementData(self::ELEMENT), 'Forged submission data reset to 0.');
  }

  /**
   * Tests access_cilink_normalize_tids().
   */
  public function testNormalizeTids(): void {
    $this->assertSame([5], access_cilink_normalize_tids('5'));
    $this->assertSame([1, 3], access_cilink_normalize_tids([3, '3', 0, NULL, 1]));
    $this->assertSame([], access_cilink_normalize_tids(NULL));
  }

  /**
   * Tests access_cilink_user_can_allow_on_groups() edge cases.
   */
  public function testCanAllowOnGroupsEdgeCases(): void {
    $plain = $this->createUser();
    $this->assertFalse(access_cilink_user_can_allow_on_groups($plain, [9999]), 'No group exists for the term.');
    $this->assertFalse(access_cilink_user_can_allow_on_groups($plain, []), 'No terms.');

    // Two groups share term A; coordA coordinates only the first.
    $this->createGroup($this->termA, [$this->createUser()]);
    $this->assertFalse(access_cilink_user_can_allow_on_groups($this->coordA, [$this->termA->id()]), 'Coordinating only one of two groups tagged with the term is not enough.');
    $this->assertTrue(access_cilink_user_can_allow_on_groups($this->coordB, [$this->termB->id()]), 'Sole coordinator of the only group is allowed.');
  }

  /**
   * Tests access_cilink_user_coordinates_any_group().
   */
  public function testCoordinatesAnyGroup(): void {
    $this->assertTrue(access_cilink_user_coordinates_any_group($this->coordA));
    $this->assertFalse(access_cilink_user_coordinates_any_group($this->createUser()), 'Plain user coordinates nothing.');
  }

}
