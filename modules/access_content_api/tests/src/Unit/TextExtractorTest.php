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

}
