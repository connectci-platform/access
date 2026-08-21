<?php

namespace Drupal\access_news\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Latest Announcements and Events' Block.
 *
 * @Block(
 *   id = "newsandevents_block",
 *   admin_label = @Translation("Announcements and Events block"),
 *   category = @Translation("ACCESS"),
 * )
 */
class NewsAndEventsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<int, array<string, mixed>>
   *   The render array.
   */
  public function build() {

    $latest_news_block = views_embed_view('access_news', 'latest_news_block');
    $latest_events_block = views_embed_view('recurring_events_event_instances', 'latest_events_block');

    return [
      [
        'description' => [
          '#theme' => 'newsandevents_block',
          '#latest_news_block' => $latest_news_block,
          '#latest_events_block' => $latest_events_block,
        ],
      ],
    ];
  }

}
