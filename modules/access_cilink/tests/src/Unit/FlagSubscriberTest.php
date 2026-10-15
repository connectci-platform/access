<?php

namespace Drupal\Tests\access_cilink\Unit;

use Drupal\access_cilink\EventSubscriber\FlagSubscriber;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\State\StateInterface;
use Drupal\flag\Event\FlagEvents;
use Drupal\flag\Event\FlaggingEvent;
use Drupal\flag\Event\UnflaggingEvent;
use Drupal\flag\FlaggingInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the CI Link flag event subscriber.
 *
 * @coversDefaultClass \Drupal\access_cilink\EventSubscriber\FlagSubscriber
 * @group access_cilink
 */
class FlagSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   */
  protected FlagSubscriber $subscriber;

  /**
   * In-memory state values.
   *
   * @var \ArrayObject
   */
  protected \ArrayObject $stateValues;

  /**
   * Tags passed to the cache tags invalidator.
   *
   * @var \ArrayObject
   */
  protected \ArrayObject $invalidated;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->stateValues = new \ArrayObject();
    $values = $this->stateValues;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      fn ($key, $default = NULL) => $values->offsetExists($key) ? $values[$key] : $default
    );
    $state->method('set')->willReturnCallback(
      function ($key, $value) use ($values) {
        $values[$key] = $value;
      }
    );

    $this->invalidated = new \ArrayObject();
    $invalidated = $this->invalidated;
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(
      function (array $tags) use ($invalidated) {
        foreach ($tags as $tag) {
          $invalidated[] = $tag;
        }
      }
    );

    $container = new ContainerBuilder();
    $container->set('state', $state);
    $container->set('cache_tags.invalidator', $invalidator);
    \Drupal::setContainer($container);

    $this->subscriber = new FlagSubscriber();
  }

  /**
   * Builds a mock flagging for a flag id and flaggable id.
   */
  protected function flagging(string $flag_id, string $sid = '42'): FlaggingInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('id')->willReturn($sid);
    $flagging = $this->createMock(FlaggingInterface::class);
    $flagging->method('getFlagId')->willReturn($flag_id);
    $flagging->method('getFlaggable')->willReturn($entity);
    return $flagging;
  }

  /**
   * Dispatches a flag event to the subscriber.
   */
  protected function flag(string $flag_id, string $sid = '42'): void {
    $this->subscriber->onFlag(new FlaggingEvent($this->flagging($flag_id, $sid)));
  }

  /**
   * Dispatches an unflag event to the subscriber.
   */
  protected function unflag(string $flag_id, string $sid = '42'): void {
    $this->subscriber->onUnflag(new UnflaggingEvent([$this->flagging($flag_id, $sid)]));
  }

  /**
   * Returns the stored resource_flags state.
   */
  protected function resourceFlags(): ?array {
    return $this->stateValues['resource_flags'] ?? NULL;
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = FlagSubscriber::getSubscribedEvents();
    $this->assertSame([['onFlag']], $events[FlagEvents::ENTITY_FLAGGED]);
    $this->assertSame([['onUnflag']], $events[FlagEvents::ENTITY_UNFLAGGED]);
    $this->assertCount(2, $events);
  }

  /**
   * @covers ::onFlag
   */
  public function testUpvoteFlagInvalidatesCacheTags(): void {
    $this->flag('upvote', '42');
    $this->assertSame(['webform_submission:42', 'search_api_list:ci_links'], $this->invalidated->getArrayCopy());
    $this->assertNull($this->resourceFlags());
  }

  /**
   * @covers ::onUnflag
   */
  public function testUpvoteUnflagInvalidatesCacheTags(): void {
    $this->unflag('upvote', '42');
    $this->assertSame(['webform_submission:42', 'search_api_list:ci_links'], $this->invalidated->getArrayCopy());
    $this->assertNull($this->resourceFlags());
  }

  /**
   * @covers ::onFlag
   * @covers ::onUnflag
   */
  public function testOtherFlagsDoNothing(): void {
    $this->flag('bookmark');
    $this->unflag('bookmark');
    $this->assertNull($this->resourceFlags());
    $this->assertSame([], $this->invalidated->getArrayCopy());
  }

  /**
   * @covers ::onFlag
   * @dataProvider reportFlagProvider
   */
  public function testFlagSetsThenIncrements(string $flag_id): void {
    $this->flag($flag_id);
    $this->assertSame([$flag_id => 1, 'today' => 1], $this->resourceFlags()['42']);
    $this->flag($flag_id);
    $this->assertSame(2, $this->resourceFlags()['42'][$flag_id]);
    $this->flag($flag_id);
    $this->assertSame(3, $this->resourceFlags()['42'][$flag_id]);
    $this->assertSame(1, $this->resourceFlags()['42']['today']);
    $this->assertSame([], $this->invalidated->getArrayCopy());
  }

  /**
   * @covers ::onFlag
   */
  public function testFlagCountsAreTrackedPerResourceAndFlag(): void {
    $this->flag('outdated', '1');
    $this->flag('inaccurate', '1');
    $this->flag('outdated', '2');
    $flags = $this->resourceFlags();
    $this->assertSame(['outdated' => 1, 'today' => 1, 'inaccurate' => 1], $flags['1']);
    $this->assertSame(['outdated' => 1, 'today' => 1], $flags['2']);
  }

  /**
   * @covers ::onUnflag
   * @dataProvider reportFlagProvider
   */
  public function testUnflagDecrementsAndNeverGoesBelowZero(string $flag_id): void {
    $this->flag($flag_id);
    $this->flag($flag_id);
    $this->unflag($flag_id);
    $this->assertSame(1, $this->resourceFlags()['42'][$flag_id]);
    $this->unflag($flag_id);
    $this->assertSame(0, $this->resourceFlags()['42'][$flag_id]);
    $this->unflag($flag_id);
    $this->assertSame(0, $this->resourceFlags()['42'][$flag_id]);
    $this->unflag($flag_id);
    $this->assertSame(0, $this->resourceFlags()['42'][$flag_id]);
    $this->assertSame(1, $this->resourceFlags()['42']['today']);
  }

  /**
   * @covers ::onUnflag
   * @dataProvider reportFlagProvider
   */
  public function testUnflagWithNoPriorStateSetsZero(string $flag_id): void {
    $this->unflag($flag_id);
    $this->assertSame([$flag_id => 0, 'today' => 1], $this->resourceFlags()['42']);
  }

  /**
   * Data provider for the flags that trigger daily notifications.
   */
  public static function reportFlagProvider(): array {
    return [
      'outdated' => ['outdated'],
      'not_useful' => ['not_useful'],
      'inaccurate' => ['inaccurate'],
    ];
  }

}
