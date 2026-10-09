<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Controller\EventSeriesTitleController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\Entity\EventSeriesType;

/**
 * Tests the _admin_route stripping performed by RouteAlterSubscriber.
 *
 * The recurring_events 3.0.0 module builds both public collection routes
 * (entity.eventinstance.collection, entity.eventseries.collection) with
 * options._admin_route: TRUE via
 * \Drupal\Core\Entity\Routing\AdminHtmlRouteProvider (see
 * EventInstanceHtmlRouteProvider / EventSeriesHtmlRouteProvider), and also
 * declares it directly in recurring_events.routing.yml for those two route
 * names. RouteAlterSubscriber runs at RoutingEvents::ALTER priority -200 (after
 * the Views route subscriber, priority -175, takes the paths over for the
 * events_facet and recurring_events_event_series View displays) and strips the
 * flag from just those two routes, so anonymous and privileged visitors alike
 * see /events and /events/series in the default theme.
 *
 * A standalone base (rather than EventKernelTestBase) is used deliberately:
 * this test only needs the router built, not any event/registration fixtures.
 *
 * @covers \Drupal\access_events\EventSubscriber\RouteAlterSubscriber
 * @group access_events
 */
class RouteAlterSubscriberTest extends KernelTestBase {

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
    // access_events.cancellation_notifier needs the notification service
    // recurring_events_registration provides.
    'recurring_events_registration',
    // access_events.services.yml decorates core's access_check.latest_revision
    // service (access_events.latest_revision), which content_moderation
    // provides; workflows is content_moderation's own dependency.
    'workflows',
    'content_moderation',
    // 'access' provides access.access_id_resolver, an access_events
    // dependency; access_affinitygroup is access_events' other declared
    // dependency (and needs key.repository for its xdusage_client service).
    'key',
    'access',
    'access_affinitygroup',
    // access_events.post_survey needs access_misc.sitetools + the Symfony
    // mailer service access_misc provides.
    'access_misc',
    'access_events',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('eventseries');
    $this->installEntitySchema('eventinstance');

    // field_inheritance 3.x installs a `field_inheritance` base field on every
    // entity type named in field_inheritance.config, via its ConfigSubscriber.
    // The module's install default names node/taxonomy_term/block_content/
    // file, whose entity schemas this minimal kernel env does not install —
    // set the site's value directly rather than importing the module default
    // (mirrors EventKernelTestBase::setUp()).
    $this->config('field_inheritance.config')
      ->set('included_entities', ['eventinstance'])
      ->save();
    $this->installConfig(['recurring_events']);

    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * The public eventinstance collection route (/events) has no admin flag.
   */
  public function testEventInstanceCollectionRouteIsNotAdmin(): void {
    $route = \Drupal::service('router.route_provider')
      ->getRouteByName('entity.eventinstance.collection');

    $this->assertNull($route->getOption('_admin_route'));
  }

  /**
   * The public eventseries collection route (/events/series) has no admin flag.
   */
  public function testEventSeriesCollectionRouteIsNotAdmin(): void {
    $route = \Drupal::service('router.route_provider')
      ->getRouteByName('entity.eventseries.collection');

    $this->assertNull($route->getOption('_admin_route'));
  }

  /**
   * Control: the admin-only series listing keeps its admin flag.
   *
   * The entity.eventseries.admin_collection route
   * (/admin/content/events/series) is declared with options._admin_route:
   * TRUE directly in recurring_events.routing.yml and is NOT one of the two
   * routes RouteAlterSubscriber targets, proving the subscriber is not
   * over-broad.
   */
  public function testEventSeriesAdminCollectionRouteStaysAdmin(): void {
    $route = \Drupal::service('router.route_provider')
      ->getRouteByName('entity.eventseries.admin_collection');

    $this->assertTrue($route->getOption('_admin_route'));
  }

  /**
   * Eventseries form routes use the access_events title callbacks.
   *
   * @dataProvider titleCallbackProvider
   */
  public function testTitleCallbacksAreOverridden(string $route_name, string $method): void {
    $route = \Drupal::service('router.route_provider')
      ->getRouteByName($route_name);

    $this->assertSame(
      '\\Drupal\\access_events\\Controller\\EventSeriesTitleController::' . $method,
      $route->getDefault('_title_callback'),
    );
  }

  /**
   * Data provider for testTitleCallbacksAreOverridden().
   */
  public static function titleCallbackProvider(): array {
    return [
      ['entity.eventseries.add_form', 'addPageTitle'],
      ['entity.eventseries.edit_form', 'editPageTitle'],
      ['entity.eventseries.delete_form', 'deletePageTitle'],
      ['entity.eventseries.clone_form', 'clonePageTitle'],
      ['entity.eventseries.add_instance_form', 'addInstanceTitle'],
    ];
  }

  /**
   * Resolved titles contain the label with no placeholder markup.
   */
  public function testTitlesRenderWithoutPlaceholderMarkup(): void {
    $series = EventSeries::create([
      'type' => 'default',
      'title' => 'Test Event',
    ]);
    $type = EventSeriesType::create(['id' => 'default', 'label' => 'Default']);

    $titles = [];
    $controller = EventSeriesTitleController::create(\Drupal::getContainer());
    $titles['edit'] = $controller->editPageTitle($series);
    $titles['delete'] = $controller->deletePageTitle($series);
    $titles['clone'] = $controller->clonePageTitle($series);
    $titles['add_instance'] = $controller->addInstanceTitle($series);
    $titles['add_form'] = $controller->addPageTitle($type);

    foreach ($titles as $name => $title) {
      $string = (string) $title;
      $this->assertStringNotContainsString('<em', $string, $name);
      $this->assertStringNotContainsString('placeholder', $string, $name);
    }
    foreach (['edit', 'delete', 'clone', 'add_instance'] as $name) {
      $this->assertStringContainsString('Test Event', (string) $titles[$name], $name);
    }
    $this->assertStringContainsString('Default', (string) $titles['add_form']);

    // "@" placeholders must still escape the label.
    $series->set('title', '<script>alert(1)</script>');
    $string = (string) $controller->editPageTitle($series);
    $this->assertStringNotContainsString('<script>', $string);
    $this->assertStringContainsString('&lt;script&gt;', $string);
  }

}
