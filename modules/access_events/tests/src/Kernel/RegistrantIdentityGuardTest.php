<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events_registration\Entity\Registrant;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the registrant-identity guard in access_events_form_alter().
 *
 * @group access_events
 *
 * On registrant_default_add_form / registrant_default_edit_form,
 * non-administrators have field_first_name/field_last_name/email/user_id
 * forced to their OWN profile identity and hidden — this is the intended
 * self-registration UX. Before D8-2825's related fix, that block ran
 * unconditionally for any non-admin viewing ANY registrant's edit form,
 * which meant an editor granted the (currently ungranted) 'edit registrant
 * entities' permission would silently overwrite someone else's name/email
 * with their own on save. The guard added alongside the fix skips the
 * overwrite unless the registrant is new or already owned by the current
 * user, so a future permission grant does not reintroduce that data loss.
 */
class RegistrantIdentityGuardTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // field_first_name / field_last_name are config fields on the registrant
    // "default" bundle (ships in recurring_events_registration's config/
    // install), not base fields, and the shared EventKernelTestBase
    // deliberately avoids installing that module's full config (see its
    // setUp() comment) — so create just these two directly.
    foreach (['field_first_name', 'field_last_name'] as $fieldName) {
      if (!FieldStorageConfig::loadByName('registrant', $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => 'registrant',
          'field_name' => $fieldName,
          'type' => 'string',
        ])->save();
        FieldConfig::create([
          'entity_type' => 'registrant',
          'field_name' => $fieldName,
          'bundle' => 'default',
        ])->save();
      }
    }

    // field_user_first_name / field_user_last_name are the site's config
    // fields on the user entity that access_events_form_alter() reads to
    // populate the self-registration defaults.
    foreach (['field_user_first_name', 'field_user_last_name'] as $fieldName) {
      if (!FieldStorageConfig::loadByName('user', $fieldName)) {
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
    }

    // field_event_allocation_grant is a site-level string field on
    // eventseries that the same form_alter block reads (after the identity
    // guard) to decide whether to show allocation messaging. Leaving it
    // unset (empty string) keeps that branch a no-op, which is all these
    // tests need.
    if (!FieldStorageConfig::loadByName('eventseries', 'field_event_allocation_grant')) {
      FieldStorageConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_event_allocation_grant',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'entity_type' => 'eventseries',
        'field_name' => 'field_event_allocation_grant',
        'bundle' => 'default',
      ])->save();
    }

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Reload: $this->owner/$this->stranger were instantiated (in the parent
    // setUp()) before the fields above existed, so their in-memory field
    // definitions predate them.
    $this->owner = User::load($this->owner->id());
    $this->stranger = User::load($this->stranger->id());

    $this->owner->set('field_user_first_name', 'Jamie')->set('field_user_last_name', 'Owner')->save();
    $this->stranger->set('field_user_first_name', 'Sam')->set('field_user_last_name', 'Stranger')->save();
  }

  /**
   * Runs access_events_form_alter() as $actingUser, $entity as the form entity.
   *
   * Builds the minimal $form/$form_state/$form_id triple the real
   * registrant_default_add_form / registrant_default_edit_form routes hand
   * the alter (mirrors EventDeleteGuardFormAlterTest::runFormAlter()), and
   * pushes a /events/{instance}/... request so the hook's own
   * getRequestUri() parsing resolves the eventinstance id, exactly as the
   * real route does.
   */
  private function runFormAlter($entity, string $formId, User $actingUser, EventInstance $instance): array {
    $request = Request::create('/events/' . $instance->id() . '/register');
    // Carry over the kernel bootstrap's session, so pushing this request does
    // not trigger the "session-less request on the stack" deprecation.
    $request->setSession(\Drupal::requestStack()->getCurrentRequest()->getSession());
    \Drupal::requestStack()->push($request);

    $form = [
      // access_events_form_alter() unconditionally reads $form['#id'] first,
      // for an unrelated views-exposed-form check.
      '#id' => 'stub-registrant-form',
      'actions' => [
        '#type' => 'actions',
        'submit' => [
          '#type' => 'submit',
          '#value' => 'Register',
        ],
      ],
    ];
    $formObject = new class($entity) extends FormBase {

      public function __construct(private $entity) {
      }

      /**
       * Returns the registrant under edit, mirroring EntityForm::getEntity().
       */
      public function getEntity() {
        return $this->entity;
      }

      /**
       * {@inheritdoc}
       */
      public function getFormId() {
        return 'stub_registrant_form';
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

    return $this->asActingUser($actingUser, function () use (&$form, $formState, $formId) {
      access_events_form_alter($form, $formState, $formId);
      return $form;
    });
  }

  /**
   * A non-admin's ADD form defaults to (and locks) their own identity.
   */
  public function testAddFormSetsOwnIdentityForNonAdmin(): void {
    $instance = $this->createRegistrableInstance();
    $registrant = Registrant::create([
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'type' => 'default',
    ]);

    $form = $this->runFormAlter($registrant, 'registrant_default_add_form', $this->owner, $instance);

    $this->assertSame('Jamie', $form['field_first_name']['widget'][0]['value']['#default_value']);
    $this->assertFalse($form['field_first_name']['widget'][0]['value']['#access']);
    $this->assertSame('Owner', $form['field_last_name']['widget'][0]['value']['#default_value']);
    $this->assertFalse($form['field_last_name']['widget'][0]['value']['#access']);
    $this->assertSame('owner@example.com', $form['email']['widget'][0]['value']['#default_value']);
    $this->assertFalse($form['email']['widget'][0]['value']['#access']);
    $this->assertFalse($form['user_id']['widget'][0]['#access']);
  }

  /**
   * A non-admin editing their OWN registrant still gets the identity lock.
   *
   * Unchanged behavior: the stored (possibly stale) first/last/email is
   * overwritten with the current profile values on every edit, same as
   * before the guard was added — this is the intentional self-registration
   * round-trip, not the data-loss path being guarded against.
   */
  public function testEditFormOwnRegistrantIdentityOverwritten(): void {
    $instance = $this->createRegistrableInstance();
    $registrant = Registrant::create([
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'type' => 'default',
      'user_id' => $this->owner->id(),
      'field_first_name' => 'Stale',
      'field_last_name' => 'Data',
      'email' => 'stale@example.com',
    ]);
    $registrant->save();

    // The request URI is only used by the hook to look up the eventinstance
    // for its unrelated allocation-grant messaging and "already registered"
    // redirect. Point it at a SECOND, unrelated instance the owner is not
    // registered for, so that redirect (which would print output PHPUnit
    // treats as a risky-test failure) does not fire — it is orthogonal to
    // the identity guard under test here.
    $urlInstance = $this->createRegistrableInstance();
    $form = $this->runFormAlter($registrant, 'registrant_default_edit_form', $this->owner, $urlInstance);

    $this->assertSame('Jamie', $form['field_first_name']['widget'][0]['value']['#default_value']);
    $this->assertFalse($form['field_first_name']['widget'][0]['value']['#access']);
    $this->assertSame('Owner', $form['field_last_name']['widget'][0]['value']['#default_value']);
    $this->assertFalse($form['field_last_name']['widget'][0]['value']['#access']);
    $this->assertSame('owner@example.com', $form['email']['widget'][0]['value']['#default_value']);
    $this->assertFalse($form['email']['widget'][0]['value']['#access']);
  }

  /**
   * A non-admin editing SOMEONE ELSE's registrant no longer overwrites it.
   *
   * This is the D8-2825 regression guard: no role currently holds the
   * cross-user 'edit registrant entities' permission, so this form is not
   * reachable this way in production today — but the hook itself must not
   * silently clobber another person's registration data if such a
   * permission is ever granted. Simulates that by invoking the alter
   * directly as a non-admin against a registrant owned by someone else.
   */
  public function testEditFormOtherUsersRegistrantNotOverwritten(): void {
    $instance = $this->createRegistrableInstance();
    $registrant = Registrant::create([
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'type' => 'default',
      'user_id' => $this->stranger->id(),
      'field_first_name' => 'Sam',
      'field_last_name' => 'Stranger',
      'email' => 'stranger@example.com',
    ]);
    $registrant->save();

    $form = $this->runFormAlter($registrant, 'registrant_default_edit_form', $this->owner, $instance);

    $this->assertArrayNotHasKey('#default_value', $form['field_first_name']['widget'][0]['value'] ?? []);
    $this->assertArrayNotHasKey('#access', $form['field_first_name']['widget'][0]['value'] ?? []);
    $this->assertArrayNotHasKey('#default_value', $form['field_last_name']['widget'][0]['value'] ?? []);
    $this->assertArrayNotHasKey('#default_value', $form['email']['widget'][0]['value'] ?? []);
    $this->assertArrayNotHasKey('#access', $form['user_id']['widget'][0] ?? []);
  }

}
