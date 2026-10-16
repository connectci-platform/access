<?php

namespace Drupal\Tests\access_misc\Unit;

use Drupal\access_misc\Plugin\Util\SiteTools;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Tests\UnitTestCase;

/**
 * Tests SiteTools::buildDomainAwareUrl().
 *
 * Regression test for D8-2778: Domain Source rewrites the canonical URL of a
 * cross-domain entity to an absolute URL, and email builders that prepended a
 * host on top produced doubled links (https://ccmnet.orghttps://ccmnet.org/x).
 *
 * Regression test for OO-113: an event assigned to both ACCESS and the OOD hub
 * was built from the first domain in the field, so the events API served an
 * ACCESS URL to the hub and openondemand.org linked visitors off the hub.
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

  /**
   * Builds a negotiator stub whose active domain has the given id.
   *
   * @param string|null $id
   *   The active domain id, or NULL for no active domain.
   */
  protected function setActiveDomain(?string $id): void {
    $domain = $id === NULL ? NULL : new class($id) {

      /**
       * The domain id.
       */
      public function __construct(protected string $id) {}

      /**
       * {@inheritdoc}
       */
      public function id() {
        return $this->id;
      }

    };

    $negotiator = new class($domain) {

      /**
       * The active domain, or NULL.
       */
      public function __construct(protected $domain) {}

      /**
       * {@inheritdoc}
       */
      public function getActiveDomain() {
        return $this->domain;
      }

    };

    $container = new ContainerBuilder();
    $container->set('domain.negotiator', $negotiator);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the service with a domain storage that maps ids to hosts.
   *
   * @param array $hosts
   *   Map of domain id to the host buildUrl() should produce.
   */
  protected function siteToolsWithDomains(array $hosts): SiteTools {
    $domains = [];
    foreach ($hosts as $id => $host) {
      $domains[$id] = new class($host) {

        /**
         * The host for this domain.
         */
        public function __construct(protected string $host) {}

        /**
         * {@inheritdoc}
         */
        public function buildUrl($path) {
          return $this->host . $path;
        }

      };
    }

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      fn ($id) => $domains[$id] ?? NULL
    );

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('domain')->willReturn($storage);

    return new SiteTools($etm, $this->createMock(Token::class));
  }

  /**
   * Builds an entity stub exposing the given domain_access values.
   */
  protected function entityWithDomains(array $domain_ids) {
    $values = array_map(fn ($id) => ['target_id' => $id], $domain_ids);

    $field = new class($values) {

      /**
       * The field values.
       */
      public function __construct(protected array $values) {}

      /**
       * {@inheritdoc}
       */
      public function getValue() {
        return $this->values;
      }

    };

    return new class($field) {

      /**
       * The domain_access field.
       */
      public function __construct(protected $field) {}

      /**
       * {@inheritdoc}
       */
      public function hasField($name) {
        return $name === 'domain_access';
      }

      /**
       * {@inheritdoc}
       */
      public function get($name) {
        return $this->field;
      }

    };
  }

  /**
   * A shared entity requested on the OOD hub links to the hub.
   *
   * The event is assigned to ACCESS first and the hub second, which is the
   * OO-113 case: the old code took the first domain and served an ACCESS URL.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testActiveDomainWinsWhenEntityIsAssignedToIt(): void {
    $this->setActiveDomain('openondemand_cyberinfrastructure_org');
    $site_tools = $this->siteToolsWithDomains(
      [
        'amp_cyberinfrastructure_org' => 'https://support.access-ci.org',
        'openondemand_cyberinfrastructure_org' => 'https://openondemand.connectci.org',
      ]
    );

    $entity = $this->entityWithDomains([
      'amp_cyberinfrastructure_org',
      'openondemand_cyberinfrastructure_org',
    ]);

    $this->assertSame(
      'https://openondemand.connectci.org/events/9341',
      $site_tools->buildDomainAwareUrl('/events/9341', $entity)
    );
  }

  /**
   * The same shared entity requested on ACCESS still links to ACCESS.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testActiveDomainWinsOnTheOtherDomainToo(): void {
    $this->setActiveDomain('amp_cyberinfrastructure_org');
    $site_tools = $this->siteToolsWithDomains(
      [
        'amp_cyberinfrastructure_org' => 'https://support.access-ci.org',
        'openondemand_cyberinfrastructure_org' => 'https://openondemand.connectci.org',
      ]
    );

    $entity = $this->entityWithDomains([
      'amp_cyberinfrastructure_org',
      'openondemand_cyberinfrastructure_org',
    ]);

    $this->assertSame(
      'https://support.access-ci.org/events/9341',
      $site_tools->buildDomainAwareUrl('/events/9341', $entity)
    );
  }

  /**
   * An entity not on the active domain keeps its own first domain.
   *
   * Nothing in the events API should reach this (the Search API query filters
   * by domain), but buildDomainAwareUrl() is a general helper, so an entity
   * the active domain has no claim on must still get a usable URL.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testFirstDomainUsedWhenActiveDomainHasNoClaim(): void {
    $this->setActiveDomain('ccmnet_org');
    $site_tools = $this->siteToolsWithDomains(
      [
        'amp_cyberinfrastructure_org' => 'https://support.access-ci.org',
        'ccmnet_org' => 'https://ccmnet.org',
      ]
    );

    $entity = $this->entityWithDomains(['amp_cyberinfrastructure_org']);

    $this->assertSame(
      'https://support.access-ci.org/events/9018',
      $site_tools->buildDomainAwareUrl('/events/9018', $entity)
    );
  }

  /**
   * An entity on one domain only resolves to that domain.
   *
   * @covers ::buildDomainAwareUrl
   */
  public function testSingleDomainEntityResolvesToThatDomain(): void {
    $this->setActiveDomain('openondemand_cyberinfrastructure_org');
    $site_tools = $this->siteToolsWithDomains(
      ['openondemand_cyberinfrastructure_org' => 'https://openondemand.connectci.org']
    );

    $entity = $this->entityWithDomains(['openondemand_cyberinfrastructure_org']);

    $this->assertSame(
      'https://openondemand.connectci.org/events/8793',
      $site_tools->buildDomainAwareUrl('/events/8793', $entity)
    );
  }

}
