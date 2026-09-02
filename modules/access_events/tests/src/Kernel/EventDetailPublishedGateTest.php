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
 * Tests the published/access gate on GET /api/2.3/events/{eventinstance_id}.
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
 * handler — which would perturb the register/cancel tests on the base class.
 * Scoping the extra modules here keeps those tests on the untouched base
 * module list.
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
    // published-visible-to-stranger assertion would (wrongly) fail closed. Grant
    // it to ANONYMOUS as well: a published event is a public read (the anonymous
    // cacheable-response path evaluates access with a null $user, i.e. as the
    // anonymous role), matching production where published events are public.
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      ['view eventinstance entity'],
    );
    $this->grantPermissions(
      Role::load(RoleInterface::ANONYMOUS_ID),
      ['view eventinstance entity'],
    );
  }


  /**
   * Builds a GET request carrying the acting-user attribute and returns get().
   */
  private function getDetail($instance, int $actingUid) {
    $request = Request::create('/api/2.3/events/' . $instance->id());
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
    // Under content_moderation, status is DERIVED from moderation_state on
    // save, not settable directly — transition to draft to go unpublished.
    $instance->set('moderation_state', 'draft')->set('uid', $this->owner->id())->save();

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
    // Under content_moderation, status is DERIVED from moderation_state on
    // save, not settable directly — transition to draft to go unpublished.
    $instance->set('moderation_state', 'draft')->set('uid', $this->owner->id())->save();

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
   * The anonymous response is a cacheable JsonResponse carrying the
   * eventinstance cache tag + the anonymous-roles context, and omits the
   * per-user registration overlay.
   */
  public function testAnonymousResponseIsCacheableWithEventinstanceTag(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('uid', $this->owner->id())->save();
    // Anonymous: no acting_user_uid attribute.
    $request = Request::create('/api/2.3/events/' . $instance->id());
    \Drupal::requestStack()->push($request);
    $response = EventDetailApiController::create(\Drupal::getContainer())->get($instance);
    $this->assertInstanceOf(\Drupal\Core\Cache\CacheableResponseInterface::class, $response);
    $tags = $response->getCacheableMetadata()->getCacheTags();
    $this->assertContains('eventinstance:' . $instance->id(), $tags);
    $contexts = $response->getCacheableMetadata()->getCacheContexts();
    $this->assertContains('user.roles:anonymous', $contexts);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayNotHasKey('already_registered', $data['registration']);
  }

  /**
   * The acting-user response is NOT shared-cacheable: not a
   * CacheableJsonResponse and max-age 0 (private per-user overlay).
   */
  public function testActingUserResponseIsPrivateNotShareable(): void {
    $instance = $this->createRegistrableInstance();
    $response = $this->asActingUser($this->owner, function () use ($instance) {
      $request = Request::create('/api/2.3/events/' . $instance->id());
      $request->attributes->set('acting_user_uid', (int) $this->owner->id());
      \Drupal::requestStack()->push($request);
      return EventDetailApiController::create(\Drupal::getContainer())->get($instance);
    });
    $this->assertNotInstanceOf(\Drupal\Core\Cache\CacheableResponseInterface::class, $response);
    $this->assertSame(0, $response->getMaxAge());
  }

  /**
   * A registrant's cache-tag set includes the eventinstance tag, so registering
   * or cancelling invalidates the cached anonymous seat-count response.
   */
  public function testRegistrationInvalidatesEventinstanceTag(): void {
    $instance = $this->createRegistrableInstance();
    // An UNSAVED Registrant resolves getEventInstance() from its eventinstance_id
    // field, so getCacheTagsToInvalidate() returns the eventinstance tag without a
    // save (avoids the disabled notification/mail side effects a real save fires).
    $registrant = \Drupal\recurring_events_registration\Entity\Registrant::create([
      'bundle' => $instance->getType(),
      'type' => $instance->getType(),
      'user_id' => (int) $this->owner->id(),
      'eventinstance_id' => $instance->id(),
      'eventseries_id' => $instance->get('eventseries_id')->target_id,
      'waitlist' => 0,
    ]);
    $this->assertContains('eventinstance:' . $instance->id(), $registrant->getCacheTagsToInvalidate());
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
