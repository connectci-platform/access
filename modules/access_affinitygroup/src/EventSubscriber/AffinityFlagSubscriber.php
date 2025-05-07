<?php

namespace Drupal\access_affinitygroup\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\flag\Event\FlaggingEvent;
use Drupal\flag\Event\FlagEvents;
use Drupal\flag\Event\UnflaggingEvent;

/**
 * Reacts to flag and unflag events for the affinity_group flag.
 */
class AffinityFlagSubscriber implements EventSubscriberInterface {

  /**
   * React to flag being set.
   */
  public function onFlag(FlaggingEvent $event) {
    $flag = $event->getFlagging();
    $flag_id = $flag->getFlagId();
    if ($flag_id === 'affinity_group') {
      $entity = $flag->getFlaggable();
      $tid = $entity->id();
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['affinity_group_field_' . $tid]);
      views_invalidate_cache(['affinity_group_search:page_1']);
      \Drupal::logger('affinity_tools')->info('Flagged affinity group, cache cleared.');
    }
  }

  /**
   * React to flag being removed.
   */
  public function onUnflag(UnflaggingEvent $event) {
    $flaggings = $event->getFlaggings();
    $flag = reset($flaggings);
    $flag_id = $flag->getFlagId();
    if ($flag_id === 'affinity_group') {
      $entity = $flag->getFlaggable();
      // Ensure the entity is a taxonomy term and retrieve the term ID.
      if ($entity->getEntityTypeId() === 'taxonomy_term') {
        $tid = $entity->id();
        \Drupal::service('cache_tags.invalidator')->invalidateTags(['affinity_group_field_' . $tid]);
        views_invalidate_cache(['affinity_group_search:page_1']);
        \Drupal::logger('affinity_tools')->info('Unflagged affinity group, cache cleared.');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      FlagEvents::ENTITY_FLAGGED => 'onFlag',
      FlagEvents::ENTITY_UNFLAGGED => 'onUnflag',
    ];
  }

}
