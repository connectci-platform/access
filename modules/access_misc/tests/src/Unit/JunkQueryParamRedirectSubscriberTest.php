<?php

namespace Drupal\Tests\access_misc\Unit;

use Drupal\access_misc\EventSubscriber\JunkQueryParamRedirectSubscriber;
use Drupal\Tests\UnitTestCase;

/**
 * Tests junk query string sanitizing.
 *
 * @coversDefaultClass \Drupal\access_misc\EventSubscriber\JunkQueryParamRedirectSubscriber
 * @group access_misc
 */
class JunkQueryParamRedirectSubscriberTest extends UnitTestCase {

  /**
   * Clean query strings need no sanitizing.
   *
   * @covers ::sanitizeQueryString
   */
  public function testCleanQueryReturnsNull(): void {
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString(''));
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString('f%5B0%5D=me_tags%3A275'));
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString('f%5B0%5D=me_tags%3A275&f%5B1%5D=me_tags%3A299&page=2'));
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString('facets_query=&combine=&order=field_user_first_name&sort=asc'));
  }

  /**
   * Keys merely containing "amp" or "amp;" mid-key are not junk.
   *
   * @covers ::sanitizeQueryString
   */
  public function testSimilarKeysAreNotStripped(): void {
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString('amp=1'));
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString('camp%3Bx=1'));
    $this->assertNull(JunkQueryParamRedirectSubscriber::sanitizeQueryString('campus=amp%3B'));
  }

  /**
   * Single-layer mangled keys are dropped, real params kept.
   *
   * @covers ::sanitizeQueryString
   */
  public function testSingleLayerJunkDropped(): void {
    $this->assertSame(
      'f%5B0%5D=me_tags%3A275',
      JunkQueryParamRedirectSubscriber::sanitizeQueryString('amp%3Bf%5B1%5D=me_tags%3A299&f%5B0%5D=me_tags%3A275')
    );
  }

  /**
   * Arbitrarily deep "amp;" stacks are dropped, raw or encoded.
   *
   * @covers ::sanitizeQueryString
   */
  public function testStackedLayersDropped(): void {
    $qs = 'facets_query=&amp%3Bamp%3Bamp%3Bf%5B0%5D=me_tags%3A275&amp%3Bamp%3Bf%5B0%5D=me_tags%3A442&amp%3Bf%5B0%5D=me_tags%3A272&f%5B0%5D=me_tags%3A724';
    $this->assertSame(
      'facets_query=&f%5B0%5D=me_tags%3A724',
      JunkQueryParamRedirectSubscriber::sanitizeQueryString($qs)
    );
    $this->assertSame(
      'a=1',
      JunkQueryParamRedirectSubscriber::sanitizeQueryString('a=1&amp;b=2&amp;amp;c=3')
    );
  }

  /**
   * All-junk query strings sanitize to an empty string.
   *
   * @covers ::sanitizeQueryString
   */
  public function testAllJunkYieldsEmptyString(): void {
    $this->assertSame(
      '',
      JunkQueryParamRedirectSubscriber::sanitizeQueryString('amp%3Bf%5B0%5D=me_tags%3A275')
    );
  }

}
