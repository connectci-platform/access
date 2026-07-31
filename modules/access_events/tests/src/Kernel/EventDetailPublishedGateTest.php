<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Controller\EventDetailApiController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the published/access gate on GET /api/1.0/events/{eventinstance_id}.
 *
 * The detail read applies an IN-CONTROLLER entity-access gate
 * ($eventinstance->access('view', $user, TRUE)) so an unpublished instance is
 * NOT leaked to a stranger, while its owner still sees it. Owner-view of an
 * unpublished instance is granted by access_events_entity_access(), which reads
 * \Drupal::currentUser() — so the owner-positive case MUST run switched (via
 * asActingUser()) for the hook to see the owner as the current user.
 *
 * This is a SEPARATE class from EventDetailApiControllerTest deliberately: the
 * hook only fires when access_events (+ its key/content_moderation/access_misc
 * service deps) is enabled, and access_misc overrides the registrant access
 * handler — which would perturb Task 5's register/cancel tests. Scoping the
 * extra modules here keeps those tests on the untouched base module list.
 *
 * @covers \Drupal\access_events\Controller\EventDetailApiController::get
 * @group access_events
 */
class EventDetailPublishedGateTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The base module list plus the four modules the owner-view hook needs.
   * access_events carries the hook; key + content_moderation + access_misc are
   * hard service-compile dependencies of access_events (its services reference
   * them), so the container will not compile without all four.
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

    // Enabling access_events activates access_events_entity_presave(), which on
    // every eventinstance save reads site-level fields that don't exist in this
    // minimal kernel env: eventseries.domain_access, eventinstance.domain_access,
    // and the three survey fields on the instance. Attach them empty so the
    // hook's reads return empty and its conditional blocks are skipped (we only
    // need the module's entity_access hook, not its domain/survey wiring).
    $this->attachInstancePresaveFields();

    // The contrib EventInstanceAccessControlHandler grants view of a PUBLISHED
    // instance only to accounts holding 'view eventinstance entity'. The base
    // class grants only the registrant permissions, so grant this too or the
    // published-visible-to-stranger assertion would (wrongly) fail closed.
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      ['view eventinstance entity'],
    );
  }

  /**
   * Attaches the empty site-level fields access_events_entity_presave() reads.
   */
  private function attachInstancePresaveFields(): void {
    $fields = [
      // domain_access lives on BOTH the series (read) and the instance
      // (read + optionally set). A plain string multi-value field satisfies the
      // hook's ->getValue()/array_column() with no domain module present.
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      // post_survey_url: the hook reads $instance->get('post_survey_url')->uri
      // (the inherited computed field name, not field_post_survey_url). Attach a
      // plain empty link field under that exact name so the read returns null.
      ['eventinstance', 'post_survey_url', 'link', 1],
      // The two survey-sent flags: the hook reads ->value and may set 0.
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
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Builds a GET request carrying the acting-user attribute and returns get().
   */
  private function getDetail($instance, int $actingUid) {
    $request = Request::create('/api/1.0/events/' . $instance->id());
    $request->attributes->set('acting_user_uid', $actingUid);
    \Drupal::requestStack()->push($request);

    return EventDetailApiController::create(\Drupal::getContainer())
      ->get($instance);
  }

  /**
   * A stranger is refused (404) on an UNPUBLISHED instance — no leak.
   */
  public function testStrangerDeniedOnUnpublished(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('status', 0)->set('uid', $this->owner->id())->save();

    $response = $this->asActingUser(
      $this->stranger,
      fn () => $this->getDetail($instance, (int) $this->stranger->id()),
    );

    $this->assertSame(404, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('not_found', $data['error']);
    // The event body must NOT be present in the refusal.
    $this->assertArrayNotHasKey('id', $data);
    $this->assertArrayNotHasKey('registration', $data);
  }

  /**
   * The owner sees the full body of their own UNPUBLISHED instance.
   *
   * Runs switched so access_events_entity_access() reads currentUser() == owner
   * and returns AccessResult::allowed() for the unpublished instance.
   */
  public function testOwnerSeesUnpublished(): void {
    $instance = $this->createRegistrableInstance(capacity: 60, waitlist: TRUE);
    $instance->set('status', 0)->set('uid', $this->owner->id())->save();

    $response = $this->asActingUser(
      $this->owner,
      fn () => $this->getDetail($instance, (int) $this->owner->id()),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // The full detail body returns, not a refusal.
    $this->assertArrayNotHasKey('error', $data);
    $this->assertSame((string) $instance->id(), (string) $data['id']);
    $this->assertSame('Registrable Event', $data['title']);
    $this->assertArrayHasKey('registration', $data);
  }

  /**
   * A PUBLISHED instance stays visible to a stranger — the gate must not
   * over-gate normal published reads.
   */
  public function testPublishedVisibleToStranger(): void {
    // createRegistrableInstance() creates a published instance by default.
    $instance = $this->createRegistrableInstance();

    $response = $this->asActingUser(
      $this->stranger,
      fn () => $this->getDetail($instance, (int) $this->stranger->id()),
    );

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayNotHasKey('error', $data);
    $this->assertSame((string) $instance->id(), (string) $data['id']);
    $this->assertSame('Registrable Event', $data['title']);
  }

}
