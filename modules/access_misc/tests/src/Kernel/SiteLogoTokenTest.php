<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\domain\DomainInterface;
use Drupal\domain\Entity\Domain;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the [access_misc:site_logo] token across domains.
 *
 * The token used to look the domain's theme up through domain_theme_switch's
 * domain_theme_lookup service, which domain_theme_switch 3.x no longer
 * provides, so every call failed and logged an error. Under domain 3.x the
 * per-domain theme is a domain_config override of system.theme, read through
 * plain config.
 *
 * @group access_misc
 */
class SiteLogoTokenTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'dblog',
    'domain',
    // domain_config's language manager needs core's.
    'language',
    'domain_config',
    // Container dependencies of access_misc's services, as in
    // ReviewRequestedDomainScopeTest.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * The default domain, on the global theme.
   */
  protected DomainInterface $defaultDomain;

  /**
   * A second domain whose system.theme override picks claro.
   */
  protected DomainInterface $otherDomain;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    // system.theme.global carries logo.use_default, which theme settings
    // inherit, as on the site.
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['olivero', 'claro']);
    $this->config('system.theme')->set('default', 'olivero')->save();
    // Olivero ships with no logo (logo.use_default: false and no path); the
    // site's themes use their own logo.svg, so put olivero on its default.
    $this->config('olivero.settings')->set('logo.use_default', TRUE)->save();

    $this->defaultDomain = Domain::create([
      'id' => 'one_example_com',
      'hostname' => 'one.example.com',
      'name' => 'One',
      'scheme' => 'https',
      'status' => 1,
      'weight' => 0,
      'is_default' => TRUE,
    ]);
    $this->defaultDomain->save();
    $this->otherDomain = Domain::create([
      'id' => 'two_example_com',
      'hostname' => 'two.example.com',
      'name' => 'Two',
      'scheme' => 'https',
      'status' => 1,
      'weight' => 1,
      'is_default' => FALSE,
    ]);
    $this->otherDomain->save();

    // Where domain 3.x keeps a per-domain override: the domain.<id>
    // collection, under the overridden config name.
    $this->container->get('config.storage')
      ->createCollection('domain.two_example_com')
      ->write('system.theme', ['default' => 'claro']);
  }

  /**
   * The logo follows each active domain's theme.
   */
  public function testLogoFollowsActiveDomainTheme(): void {
    $this->activate($this->otherDomain);
    $this->assertStringEndsWith('core/themes/claro/logo.svg', $this->siteLogo());

    $this->activate($this->defaultDomain);
    $this->assertStringEndsWith('core/themes/olivero/logo.svg', $this->siteLogo());
  }

  /**
   * A theme's custom logo path is used when it is not on the default logo.
   */
  public function testCustomLogoPath(): void {
    $this->config('olivero.settings')
      ->set('logo.use_default', FALSE)
      ->set('logo.path', 'sites/default/files/custom-logo.png')
      ->save();
    $this->activate($this->defaultDomain);

    $this->assertStringEndsWith('sites/default/files/custom-logo.png', $this->siteLogo());
  }

  /**
   * With nothing set active, the default domain's theme is used, quietly.
   */
  public function testNegotiatedDefaultDomainUsesGlobalTheme(): void {
    $this->assertStringEndsWith('core/themes/olivero/logo.svg', $this->siteLogo());

    $errors = $this->container->get('database')->select('watchdog', 'w')
      ->condition('type', 'access_misc')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, (int) $errors);
  }

  /**
   * Makes a domain the active one, as the negotiator does for a request.
   */
  protected function activate(DomainInterface $domain): void {
    $this->container->get('domain.negotiator')->setActiveDomain($domain);
  }

  /**
   * Replaces the token the way a page or mail would.
   */
  protected function siteLogo(): string {
    return (string) $this->container->get('token')->replace('[access_misc:site_logo]');
  }

}
