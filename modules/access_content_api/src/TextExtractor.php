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
    $text = $this->pregReplace('/<(style|script)[^>]*>.*?<\/\1>/si', '', $html);

    // Remove nav blocks (jump menus / tables of contents) entirely — they are
    // navigation chrome, not content, and pollute RAG embeddings.
    $text = $this->pregReplace('/<nav\b[^>]*>.*?<\/nav>/si', '', $text);

    // Headings → markdown markers (## Heading) so chunkers get real section
    // boundaries. Done before generic block handling so the level is known.
    $text = $this->pregReplaceCallback(
      '/<h([1-6])[^>]*>(.*?)<\/h\1>/si',
      function (array $m): string {
        $label = trim(strip_tags($m[2]));
        if ($label === '') {
          return "\n";
        }
        return "\n" . str_repeat('#', (int) $m[1]) . ' ' . $label . "\n";
      },
      $text
    );

    // Expand anchors: <a href="url">label</a> → label (url).
    // The opening quote is captured and backreferenced so the URL may contain
    // the other quote character (e.g. an apostrophe in a double-quoted href)
    // without being truncated.
    $text = $this->pregReplaceCallback(
      '/<a\s[^>]*href=(["\'])(#?)((?:(?!\1).)*)\1[^>]*>(.*?)<\/a>/si',
      function (array $m): string {
        // $m[2] is "#" for in-page anchors, which emit the label only.
        $label = trim(strip_tags($m[4]));
        $url = trim($m[3]);
        if ($label === '' || $m[2] === '#') {
          return $label;
        }
        return $label . ' (' . $url . ')';
      },
      $text
    );

    // Drop remaining anchors (no href, or already handled above) to label.
    $text = $this->pregReplace('/<a[^>]*>(.*?)<\/a>/si', '$1', $text);

    // Tables: simple rows → cell1 | cell2.
    $text = $this->pregReplaceCallback(
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
    $text = $this->pregReplace('/<\/?table[^>]*>/si', "\n", $text);

    // Lists: process innermost first, bubbling indentation outward.
    $text = $this->processLists($text);

    // Block-level tags → newlines.
    $block = 'p|div|h1|h2|h3|h4|h5|h6|ul|ol|dl|dt|dd|blockquote|pre|section|article|header|footer|nav|main|aside|figure|figcaption|details|summary';
    $text = $this->pregReplace('/<\/(' . $block . ')[^>]*>/si', "\n", $text);
    $text = $this->pregReplace('/<(' . $block . ')[^>]*>/si', "\n", $text);

    // <br> → newline.
    $text = $this->pregReplace('/<br\s*\/?>/si', "\n", $text);

    // Strip remaining tags.
    $text = strip_tags($text);

    // Decode HTML entities.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize whitespace: clean each line, collapse blank lines.
    $lines = explode("\n", $text);
    $normalized = [];
    $blankCount = 0;
    foreach ($lines as $line) {
      $line = $this->normalizeLineWhitespace($line);
      if ($line === '') {
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
      $new = $this->pregReplaceCallback(
        '/<(ul|ol)[^>]*>((?:(?!<(?:ul|ol)[^>]*>).)*?)<\/(?:ul|ol)>/si',
        function (array $m): string {
          $items = $this->pregReplaceCallback(
            '/<li[^>]*>(.*?)<\/li>/si',
            function (array $li): string {
              $content = $this->pregReplace('/<br\s*\/?>/si', "\n", $li[1]);
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

  /**
   * Cleans a single line's whitespace.
   *
   * Preserves the list-structure indentation this class emits (pairs of
   * leading spaces, optionally followed by a "- " bullet) so nested lists keep
   * their shape, strips any other leading whitespace (tabs and ragged runs that
   * leak from rendered layout markup), and collapses interior whitespace runs
   * to a single space.
   */
  private function normalizeLineWhitespace(string $line): string {
    // Preserve leading indentation ONLY for actual list items, which this class
    // emits as pairs of leading spaces followed by a "- " bullet. Any other
    // leading whitespace (tabs, ragged or even-length runs from rendered layout
    // markup) is accidental and stripped.
    $indent = '';
    $bullet = '';
    if (preg_match('/^((?:  )*)(- )/', $line, $prefix)) {
      $indent = $prefix[1];
      $bullet = $prefix[2];
    }

    // Collapse all whitespace (incl. tabs) to single spaces, then trim ends.
    $content = substr($line, strlen($indent) + strlen($bullet));
    $content = trim((string) $this->pregReplace('/\s+/', ' ', $content));

    if ($content === '') {
      return '';
    }
    return $indent . $bullet . $content;
  }

  /**
   * NULL-safe preg_replace.
   *
   * preg_replace returns NULL when a limit (e.g. pcre.backtrack_limit) is hit,
   * which would otherwise null out the whole text and propagate through the
   * extraction chain. On failure, return the subject unchanged so only the
   * offending fragment is left as-is rather than wiping all output.
   */
  private function pregReplace(string $pattern, string $replacement, string $subject): string {
    $result = preg_replace($pattern, $replacement, $subject);
    return $result ?? $subject;
  }

  /**
   * NULL-safe preg_replace_callback.
   *
   * @see self::pregReplace()
   */
  private function pregReplaceCallback(string $pattern, callable $callback, string $subject): string {
    $result = preg_replace_callback($pattern, $callback, $subject);
    return $result ?? $subject;
  }

}
