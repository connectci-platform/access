<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests that API text output is independent of the active theme's templates.
 *
 * The test theme overrides field.html.twig and field--text.html.twig (as the
 * support theme does) and prints a sentinel. The API must never show it, and
 * public rendering of real "text" fields must still use the theme.
 *
 * @group access_content_api
 */
class ContentThemeIndependenceTest extends ContentApiKernelTestBase {

  const SENTINEL = 'THEME-FIELD-OVERRIDE';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('theme_installer')->install(['access_content_api_test_theme']);
    $this->config('system.theme')->set('default', 'access_content_api_test_theme')->save();
    \Drupal::theme()->resetActiveTheme();
    \Drupal::service('theme.registry')->reset();
    $this->grantAnonymousAccessContent();
  }

  /**
   * Asserts the test theme is really active.
   */
  protected function assertThemeActive(): void {
    $this->assertSame('access_content_api_test_theme', \Drupal::theme()->getActiveTheme()->getName());
    $build = [
      '#theme' => 'field',
      '#title' => 'Probe',
      '#label_display' => 'hidden',
      '#view_mode' => 'default',
      '#language' => 'en',
      '#field_name' => 'probe',
      '#field_type' => 'string',
      '#field_translatable' => FALSE,
      '#entity_type' => 'node',
      '#bundle' => 'page',
      '#object' => NULL,
      '#is_multiple' => FALSE,
      '#items' => [],
      0 => ['#markup' => 'probe'],
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString(self::SENTINEL, $html, 'The test theme field template is in use.');
  }

  /**
   * An API list field reads "Label: value" without the theme override.
   */
  public function testApiListFieldIgnoresThemeOverride(): void {
    $this->assertThemeActive();
    $this->createMatchBundle();
    $node = $this->createContentNode('match_engagement', [
      'body' => ['value' => '<p>Body</p>', 'format' => 'basic_html'],
      'field_status' => 'complete',
    ]);

    $text = $this->decode($this->requestById($node->id()))['text'];
    $this->assertStringContainsString('Status: Complete', $text);
    $this->assertStringNotContainsString(self::SENTINEL, $text);
  }

  /**
   * Public rendering of a real "text" field still uses the theme template.
   */
  public function testPublicTextFieldStillUsesTheme(): void {
    $this->assertThemeActive();
    $node = $this->createNodeWithTextTypeField();

    $build = \Drupal::entityTypeManager()->getViewBuilder('node')->view($node, 'default');
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Plain text value', $html);
    $this->assertStringContainsString(self::SENTINEL, $html, 'Public rendering uses the theme template.');
  }

  /**
   * A real "text" field in the text display reads "Label: value" in the API.
   */
  public function testApiTextTypeFieldIgnoresThemeOverride(): void {
    $this->assertThemeActive();
    $node = $this->createNodeWithTextTypeField();

    $text = $this->decode($this->requestById($node->id()))['text'];
    $this->assertStringContainsString('Notes: Plain text value', $text);
    $this->assertStringNotContainsString(self::SENTINEL, $text);
  }

  /**
   * Creates a page with a field of type "text" in default and text displays.
   */
  protected function createNodeWithTextTypeField(): object {
    FieldStorageConfig::create([
      'field_name' => 'field_notes',
      'entity_type' => 'node',
      'type' => 'text',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_notes',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Notes',
    ])->save();
    foreach (['default', 'text'] as $mode) {
      $display = EntityViewDisplay::load("node.page.$mode") ?: EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'page',
        'mode' => $mode,
        'status' => TRUE,
      ]);
      $display->setComponent('field_notes', [
        'type' => 'text_default',
        'label' => 'inline',
        'weight' => 5,
      ]);
      $display->save();
    }
    return $this->createPage([
      'field_notes' => ['value' => 'Plain text value', 'format' => 'basic_html'],
    ]);
  }

}
