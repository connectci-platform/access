<?php

declare(strict_types=1);

namespace Drupal\Tests\ccmnet\Kernel;

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
 * the real value — but only when PANTHEON_ENVIRONMENT == 'live'. On
 * dev/test/multidev that restore never ran, so every non-production edit
 * silently wiped field_domain_source. The fix scopes the '_none' default to
 * the add form only (the edit form now keeps the field's own stored-value
 * default, set by the entity form widget itself — untouched by this hook)
 * and removes the env-gated restore entirely, since it is no longer needed.
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
   * The ADD form still defaults field_domain_source to '_none'.
   */
  public function testAddFormDefaultsDomainSourceToNone(): void {
    $form = $this->baseForm();
    ccmnet_form_alter($form, new FormState(), 'node_mentorship_engagement_form');

    $this->assertSame('_none', $form['field_domain_source']['widget']['#default_value']);
  }

  /**
   * The EDIT form no longer forces field_domain_source to '_none'.
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
   * Ccmnet_set_domain_submit() no longer touches field_domain_source at all.
   *
   * Previously this restored field_domain_source to ccmnet_org, but only
   * when PANTHEON_ENVIRONMENT == 'live' — the env-gated restore that made
   * the add/edit default-value bug above invisible in production while
   * silently wiping the field everywhere else. The restore (and the gate)
   * is now removed entirely, since the edit form no longer needs undoing.
   */
  public function testSetDomainSubmitNoLongerTouchesDomainSource(): void {
    $formState = new FormState();
    $formState->setValue('field_domain_source', [['target_id' => 'original_value']]);
    $formState->setValue('field_mentorship_program', NULL);
    $formState->setValue('field_domain_access', []);

    ccmnet_set_domain_submit([], $formState);

    $this->assertSame(
      [['target_id' => 'original_value']],
      $formState->getValue('field_domain_source'),
    );
  }

}
