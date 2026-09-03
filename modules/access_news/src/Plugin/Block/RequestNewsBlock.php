<?php

namespace Drupal\access_news\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Request Announcement' Block.
 *
 * @Block(
 *   id = "requestnews_block",
 *   admin_label = @Translation("Request an Announcement block"),
 *   category = @Translation("ACCESS"),
 * )
 */
class RequestNewsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function build() {

    return [
      '#theme' => 'requestnews_block',
      'variables' => [],
    ];
  }

}
