<?php

namespace Drupal\Tests\access_misc\Unit;

use Drupal\access_misc\EventSubscriber\CanonicalAliasNormalizerSubscriber;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests when the canonical alias normalizer clears the alias lookup entry.
 *
 * @coversDefaultClass \Drupal\access_misc\EventSubscriber\CanonicalAliasNormalizerSubscriber
 * @group access_misc
 */
class CanonicalAliasNormalizerSubscriberTest extends UnitTestCase {

  /**
   * Builds a request event.
   */
  protected function createEvent(int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new RequestEvent($kernel, Request::create('/foo'), $type);
  }

  /**
   * Builds a current path stack returning /node/1.
   */
  protected function createCurrentPath(): CurrentPathStack {
    $current_path = $this->createMock(CurrentPathStack::class);
    $current_path->method('getPath')->willReturn('/node/1');
    return $current_path;
  }

  /**
   * Runs before Redirect's normalizer (30) and after routing (32).
   *
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedPriority(): void {
    $this->assertSame(
      [KernelEvents::REQUEST => ['onRequest', 31]],
      CanonicalAliasNormalizerSubscriber::getSubscribedEvents()
    );
  }

  /**
   * An aliased path is cleared exactly once.
   *
   * @covers ::onRequest
   */
  public function testAliasedPathIsCleared(): void {
    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getAliasByPath')->with('/node/1')->willReturn('/foo');
    $alias_manager->expects($this->once())->method('cacheClear')->with('/node/1');
    (new CanonicalAliasNormalizerSubscriber($alias_manager, $this->createCurrentPath()))
      ->onRequest($this->createEvent());
  }

  /**
   * An unaliased path is left alone.
   *
   * @covers ::onRequest
   */
  public function testUnaliasedPathIsNotCleared(): void {
    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getAliasByPath')->with('/node/1')->willReturn('/node/1');
    $alias_manager->expects($this->never())->method('cacheClear');
    (new CanonicalAliasNormalizerSubscriber($alias_manager, $this->createCurrentPath()))
      ->onRequest($this->createEvent());
  }

  /**
   * Sub-requests are ignored.
   *
   * @covers ::onRequest
   */
  public function testSubRequestIsIgnored(): void {
    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects($this->never())->method('getAliasByPath');
    $alias_manager->expects($this->never())->method('cacheClear');
    (new CanonicalAliasNormalizerSubscriber($alias_manager, $this->createCurrentPath()))
      ->onRequest($this->createEvent(HttpKernelInterface::SUB_REQUEST));
  }

  /**
   * Without an alias manager nothing happens.
   *
   * @covers ::onRequest
   */
  public function testNullAliasManager(): void {
    $current_path = $this->createMock(CurrentPathStack::class);
    $current_path->expects($this->never())->method('getPath');
    (new CanonicalAliasNormalizerSubscriber(NULL, $current_path))
      ->onRequest($this->createEvent());
  }

}
