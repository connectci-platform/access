<?php

namespace Drupal\access_outages\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides an 'Outages' Block.
 *
 * @Block(
 *   id = "outages_block",
 *   admin_label = @Translation("Outages block"),
 *   category = @Translation("ACCESS"),
 * )
 */
class OutagesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The block render array.
   */
  public function build(): array {
    return [
      '#theme' => 'outages_block',
      '#data' => [],
    ];
  }

}
