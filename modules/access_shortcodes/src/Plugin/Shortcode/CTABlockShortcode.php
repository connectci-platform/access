<?php

namespace Drupal\access_shortcodes\Plugin\Shortcode;

use Drupal\Core\Language\Language;
use Drupal\shortcode\Plugin\ShortcodeBase;

/**
 * Provides a shortcode for CTA blocks.
 *
 * @Shortcode(
 *   id = "cta_block",
 *   title = @Translation("CTA Block"),
 *   description = @Translation("Builds a Call-to-Action.")
 * )
 */
class CTABlockShortcode extends ShortcodeBase {

  /**
   * {@inheritdoc}
   */
  public function process(array $attributes, $text, $langcode = Language::LANGCODE_NOT_SPECIFIED) {
    $attributes = $this->getAttributes([
      'heading' => '',
      'bg' => '',
      'img' => '',
      'alt' => '',
      'title' => '',
      'btnlink' => '',
      'btntext' => '',
    ],
      $attributes
    );

    $heading = empty($attributes['heading']) ? 'h2' : $attributes['heading'];
    $bg = $attributes['bg'];
    $img = $attributes['img'];
    $alt = $attributes['alt'];
    $title = $attributes['title'];
    $btnlink = $attributes['btnlink'];
    $btntext = $attributes['btntext'];

    $output = [
      '#theme' => 'shortcode_cta_block',
      '#heading' => $heading,
      '#bg' => $bg,
      '#img' => $img,
      '#alt' => $alt,
      '#title' => $title,
      '#btnlink' => $btnlink,
      '#btntext' => $btntext,
    ];

    return $this->render($output);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    $output = [];
    $output[] = '<p><strong>' . $this->t('[cta_box bg="/path/to/image.jpg" img="optional image url" alt="alt text for image" title="Your title here" btnLink="https://example.com" btnText="Button link"][/cta_box]') . '</strong> ';
    if ($long) {
      $output[] = $this->t('Builds a Call-to-Action.') . '</p>';
    }
    else {
      $output[] = $this->t('Builds a  Call-to-Action.') . '</p>';
    }

    return implode(' ', $output);
  }

}
