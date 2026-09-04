<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Unit;

use Drupal\access_events\EventDomainContext;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\domain\DomainInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the domain switch/restore mechanics in isolation.
 *
 * @coversDefaultClass \Drupal\access_events\EventDomainContext
 * @group access_events
 */
class EventDomainContextTest extends UnitTestCase {

  /**
   * The switch points the request context at the domain's scheme and host.
   *
   * This is the part that actually fixes the link hosts —
   * \Drupal\Core\Routing\UrlGenerator::doGenerate() builds an absolute URL
   * from exactly these context values.
   *
   * @covers ::forDomain
   */
  public function testForDomainPointsTheRequestContextAtTheDomain(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $service = new EventDomainContext($context, $this->negotiator());

    $seen = [];
    $service->forDomain($this->domain('event-site.example.com', 'https'), function () use ($context, &$seen): void {
      $seen = [
        'scheme' => $context->getScheme(),
        'host' => $context->getHost(),
        'baseUrl' => $context->getCompleteBaseUrl(),
      ];
    });

    $this->assertSame('https', $seen['scheme']);
    $this->assertSame('event-site.example.com', $seen['host']);
    $this->assertSame('https://event-site.example.com', $seen['baseUrl']);
  }

  /**
   * The negotiator is actually pointed at the domain WHILE the callback runs.
   *
   * The other tests here only see the request context, or only see the
   * negotiator once the callback has already returned (post-restore). None
   * of them would notice if the `$this->negotiator?->setActiveDomain($domain)`
   * call inside the try{} block were deleted: forDomain() would still switch
   * the request context, still run the callback, and still hit the restore
   * in finally{} — so link hosts would keep passing while mail transport and
   * From-address selection (which read the negotiator's active domain, not
   * the request context) silently broke. This asserts what the callback
   * itself observes.
   *
   * @covers ::forDomain
   */
  public function testForDomainPointsTheNegotiatorAtTheDomainDuringTheCallback(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $domain = $this->domain('event-site.example.com', 'https');

    $active = NULL;
    $negotiator = $this->createMock(DomainNegotiatorInterface::class);
    // A regular closure, not `fn()`: arrow functions capture by value at
    // definition time, which would freeze $active at NULL forever.
    $negotiator->method('getActiveDomain')->willReturnCallback(function () use (&$active) {
      return $active;
    });
    $negotiator->expects($this->once())->method('setActiveDomain')
      ->with($domain)
      ->willReturnCallback(function ($d) use (&$active): void {
        $active = $d;
      });

    $service = new EventDomainContext($context, $negotiator);

    $activeDuringCallback = NULL;
    $service->forDomain($domain, function () use ($negotiator, &$activeDuringCallback): void {
      $activeDuringCallback = $negotiator->getActiveDomain();
    });

    $this->assertSame($domain, $activeDuringCallback);
  }

  /**
   * A port carried on the domain's hostname lands on the matching setter.
   *
   * UrlGenerator only emits a port when it differs from the scheme default,
   * so putting it on the wrong setter would silently drop it.
   *
   * @covers ::forDomain
   */
  public function testForDomainCarriesNonStandardPort(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $service = new EventDomainContext($context, $this->negotiator());

    $seen = [];
    $service->forDomain($this->domain('event-site.ddev.site:8443', 'https'), function () use ($context, &$seen): void {
      $seen = [
        'host' => $context->getHost(),
        'httpsPort' => $context->getHttpsPort(),
      ];
    });

    $this->assertSame('event-site.ddev.site', $seen['host']);
    $this->assertSame(8443, $seen['httpsPort']);
  }

