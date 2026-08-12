<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Covers the UX guard on the two contrib entity delete forms.
 *
 * @group access_events
 *
 * Editors should never actually reach the exception page the predelete
 * hooks throw in normal use — access_events_form_alter() disables the
 * confirm button and prints the same has-registrations message up front,
 * on both eventseries_default_delete_form and eventinstance_default_
 * delete_form, whenever EventDeleteGuard::deletionBlockedReason() is
 * non-null for the entity being deleted. The hook-level throws (see
 * EventDeleteGuardHooksTest) remain the backstop for any path that skips
 * this form (API, direct code, a JS-disabled resubmit, ...).
 */
class EventDeleteGuardFormAlterTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'link',
    'datetime',
    'datetime_range',
    'field_inheritance',
    'recurring_events',
    'recurring_events_registration',
    'taxonomy',
    'key',
    'workflows',
    'content_moderation',
    'access_misc',
    'access_events',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }

    if (!FieldStorageConfig::loadByName('eventseries', 'field_other_authors')) {
      FieldStorageConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
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
      ])->save();
    }

    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      [
        'use editorial transition create_new_draft',
        'use editorial transition archived_draft',
        'use editorial transition review_to_review',
        'use editorial transition send_for_review',
      ],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Runs access_events_form_alter() as the delete-form route would.
   *
   * Builds the minimal $form/$form_state/$form_id triple the real
   * eventseries_default_delete_form / eventinstance_default_delete_form
   * routes hand the alter — a confirm-form shell (description + actions.
   * submit, matching ConfirmFormBase::buildForm()) and a FormState whose
   * form object resolves ->getEntity() to $entity, exactly what
   * EntityForm::getEntity() returns for the real contrib delete form
   * objects. This calls the alter function directly rather than round-
   * tripping through \Drupal::formBuilder()->buildForm() against the real
   * contrib EventSeriesDeleteForm/EventInstanceDeleteForm classes, whose
   * constructors trigger an unrelated PHP 8 "optional parameter before
   * required" deprecation (contrib code, not under test here) that
   * Drupal's PHPUnit bridge escalates to a hard test failure.
   */
  private function runFormAlter($entity, string $formId): array {
    $form = [
      '#id' => 'stub-delete-form',
      'description' => ['#markup' => 'Are you sure?'],
      'actions' => [
        '#type' => 'actions',
        'submit' => [
          '#type' => 'submit',
          '#value' => 'Delete',
        ],
      ],
    ];
    $formObject = new class($entity) extends FormBase {

      public function __construct(private $entity) {
      }

      /**
       * Returns the entity under deletion, mirroring EntityForm::getEntity().
       */
      public function getEntity() {
        return $this->entity;
      }

      /**
       * {@inheritdoc}
       */
      public function getFormId() {
        return 'stub_delete_form';
      }

      /**
       * {@inheritdoc}
       */
      public function buildForm(array $form, FormStateInterface $form_state) {
        return $form;
      }

      /**
       * {@inheritdoc}
       */
      public function submitForm(array &$form, FormStateInterface $form_state) {
      }

    };
    $formState = new FormState();
    $formState->setFormObject($formObject);
    access_events_form_alter($form, $formState, $formId);
    return $form;
  }

  /**
   * The series delete form's confirm button is disabled when registered.
   */
  public function testSeriesDeleteFormDisablesSubmitWhenRegistered(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);
    $series = $instance->getEventSeries();

    $form = $this->runFormAlter($series, 'eventseries_default_delete_form');
    $this->assertTrue(!empty($form['actions']['submit']['#disabled']));
    $renderedMessage = (string) (\Drupal::service('renderer')->renderInIsolation($form['access_events_delete_guard_message']) ?? '');
    $this->assertStringContainsString('This event has 1 registration(s) and cannot be deleted.', $renderedMessage);
  }

  /**
   * The series delete form's confirm button is NOT disabled when clean.
   */
  public function testSeriesDeleteFormAllowsSubmitWhenNoRegistrants(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $series = $instance->getEventSeries();

    $form = $this->runFormAlter($series, 'eventseries_default_delete_form');
    $this->assertTrue(empty($form['actions']['submit']['#disabled']));
    $this->assertArrayNotHasKey('access_events_delete_guard_message', $form);
  }

  /**
   * The instance delete form's confirm button is disabled when registered.
   */
  public function testInstanceDeleteFormDisablesSubmitWhenRegistered(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);
    $this->registerUser($this->createUser(), $instance);

    $form = $this->runFormAlter($instance, 'eventinstance_default_delete_form');
    $this->assertTrue(!empty($form['actions']['submit']['#disabled']));
    $renderedMessage = (string) (\Drupal::service('renderer')->renderInIsolation($form['access_events_delete_guard_message']) ?? '');
    $this->assertStringContainsString('This event has 1 registration(s) and cannot be deleted.', $renderedMessage);
  }

  /**
   * The instance delete form's confirm button is NOT disabled when clean.
   */
  public function testInstanceDeleteFormAllowsSubmitWhenNoRegistrants(): void {
    $instance = $this->createRegistrableInstance(capacity: 5);

    $form = $this->runFormAlter($instance, 'eventinstance_default_delete_form');
    $this->assertTrue(empty($form['actions']['submit']['#disabled']));
    $this->assertArrayNotHasKey('access_events_delete_guard_message', $form);
  }

  /**
   * A form object whose entity resolves to NULL no-ops cleanly.
   *
   * GetEntity() is untyped on the interface — an AJAX partial rebuild, or
   * any form object caught mid-build, can legitimately return NULL. Feeding
   * that straight into the strictly-typed EventSeries|EventInstance
   * deletionBlockedReason() would throw a TypeError from inside
   * hook_form_alter, breaking the whole form's render — not just this one
   * guard. Confirm no exception propagates and the form is left untouched.
   */
  public function testFormAlterNoOpsCleanlyWhenEntityIsNull(): void {
    $form = $this->runFormAlter(NULL, 'eventseries_default_delete_form');
    $this->assertTrue(empty($form['actions']['submit']['#disabled']));
    $this->assertArrayNotHasKey('access_events_delete_guard_message', $form);
  }

  /**
   * A form object whose entity is an unrelated type no-ops cleanly.
   *
   * Guards against a future form-id addition to the two conditions in
   * access_events_form_alter() that targets a form whose entity is not an
   * eventseries/eventinstance — the same TypeError risk as the NULL case
   * above, just with a concrete but wrong type instead of an absent one.
   */
  public function testFormAlterNoOpsCleanlyWhenEntityIsUnrelatedType(): void {
    $unrelated = $this->createUser();
    $form = $this->runFormAlter($unrelated, 'eventseries_default_delete_form');
    $this->assertTrue(empty($form['actions']['submit']['#disabled']));
    $this->assertArrayNotHasKey('access_events_delete_guard_message', $form);
  }

}
