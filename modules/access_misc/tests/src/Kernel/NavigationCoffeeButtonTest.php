<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the Coffee "Go to" button added to the Navigation sidebar.
 *
 * Coffee only implements hook_toolbar(), so access_misc adds the button
 * through hook_navigation_content_top() (D8-3104).
 *
 * @group access_misc
 */
class NavigationCoffeeButtonTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'domain',
    // Container dependencies of access_misc's services, as in
    // ReviewRequestedDomainScopeTest.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
    'coffee',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Take uid 1 so the test users below are not superusers.
    $this->createUser();
  }

  /**
   * A user with "access coffee" gets the button wired to Coffee's JS.
   */
  public function testButtonShownWithPermission(): void {
    $this->setCurrentUser($this->createUser(['access coffee']));

    $items = access_misc_navigation_content_top();

    $button = $items['coffee'];
    $this->assertSame('navigation:toolbar-button', $button['#component']);
    $this->assertSame('button', $button['#props']['html_tag']);
    $this->assertSame('Go to', (string) $button['#props']['text']);
    $this->assertContains('toolbar-icon-coffee', $button['#props']['extra_classes']);
    $this->assertContains('collapsible', $button['#props']['modifiers']);
    $this->assertSame(['pack_id' => 'access_misc', 'icon_id' => 'coffee-search'], $button['#props']['icon']);
    $this->assertContains('coffee/drupal.coffee', $button['#attached']['library']);
    $this->assertContains('user.permissions', $button['#cache']['contexts']);
  }

  /**
   * A user without "access coffee" gets only cache metadata, no button.
   */
  public function testButtonHiddenWithoutPermission(): void {
    $this->setCurrentUser($this->createUser([]));

    $items = access_misc_navigation_content_top();

    $this->assertSame(['coffee' => ['#cache' => ['contexts' => ['user.permissions']]]], $items);
  }

  /**
   * The button's icon resolves from the access_misc icon pack.
   */
  public function testIconPackProvidesCoffeeSearchIcon(): void {
    $icon = $this->container->get('plugin.manager.icon_pack')
      ->getIcon('access_misc:coffee-search');

    $this->assertNotNull($icon);
    $this->assertStringEndsWith('assets/icons/coffee-search.svg', (string) $icon->getSource());
  }

}
