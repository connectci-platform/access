<?php

declare(strict_types=1);

namespace Drupal\Tests\ccmnet\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Covers the D8-2825 fix in ccmnet.module.
 *
 * @group ccmnet
 *
 * Before the fix, ccmnet_form_alter() forced field_domain_source's widget
 * default to '_none' on BOTH the add and edit forms for
 * mentorship_engagement nodes, and only ccmnet_set_domain_submit() restored
 * the real value, and only when PANTHEON_ENVIRONMENT == 'live'. On
 * dev/test/multidev that restore never ran, so every non-production edit
 * silently wiped field_domain_source. A later attempt defaulted the add form
 * to ccmnet_org, but that broke real saves for non-admins:
 * domain_source_form_validate() requires the source to be in
 * field_domain_access at validate time, and domain_access only merges the
 * hidden current domain in during submit. So the form alter sets no widget
 * default on either form, and ccmnet_set_domain_submit() defaults an empty
 * field_domain_source to ccmnet_org for NEW nodes only, with no env gate.
 * Existing nodes and explicitly chosen sources are left alone.
 */
class CcmnetFormAlterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // ccmnet.info.yml depends on the access module, whose own dependency
    // chain (access_affinitygroup, access_llm, key, ...) is unrelated
    // machinery this test does not exercise and would otherwise need to be
    // fully installed just to satisfy the DI container compile. The two
    // functions under test are plain procedural code with no install hooks
    // of their own, so load the file directly instead of enabling the
    // module (and its dependency chain) through Drupal's module system.
    require_once \Drupal::root() . '/modules/custom/access/modules/ccmnet/ccmnet.module';
    // ccmnet_set_domain_submit() references SiteTools::DOMAIN_CAMPUS_CHAMPIONS;
    // load the class directly for the same reason (avoids enabling
    // access_misc, whose own dependency chain is equally unrelated here).
    require_once \Drupal::root() . '/modules/custom/access/modules/access_misc/src/Plugin/Util/SiteTools.php';

    $this->installEntitySchema('user');

    // field_user_first_name / field_user_last_name are the site's config
    // fields on the user entity that ccmnet_form_alter() reads to build the
    // "First Last (uid)" author string for the mentorship form.
    foreach (['field_user_first_name', 'field_user_last_name'] as $fieldName) {
      FieldStorageConfig::create([
        'entity_type' => 'user',
        'field_name' => $fieldName,
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'entity_type' => 'user',
        'field_name' => $fieldName,
        'bundle' => 'user',
      ])->save();
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    $user = User::create([
      'name' => 'author',
      'mail' => 'author@example.com',
      'status' => 1,
      'field_user_first_name' => 'Ada',
      'field_user_last_name' => 'Lovelace',
    ]);
    $user->save();
    \Drupal::currentUser()->setAccount($user);

    // ccmnet_form_alter() calls the real access_misc.addtags service (which
    // renders a Views block) purely to build unrelated markup for this form;
    // stub it out so the test does not need a real 'node_add_tags' view.
    \Drupal::getContainer()->set('access_misc.addtags', new class {

      /**
       * Stub replacing NodeAddTags::getView(), unrelated to what's tested.
       */
      public function getView() {
        return '';
      }

    });

    // Likewise stub access_misc.tag_suggester, which ccmnet_form_alter()
    // calls to attach the tag suggestion panel — unrelated to what's tested.
    \Drupal::getContainer()->set('access_misc.tag_suggester', new class {

      /**
       * Stub replacing TagSuggester::build(), unrelated to what's tested.
       */
      public function build(array &$form, array $options = []): void {}

    });
  }

  /**
   * Builds the minimal mentorship_engagement form array the hook expects.
   *
   * The field_ccmnet_approved default is left FALSE, taking the (simpler)
   * goals-and-deliverables-hidden branch — orthogonal to the
   * field_domain_source behavior under test.
   */
  private function baseForm(): array {
    return [
      'field_ccmnet_approved' => [
        'widget' => [
          'value' => ['#default_value' => 0],
        ],
      ],
      'title' => ['widget' => [0 => []]],
      'body' => ['widget' => [0 => ['summary' => []]]],
      'field_domain_source' => ['widget' => []],
      'field_me_looking_for' => ['widget' => []],
      'actions' => ['submit' => ['#submit' => []]],
    ];
  }

  /**
   * The ADD form no longer sets a default on the domain source widget.
   *
   * The ccmnet_org default is applied at submit time instead (see below).
   */
  public function testAddFormLeavesDomainSourceDefaultUnset(): void {
    $form = $this->baseForm();
    ccmnet_form_alter($form, new FormState(), 'node_mentorship_engagement_form');

    $this->assertArrayNotHasKey('#default_value', $form['field_domain_source']['widget']);
  }

  /**
   * The EDIT form no longer forces field_domain_source's default.
   *
   * This is the core regression guard: previously this ran unconditionally
   * for the edit form too, wiping the field's real stored value on every
   * edit outside the live environment.
   */
  public function testEditFormLeavesDomainSourceDefaultAlone(): void {
    $form = $this->baseForm();
    ccmnet_form_alter($form, new FormState(), 'node_mentorship_engagement_edit_form');

    $this->assertArrayNotHasKey('#default_value', $form['field_domain_source']['widget']);
  }

  /**
   * Runs ccmnet_set_domain_submit() and returns the resulting source value.
   *
   * @param bool $isNew
   *   Whether the mocked form entity is new.
   * @param mixed $value
   *   The submitted field_domain_source value.
   *
   * @return mixed
   *   The field_domain_source value after the handler ran.
   */
  private function runSubmit(bool $isNew, mixed $value): mixed {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('isNew')->willReturn($isNew);
    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->method('getEntity')->willReturn($entity);

    $formState = new FormState();
    $formState->setFormObject($formObject);
    $formState->setValue('field_domain_source', $value);
    $formState->setValue('field_mentorship_program', NULL);
    $formState->setValue('field_domain_access', []);

    ccmnet_set_domain_submit([], $formState);

    return $formState->getValue('field_domain_source');
  }

  /**
   * Empty submitted values for field_domain_source.
   */
  public static function emptyValueProvider(): array {
    return [
      'no value' => [[]],
      'empty target_id' => [[['target_id' => '']]],
      'null target_id' => [[['target_id' => NULL]]],
    ];
  }

  /**
   * A NEW node with an empty domain source gets ccmnet_org on submit.
   *
   * @dataProvider emptyValueProvider
   */
  public function testSetDomainSubmitDefaultsNewEntityToCcmnet(array $value): void {
    $this->assertSame([['target_id' => 'ccmnet_org']], $this->runSubmit(TRUE, $value));
  }

  /**
   * A NEW node with an explicitly chosen domain source is left alone.
   */
  public function testSetDomainSubmitKeepsExplicitValueOnNewEntity(): void {
    $value = [['target_id' => 'other_org']];
    $this->assertSame($value, $this->runSubmit(TRUE, $value));
  }

  /**
   * An EXISTING node with an empty domain source is not defaulted.
   *
   * @dataProvider emptyValueProvider
   */
  public function testSetDomainSubmitLeavesEmptyValueOnExistingEntity(array $value): void {
    $this->assertSame($value, $this->runSubmit(FALSE, $value));
  }

  /**
   * An EXISTING node keeps its stored domain source.
   */
  public function testSetDomainSubmitKeepsStoredValueOnExistingEntity(): void {
    $value = [['target_id' => 'original_value']];
    $this->assertSame($value, $this->runSubmit(FALSE, $value));
  }

  /**
   * With a non-entity form object the handler leaves the field alone.
   */
  public function testSetDomainSubmitWithNonEntityFormDoesNothing(): void {
    $formState = new FormState();
    $formState->setFormObject($this->createMock(FormInterface::class));
    $formState->setValue('field_domain_source', []);
    $formState->setValue('field_mentorship_program', NULL);
    $formState->setValue('field_domain_access', []);

    ccmnet_set_domain_submit([], $formState);

    $this->assertSame([], $formState->getValue('field_domain_source'));
  }

}
