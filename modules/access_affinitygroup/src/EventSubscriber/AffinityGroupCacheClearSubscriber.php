<?php

namespace Drupal\access_affinitygroup\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\flag\Event\FlaggingEvent;
use Drupal\flag\Event\UnflaggingEvent;
use Drupal\flag\Event\FlagEvents;
use Drupal\Core\Cache\Cache;

/**
 * Clears the cache for affinity groups when they are flagged or unflagged.
 */
class AffinityGroupCacheClearSubscriber implements EventSubscriberInterface {

  /**
   * Clears all relevant caches for the affinity_group_search view.
   */
  protected function clearAffinityGroupSearchCache() {
    $view_id = 'affinity_group_search';

    $tags_to_invalidate = [
      "config:views.view.{$view_id}",
    ];

    Cache::invalidateTags($tags_to_invalidate);
  }

  /**
   * Responds to the flagging event.
   */
  public function onFlagging(FlaggingEvent $event) {
    $flagging = $event->getFlagging();
    $flag_id = $flagging->getFlagId();

    if ($flag_id === 'affinity_group') {
      $this->clearAffinityGroupSearchCache();
    }
  }

  /**
   * Responds to the unflagging event.
   */
  public function onUnflagging(UnflaggingEvent $event) {
    $flagging = $event->getFlaggings();
    $flagging = reset($flagging);
    $flag_id = $flagging->getFlagId();

    if ($flag_id === 'affinity_group') {
      $this->clearAffinityGroupSearchCache();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      FlagEvents::ENTITY_FLAGGED => 'onFlagging',
      FlagEvents::ENTITY_UNFLAGGED => 'onUnflagging',
    ];
  }

}
