<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP round-trip cache test for GET /api/2.3/events/{eventinstance}.
 *
 * A direct EventDetailApiController::get() call CANNOT catch cache poisoning: it
 * bypasses the router and the RouteAccessResponseSubscriber that bubbles the
 * route-access (ActingUserAccess::resolve) result's cacheability onto the
 * response. That subscriber is exactly where a resolve() setCacheMaxAge(0) would
 * poison the anonymous cache. This test dispatches a REAL request through the
 * HTTP kernel so the full route-access → response-cacheability path runs, and is
 * the regression guard for the Task-1 blocker (resolve() must NOT
 * setCacheMaxAge(0) on its allowed branch).
 *
 * @covers \Drupal\access_events\Controller\EventDetailApiController::get
 * @group access_events
 */
class EventDetailHttpCacheTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The event stack plus access_affinitygroup (carries the
   * acting_user_access:resolve route-access service the event_detail route names)
   * and its key/content_moderation/access_misc service deps — without the whole
   * set the router cannot resolve the route's _custom_access and the dispatch
   * 403s or the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'filter',
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
    'access_affinitygroup',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // access_events_entity_presave() reads site-level fields absent in this
    // minimal env; attach them empty so its reads no-op (same as the gate test).
    $this->attachInstancePresaveFields();

    // Published events are a public read: the anonymous user must hold
    // 'view eventinstance entity' or the in-controller access gate 404s the
    // anonymous request before it can be cached.
    $this->grantPermissions(
      \Drupal\user\Entity\Role::load(\Drupal\user\RoleInterface::ANONYMOUS_ID),
      ['view eventinstance entity'],
    );

    // Rebuild the router so the access_events routes (incl. event_detail) are
    // registered for the http_kernel dispatch below.
    \Drupal::service('router.builder')->rebuild();
  }


  /**
   * An anonymous read dispatched through the real HTTP kernel is cacheable.
   *
   * Would FAIL if ActingUserAccess::resolve() still setCacheMaxAge(0) on its
   * allowed branch — the RouteAccessResponseSubscriber merges that into the
   * response and Cache::mergeMaxAges takes the min, poisoning the cache.
   */
  public function testAnonymousReadIsCacheableThroughTheHttpKernel(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('status', 1)->save();

    // Run the dispatch as the anonymous user (no acting header).
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());
    $request = Request::create('/api/2.3/events/' . $instance->id());
    $response = $this->container->get('http_kernel')->handle($request);

    $this->assertSame(200, $response->getStatusCode());

    // The DIRECT poison guard: DynamicPageCache stores the response's OWN
    // cacheability metadata (tags/contexts/max-age), and REFUSES to store a
    // max-age-0 response. The route-access result (ActingUserAccess::resolve)
    // is merged into this metadata by RouteAccessResponseSubscriber during the
    // dispatch above — so if resolve() setCacheMaxAge(0) on its allowed branch,
    // this metadata max-age would be 0 and the anonymous cache would be poisoned.
    // A permanent (-1) or positive max-age here proves the merge stayed cacheable.
    $this->assertInstanceOf(\Drupal\Core\Cache\CacheableResponseInterface::class, $response);
    $metaMaxAge = $response->getCacheableMetadata()->getCacheMaxAge();
    $this->assertNotSame(0, $metaMaxAge, 'Anonymous read cacheability metadata must not be max-age 0 (resolve() must not setCacheMaxAge(0) on the allowed branch, or DynamicPageCache refuses to store it).');
    // The eventinstance tag survived the route-access merge onto the response.
    $this->assertContains('eventinstance:' . $instance->id(), $response->getCacheableMetadata()->getCacheTags());
  }

}
