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

    // Lists: process innermost first, bubbling indentation outward.
    $text = $this->processLists($text);

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

  /**
   * Processes nested lists innermost-first, producing indented plain text.
   */
  private function processLists(string $html): string {
    for ($pass = 0; $pass < 20; $pass++) {
      $new = preg_replace_callback(
        '/<(ul|ol)[^>]*>((?:(?!<(?:ul|ol)[^>]*>).)*?)<\/(?:ul|ol)>/si',
        function (array $m): string {
          $items = preg_replace_callback(
            '/<li[^>]*>(.*?)<\/li>/si',
            function (array $li): string {
              $content = preg_replace('/<br\s*\/?>/si', "\n", $li[1]);
              $raw = strip_tags($content);
              $lines = array_values(array_filter(
                array_map('rtrim', explode("\n", $raw)),
                fn($l) => $l !== ''
              ));
              if (empty($lines)) {
                return '';
              }
              $out = [];
              foreach ($lines as $i => $line) {
                if (str_starts_with($line, '- ') || str_starts_with($line, '  ')) {
                  $out[] = '  ' . $line;
                }
                elseif ($i === 0) {
                  $out[] = '- ' . $line;
                }
                else {
                  $out[] = '  ' . $line;
                }
              }
              return implode("\n", $out) . "\n";
            },
            $m[2]
          );
          return "\n" . $items;
        },
        $html
      );
      if ($new === $html) {
        break;
      }
      $html = $new;
    }
    return $html;
  }

}