  /**
   * The request context is restored even when the callback throws.
   *
   * Token replacement can fail on a malformed entity; leaving a foreign
   * domain on the context would then misdirect every later link in the
   * process, which is worse than the bug being fixed.
   *
   * @covers ::forDomain
   */
  public function testRequestContextIsRestoredWhenTheCallbackThrows(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $context->setCompleteBaseUrl('http://requesting-site.example.com');
    $service = new EventDomainContext($context, $this->negotiator());

    try {
      $service->forDomain($this->domain('event-site.example.com', 'https'), function (): void {
        throw new \RuntimeException('token replacement blew up');
      });
      $this->fail('The exception should propagate to the caller.');
    }
    catch (\RuntimeException $e) {
      // Expected — the assertions below are the point.
    }

    $this->assertSame('http', $context->getScheme());
    $this->assertSame('requesting-site.example.com', $context->getHost());
    $this->assertSame(80, $context->getHttpPort());
    $this->assertSame(443, $context->getHttpsPort());
    $this->assertSame('http://requesting-site.example.com', $context->getCompleteBaseUrl());
  }

  /**
   * An entity with no domain_access value runs the callback untouched.
   *
   * @covers ::forEntity
   */
  public function testForEntityWithoutDomainLeavesTheContextAlone(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $negotiator = $this->createMock(DomainNegotiatorInterface::class);
    $negotiator->expects($this->never())->method('setActiveDomain');
    $service = new EventDomainContext($context, $negotiator);

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->with('domain_access')->willReturn(FALSE);

    $ran = FALSE;
    $service->forEntity($entity, function () use (&$ran, $context): void {
      $ran = TRUE;
      $this->assertSame('requesting-site.example.com', $context->getHost());
    });

    $this->assertTrue($ran);
  }

  /**
   * A domain_access field that is not an entity reference is ignored.
   *
   * Kernel tests elsewhere in this module stub domain_access as a plain
   * string field; treating that as a reference would fatal rather than
   * degrade to the request host.
   *
   * @covers ::resolveDomain
   */
  public function testNonReferenceDomainAccessFieldResolvesToNoDomain(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $service = new EventDomainContext($context, $this->negotiator());

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->with('domain_access')->willReturn(TRUE);
    $entity->method('get')->with('domain_access')
      ->willReturn($this->createMock(FieldItemListInterface::class));

    $this->assertNull($service->resolveDomain($entity));
    $this->assertNull($service->resolveHostname($entity));
  }

  /**
   * Resolution returns the first referenced domain.
   *
   * An event may be assigned to several domains; a notification can only
   * carry one host.
   *
   * @covers ::resolveDomain
   */
  public function testResolveDomainReturnsTheFirstReferencedDomain(): void {
    $context = new RequestContext('', 'GET', 'requesting-site.example.com', 'http', 80, 443);
    $service = new EventDomainContext($context, $this->negotiator());

    $first = $this->domain('first.example.com', 'https');
    $second = $this->domain('second.example.com', 'https');

    $items = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $items->method('isEmpty')->willReturn(FALSE);
    $items->method('referencedEntities')->willReturn([$first, $second]);

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->with('domain_access')->willReturn(TRUE);
    $entity->method('get')->with('domain_access')->willReturn($items);

    $this->assertSame($first, $service->resolveDomain($entity));
    $this->assertSame('first.example.com', $service->resolveHostname($entity));
  }

  /**
   * Builds a domain stub whose URL-shaped getters behave like the real entity.
   */
  private function domain(string $hostname, string $scheme): DomainInterface {
    $domain = $this->createMock(DomainInterface::class);
    $domain->method('getHostname')->willReturn($hostname);
    $domain->method('getScheme')->willReturnCallback(
      fn ($addSuffix = TRUE) => $addSuffix ? $scheme . '://' : $scheme
    );
    // Domain::getPath() is scheme + hostname + the global $base_path.
    $domain->method('getPath')->willReturn($scheme . '://' . $hostname . '/');
    return $domain;
  }

  /**
   * A negotiator stub that reports no previously active domain.
   */
  private function negotiator(): DomainNegotiatorInterface {
    $negotiator = $this->createMock(DomainNegotiatorInterface::class);
    $negotiator->method('getActiveDomain')->willReturn(NULL);
    return $negotiator;
  }

}
