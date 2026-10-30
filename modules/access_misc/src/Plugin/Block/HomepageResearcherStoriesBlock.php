<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;

/**
 * Provides the ACCESS Support homepage "Researcher stories" band.
 *
 * @Block(
 *   id = "access_homepage_researcher_stories",
 *   admin_label = @Translation("Homepage researcher stories"),
 *   category = @Translation("Custom"),
 * )
 */
class HomepageResearcherStoriesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array for the homepage-researcher-stories component.
   */
  public function build() {
    return [
      '#type' => 'component',
      '#component' => 'access_misc:homepage-researcher-stories',
      '#props' => [
        'stories_url' => Url::fromUserInput('/tools/researcher-stories')->toString(),
      ],
      '#slots' => [
        // The display is NULL (render nothing) until it exists.
        'stories' => views_embed_view('tool_case_study', 'homepage_stories') ?? [],
      ],
      '#cache' => [
        'tags' => ['node_list:tools_case_study'],
      ],
    ];
  }

}
