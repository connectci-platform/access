<?php

namespace Drupal\Tests\access_content_api\Unit;

use Drupal\access_content_api\TextExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for TextExtractor.
 *
 * @coversDefaultClass \Drupal\access_content_api\TextExtractor
 * @group access_content_api
 */
class TextExtractorTest extends UnitTestCase {

  /**
   * The text extractor under test.
   *
   * @var \Drupal\access_content_api\TextExtractor
   */
  private TextExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extractor = new TextExtractor();
  }

  /**
   * Tests that paragraphs become newline-separated text.
   */
  public function testPlainParagraphsBecomeNewlineSeparated(): void {
    $result = $this->extractor->extract('<p>foo</p><p>bar</p>');
    $this->assertStringContainsString('foo', $result);
    $this->assertStringContainsString('bar', $result);
    $this->assertMatchesRegularExpression('/foo\n+bar/', $result);
  }

  /**
   * Tests that list items get bullet prefixes.
   */
  public function testListsGetBulletPrefixes(): void {
    $result = $this->extractor->extract('<ul><li>a</li><li>b</li></ul>');
    $this->assertStringContainsString('- a', $result);
    $this->assertStringContainsString('- b', $result);
  }

  /**
   * Tests that nested lists are indented two spaces per level.
   */
  public function testNestedListsIndent(): void {
    $html = '<ul><li>top<ul><li>nested</li></ul></li></ul>';
    $result = $this->extractor->extract($html);
    $this->assertMatchesRegularExpression('/^- top/m', $result);
    $this->assertMatchesRegularExpression('/^  - nested/m', $result);
  }

  /**
   * Tests that anchors become "label (url)" format.
   */
  public function testAnchorsBecomeLabelAndUrl(): void {
    $result = $this->extractor->extract('<a href="https://x.org">click</a>');
    $this->assertStringContainsString('click (https://x.org)', $result);
  }

  /**
   * Tests that icon-only anchors with no label are dropped.
   */
  public function testEmptyAnchorsAreDropped(): void {
    $result = $this->extractor->extract('<a href="https://x.org"><img src="icon.png"/></a>');
    $this->assertStringNotContainsString('https://x.org', $result);
  }

  /**
   * Tests that internal (#) anchor URLs are not emitted.
   */
  public function testInternalAnchorsAreDropped(): void {
    $result = $this->extractor->extract('<a href="#section">jump</a>');
    $this->assertStringNotContainsString('#section', $result);
    $this->assertStringContainsString('jump', $result);
  }

  /**
   * Tests that simple tables render as pipe-separated columns.
   */
  public function testSimpleTablesUsePipeSeparators(): void {
    $result = $this->extractor->extract('<table><tr><td>a</td><td>b</td></tr></table>');
    $this->assertStringContainsString('a | b', $result);
  }

  /**
   * Tests that style and script content is removed from output.
   */
  public function testStyleAndScriptContentRemoved(): void {
    $result = $this->extractor->extract('<style>.foo { color: red; }</style><p>visible</p>');
    $this->assertStringNotContainsString('color', $result);
    $this->assertStringContainsString('visible', $result);
  }

  /**
   * Nav elements (jump menus / tables of contents) are removed entirely.
   */
  public function testNavContentRemoved(): void {
    $html = '<nav><ul><li><a href="#about">About</a></li></ul></nav><p>Real body content</p>';
    $result = $this->extractor->extract($html);
    $this->assertStringNotContainsString('About', $result);
    $this->assertStringContainsString('Real body content', $result);
  }

  /**
   * Headings are preserved as markdown-style "## " markers for chunking.
   */
  public function testHeadingsBecomeMarkdownMarkers(): void {
    $result = $this->extractor->extract('<h2>Section Title</h2><p>body</p>');
    $this->assertMatchesRegularExpression('/^## Section Title$/m', $result);
  }

  /**
   * Heading level maps to the number of hashes (h1 → #, h3 → ###).
   */
  public function testHeadingLevelMapsToHashCount(): void {
    $result = $this->extractor->extract('<h1>Top</h1><h3>Sub</h3>');
    $this->assertMatchesRegularExpression('/^# Top$/m', $result);
    $this->assertMatchesRegularExpression('/^### Sub$/m', $result);
  }

  /**
   * Finding 5: a URL containing an apostrophe is not truncated.
   */
  public function testAnchorUrlWithApostropheNotTruncated(): void {
    $result = $this->extractor->extract('<a href="https://x.org/o\'brien?a=1">go</a>');
    $this->assertStringContainsString("https://x.org/o'brien?a=1", $result);
  }

  /**
   * Finding 4: a huge style block must not wipe the rest of the text.
   *
   * When a regex hits the PCRE backtrack limit it returns NULL, which without
   * a guard would null out the entire extracted string. The visible content
   * must survive even if the style block cannot be cleanly removed.
   */
  public function testHugeStyleBlockDoesNotWipeText(): void {
    $html = '<style>' . str_repeat('a', 2000000) . '</style><p>VISIBLE CONTENT</p>';
    $result = $this->extractor->extract($html);
    $this->assertStringContainsString('VISIBLE CONTENT', $result);
  }

  /**
   * Finding 4: an unclosed style block must not wipe the rest of the text.
   */
  public function testUnclosedStyleBlockDoesNotWipeText(): void {
    $html = '<p>VISIBLE CONTENT</p><style>' . str_repeat('a', 2000000);
    $result = $this->extractor->extract($html);
    $this->assertStringContainsString('VISIBLE CONTENT', $result);
  }

  /**
   * Tests that HTML entities are decoded to real characters.
   */
  public function testHtmlEntitiesDecoded(): void {
    $result = $this->extractor->extract('<p>a &amp; b &nbsp; c</p>');
    $this->assertStringContainsString('a & b', $result);
  }

  /**
   * Tests blank-line collapsing and trailing-whitespace removal.
   */
  public function testWhitespaceNormalized(): void {
    $result = $this->extractor->extract("<p>line1</p>\n\n\n\n<p>line2</p>");
    $this->assertStringNotContainsString("\n\n\n", $result);
    $this->assertDoesNotMatchRegularExpression('/[ \t]+\n/', $result);
  }

  /**
   * Ragged leading tabs/spaces from layout markup are stripped.
   */
  public function testLeadingIndentationStripped(): void {
    $result = $this->extractor->extract("<div>\t      Zero installation</div>");
    $this->assertStringContainsString('Zero installation', $result);
    // No line should start with a tab or run of spaces.
    $this->assertDoesNotMatchRegularExpression('/^[ \t]+\S/m', $result);
  }

  /**
   * Even-length leading space runs that are NOT list items are stripped.
   *
   * Layout markup often indents with an even number of spaces, which must not
   * be mistaken for list nesting; only real "- " bullets keep their indent.
   */
  public function testNonListEvenIndentationStripped(): void {
    $result = $this->extractor->extract('<div>          Run entirely in your browser</div>');
    $this->assertMatchesRegularExpression('/^Run entirely/m', $result);
  }

  /**
   * Interior runs of spaces/tabs collapse to a single space.
   */
  public function testInteriorWhitespaceCollapsed(): void {
    $result = $this->extractor->extract("<p>word1 \t   word2</p>");
    $this->assertStringContainsString('word1 word2', $result);
  }

  /**
   * List bullets and nesting indentation are preserved by the cleanup.
   */
  public function testListIndentationSurvivesWhitespaceCleanup(): void {
    $html = '<ul><li>top<ul><li>nested</li></ul></li></ul>';
    $result = $this->extractor->extract($html);
    $this->assertMatchesRegularExpression('/^- top/m', $result);
    $this->assertMatchesRegularExpression('/^  - nested/m', $result);
  }

}
