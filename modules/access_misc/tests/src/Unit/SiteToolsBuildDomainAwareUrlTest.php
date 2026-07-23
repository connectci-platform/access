<?php

namespace Drupal\Tests\access_misc\Unit;

use Drupal\access_misc\Plugin\Util\SiteTools;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the absolute-URL guard in SiteTools::buildDomainAwareUrl().
 *
 * Regression test for D8-2778: Domain Source rewrites the canonical URL of a
 * cross-domain entity to an absolute URL, and email builders that prepended a
 * host on top produced doubled links (https://ccmnet.orghttps://ccmnet.org/x).
 *
 * @coversDefaultClass \Drupal\access_misc\Plugin\Util\SiteTools
 * @group access_misc
 */
class SiteToolsBuildDomainAwareUrlTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected SiteTools $siteTools;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->siteTools = new SiteTools(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Token::class)
    );
  }

  /**
   * Absolute URLs pass through unchanged — no host prepended.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testAbsoluteUrlIsReturnedUnchanged(): void {
    $url = 'https://ccmnet.org/mentorship-engagement/some-engagement';
    $this->assertSame($url, $this->siteTools->buildDomainAwareUrl($url));
  }

  /**
   * Protocol-relative URLs also count as absolute and pass through.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testProtocolRelativeUrlIsReturnedUnchanged(): void {
    $url = '//ccmnet.org/mentorship-engagement/some-engagement';
    $this->assertSame($url, $this->siteTools->buildDomainAwareUrl($url));
  }

  /**
   * Relative paths still get a host prepended (support fallback here).
   *
   * With no entity and no active domain, the method falls through to the
   * final support.access-ci.org fallback — proving relative input still
   * takes the prepend path rather than the early return.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testRelativePathGetsHostPrepended(): void {
    $negotiator = new class {

      /**
       * No active domain, forcing the final fallback.
       */
      public function getActiveDomain() {
        return NULL;
      }

    };
    $container = new ContainerBuilder();
    $container->set('domain.negotiator', $negotiator);
    \Drupal::setContainer($container);

    $this->assertSame(
      'https://support.access-ci.org/events/9018',
      $this->siteTools->buildDomainAwareUrl('/events/9018')
    );
  }

}
