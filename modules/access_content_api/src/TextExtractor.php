<?php

namespace Drupal\access_content_api;

/**
 * Converts rendered HTML to clean plain text.
 */
class TextExtractor {

  /**
   * Extracts plain text from an HTML string.
   */
  public function extract(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    // Remove style and script blocks entirely.
    $text = preg_replace('/<(style|script)[^>]*>.*?<\/\1>/si', '', $html);

    // Expand anchors: <a href="url">label</a> → label (url).
    $text = preg_replace_callback(
      '/<a\s[^>]*href=["\']([^"\'#][^"\']*)["\'][^>]*>(.*?)<\/a>/si',
      function (array $m): string {
        $label = trim(strip_tags($m[2]));
        $url = trim($m[1]);
        if ($label === '') {
          return '';
        }
        return $label . ' (' . $url . ')';
      },
      $text
    );

    // Drop internal anchors (#...) without emitting the URL.
    $text = preg_replace('/<a\s[^>]*href=["\']#[^"\']*["\'][^>]*>(.*?)<\/a>/si', '$1', $text);

    // Drop remaining anchors without href.
    $text = preg_replace('/<a[^>]*>(.*?)<\/a>/si', '$1', $text);

    // Tables: simple rows → cell1 | cell2.
    $text = preg_replace_callback(
      '/<tr[^>]*>(.*?)<\/tr>/si',
      function (array $m): string {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/si', $m[1], $cells);
        if (empty($cells[1])) {
          return "\n";
        }
        $parts = array_map(fn($c) => trim(strip_tags($c)), $cells[1]);
        return implode(' | ', $parts) . "\n";
      },
      $text
    );
    $text = preg_replace('/<\/?table[^>]*>/si', "\n", $text);

    // List items with bullet prefix.
    $text = preg_replace_callback(
      '/<li[^>]*>(.*?)<\/li>/si',
      function (array $m): string {
        return '- ' . trim(strip_tags($m[1])) . "\n";
      },
      $text
    );

    // Block-level tags → newlines.
    $block = 'p|div|h1|h2|h3|h4|h5|h6|ul|ol|dl|dt|dd|blockquote|pre|section|article|header|footer|nav|main|aside|figure|figcaption|details|summary';
    $text = preg_replace('/<\/(' . $block . ')[^>]*>/si', "\n", $text);
    $text = preg_replace('/<(' . $block . ')[^>]*>/si', "\n", $text);

    // <br> → newline.
    $text = preg_replace('/<br\s*\/?>/si', "\n", $text);

    // Strip remaining tags.
    $text = strip_tags($text);

    // Decode HTML entities.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize whitespace: trim each line, collapse blank lines.
    $lines = explode("\n", $text);
    $lines = array_map('rtrim', $lines);
    $normalized = [];
    $blankCount = 0;
    foreach ($lines as $line) {
      if (trim($line) === '') {
        $blankCount++;
        if ($blankCount <= 1) {
          $normalized[] = '';
        }
      }
      else {
        $blankCount = 0;
        $normalized[] = $line;
      }
    }

    return trim(implode("\n", $normalized));
  }

}
