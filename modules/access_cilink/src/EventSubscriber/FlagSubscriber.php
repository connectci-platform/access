<?php

namespace Drupal\access_cilink\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\flag\Event\FlagEvents;
use Drupal\flag\Event\FlaggingEvent;
use Drupal\flag\Event\UnflaggingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for flag events.
 */
class FlagSubscriber implements EventSubscriberInterface {

  /**
   * Subscribe to onFlag events.
   *
   * - Set state for flagging CI Links so email notifications can be send daily.
   * - Invalidate CI Link cache on flagging.
   */
  public function onFlag(FlaggingEvent $event) {
    $flagging = $event->getFlagging();
    $flag_id = $flagging->getFlagId();
    $entity_sid = $flagging->getFlaggable()->id();
    if ($flag_id == 'outdated' || $flag_id == 'not_useful' || $flag_id == 'inaccurate') {
      $flag_resource = \Drupal::state()->get('resource_flags'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      if (isset($flag_resource[$entity_sid][$flag_id])) {
        $flag_resource[$entity_sid][$flag_id]++;
      }
      else {
        $flag_resource[$entity_sid][$flag_id] = 1;
      }
      $flag_resource[$entity_sid]['today'] = 1;
      // Set state to send email later.
      \Drupal::state()->set('resource_flags', $flag_resource); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    }
    if ($flag_id == 'upvote') {
      Cache::invalidateTags([
        'webform_submission:' . $flagging->getFlaggable()->id(),
        'search_api_list:ci_links',
      ]);
    }
  }

  /**
   * Subscribe to onUnFlag events.
   *
   * - Set state for unflagging CI Links so notifications can be sent daily.
   * - Invalidate CI Link cache on flagging.
   */
  public function onUnflag(UnflaggingEvent $event) {
    $flagging = $event->getFlaggings();
    $flagging = reset($flagging);
    $flag_id = $flagging->getFlagId();
    $entity_sid = $flagging->getFlaggable()->id();
    if ($flag_id == 'outdated' || $flag_id == 'not_useful' || $flag_id == 'inaccurate') {
      $flag_resource = \Drupal::state()->get('resource_flags'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      if (isset($flag_resource[$entity_sid][$flag_id])) {
        $flag_resource[$entity_sid][$flag_id] !== 0 ? $flag_resource[$entity_sid][$flag_id]-- : 0;
      }
      else {
        $flag_resource[$entity_sid][$flag_id] = 0;
      }
      $flag_resource[$entity_sid]['today'] = 1;
      // Set state to send email later.
      \Drupal::state()->set('resource_flags', $flag_resource); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    }
    if ($flag_id == 'upvote') {
      Cache::invalidateTags([
        'webform_submission:' . $flagging->getFlaggable()->id(),
        'search_api_list:ci_links',
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[FlagEvents::ENTITY_FLAGGED][] = ['onFlag'];
    $events[FlagEvents::ENTITY_UNFLAGGED][] = ['onUnflag'];
    return $events;
  }

}
