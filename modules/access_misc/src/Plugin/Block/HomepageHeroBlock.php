<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;

/**
 * Provides the ACCESS Support homepage "How can we help?" hero.
 *
 * @Block(
 *   id = "access_homepage_hero",
 *   admin_label = @Translation("Homepage hero (How can we help?)"),
 *   category = @Translation("Custom"),
 * )
 */
class HomepageHeroBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array for the homepage-hero component.
   */
  public function build() {
    return [
      '#type' => 'component',
      '#component' => 'access_misc:homepage-hero',
      '#props' => [
        'bg' => Url::fromUserInput('/themes/contrib/asp-theme/images/cta/homepage-shapes.jpg')->toString(),
        'help_ticket_url' => Url::fromUserInput('/help-ticket')->toString(),
        'tickets_url' => 'https://access-ci.atlassian.net/servicedesk/customer/user/requests',
        'docs_url' => Url::fromUserInput('/documentation')->toString(),
        'prompts' => [
          (string) $this->t('How do I get started with ACCESS?'),
          (string) $this->t('Which resource has GPUs for deep learning?'),
          (string) $this->t('How do I move data with Globus?'),
        ],
      ],
    ];
  }

}
