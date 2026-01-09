<?php

namespace Drupal\access_shortcodes\Plugin\Shortcode;

use Drupal\Core\Language\Language;
use Drupal\shortcode\Plugin\ShortcodeBase;

/**
 * Provides a shortcode for accordion blocks.
 *
 * @Shortcode(
 *   id = "accordion",
 *   title = @Translation("Accordion"),
 *   description = @Translation("Builds an accordion with a summary and extended text.")
 * )
 */
class AccordionShortcode extends ShortcodeBase {

  /**
   * {@inheritdoc}
   */
  public function process(array $attributes, $text, $langcode = Language::LANGCODE_NOT_SPECIFIED) {
    kint($attributes, $text);
    $attributes = $this->getAttributes([
      'summary' => '',
      'summarytag' => '',
      'text' => '',
      'color' => '',
    ],
      $attributes
    );

    $summary = $attributes['summary'];
    $summary_tag = $attributes['summarytag'];
    $text = $attributes['text'];
    $color = $attributes['color'];

    $output = [
      '#theme' => 'shortcode_accordion',
      '#summary' => $summary,
      '#summarytag' => $summary_tag,
      '#text' => $text,
      '#color' => ($color == '') ? 'bg-light-teal' : $color,
    ];

    kint($summary_tag, $output);

    return $this->render($output);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    $output = [];
    $output[] = '<p><strong>' . $this->t('[accordion summary="Question" summarytag="h3" text="Your text here" color="bg-light-teal"][/accordion]') . '</strong>';
    if ($long) {
      $output[] = $this->t('Builds an accordion with summary, text and color.') . '</p>';
    }
    else {
      $output[] = $this->t('Builds an accordion with summary, text and color.') . '</p>';
    }

    return implode(' ', $output);
  }

}
