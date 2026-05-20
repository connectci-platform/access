<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\access\EligibilityState;
use Drupal\KernelTests\KernelTestBase;

/**
 * @coversDefaultClass \Drupal\access\Plugin\Block\EligibilityBannerBlock
 * @group access
 */
class EligibilityBannerBlockTest extends KernelTestBase {

  // Note: enabling `access` will also pull in anything declared in
  // `access.info.yml` dependencies (e.g. `access_llm`). On first run,
  // if PHPUnit complains about missing services or schemas, mirror the
  // module list used by `RpAccountServiceTest` (in access_affinitygroup)
  // and add any missing dependencies here.
  protected static $modules = ['system', 'user', 'block', 'access', 'access_affinitygroup', 'key'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  private function buildBlock(): array {
    $blockManager = $this->container->get('plugin.manager.block');
    $plugin = $blockManager->createInstance('eligibility_banner_block', []);
    return $plugin->build();
  }

  public function testRendersNothingWhenUnknown(): void {
    // State default is unknown.
    $build = $this->buildBlock();
    $this->assertEmpty($build);
  }

  public function testRendersNothingWhenEligible(): void {
    $this->container->get('access.eligibility_state')->setEligible();
    $build = $this->buildBlock();
    $this->assertEmpty($build);
  }

  public function testRendersBannerWhenIneligible(): void {
    $this->container->get('access.eligibility_state')
      ->setIneligible('Country of Residence is not set.');
    $build = $this->buildBlock();
    $this->assertNotEmpty($build);
    $this->assertSame('inline_template', $build['#type']);
    $this->assertStringContainsString(
      'Country of Residence is not set.',
      $build['#context']['reason']
    );
    $this->assertSame(
      'https://allocations.access-ci.org/profile',
      $build['#context']['profile_url']
    );
  }

  public function testCacheMaxAgeIsZero(): void {
    $blockManager = $this->container->get('plugin.manager.block');
    $plugin = $blockManager->createInstance('eligibility_banner_block', []);
    $this->assertSame(0, $plugin->getCacheMaxAge());
  }

}
