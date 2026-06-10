<?php

namespace Drupal\access_content_api;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Walks a node's effective Layout Builder layout and renders components.
 */
class LayoutWalker {

  const DENYLIST_EXACT = [
    'field_block:node:page:comment_node_page',
    'field_block:node:page:field_image',
    'extra_field_block:node:page:links',
    'layout_builder_blank',
  ];

  const DENYLIST_PREFIX = [
    'views_block:',
    'system_',
    'menu_',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected BlockManagerInterface $blockManager,
    protected RendererInterface $renderer,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
    protected ContentEligibility $eligibility,
  ) {}

  /**
   * Renders a node's layout in text view mode and returns concatenated HTML.
   *
   * Cache metadata from rendered block plugins is merged into $cacheMetadata
   * so the caller can bubble it to the response.
   */
  public function render(NodeInterface $node, CacheableMetadata $cacheMetadata): string {
    $sections = $this->getSections($node);
    if (empty($sections)) {
      return $this->renderFallback($node, $cacheMetadata);
    }

    $parts = [];
    foreach ($sections as $section) {
      foreach ($section->getComponents() as $component) {
        $pluginId = $component->getPluginId();
        if ($this->isDenylisted($pluginId)) {
          continue;
        }
        $html = $this->renderComponent($component, $node, $cacheMetadata);
        if ($html !== '') {
          $parts[] = $html;
        }
      }
    }

    return implode("\n", $parts);
  }

  /**
   * Returns the effective sections for the node's layout.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The layout sections.
   */
  private function getSections(NodeInterface $node): array {
    // Check for per-node layout override.
    if ($node->hasField('layout_builder__layout') && !$node->get('layout_builder__layout')->isEmpty()) {
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $field */
      $field = $node->get('layout_builder__layout');
      return $field->getSections();
    }

    // Fall back to default display sections.
    $display = $this->entityTypeManager
      ->getStorage('entity_view_display')
      ->load('node.' . $node->bundle() . '.default');

    if ($display && $display->isLayoutBuilderEnabled()) {
      return $display->getSections();
    }

    return [];
  }

  /**
   * Falls back to rendering the full node in text view mode.
   */
  private function renderFallback(NodeInterface $node, CacheableMetadata $cacheMetadata): string {
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->view($node, $this->eligibility->getTextViewMode());
    $html = '';
    $context = new RenderContext();
    $this->renderer->executeInRenderContext($context, function () use (&$html, $build) {
      $html = (string) $this->renderer->render($build);
    });
    if (!$context->isEmpty()) {
      $cacheMetadata->addCacheableDependency($context->pop());
    }
    return $html;
  }

  /**
   * Renders a single layout component, returning HTML or empty string on error.
   */
  private function renderComponent(SectionComponent $component, NodeInterface $node, CacheableMetadata $cacheMetadata): string {
    try {
      /** @var \Drupal\Core\Block\BlockPluginInterface&\Drupal\Core\Plugin\ContextAwarePluginInterface $plugin */
      $plugin = $component->getPlugin();

      $contexts = $plugin->getContextDefinitions();
      if (isset($contexts['entity'])) {
        $plugin->setContextValue('entity', $node);
      }
      if (isset($contexts['view_mode'])) {
        $plugin->setContextValue('view_mode', $this->eligibility->getTextViewMode());
      }

      $build = $plugin->build();
      if (empty($build)) {
        return '';
      }

      $html = '';
      $context = new RenderContext();
      $this->renderer->executeInRenderContext($context, function () use (&$html, $build) {
        $html = (string) $this->renderer->render($build);
      });
      if (!$context->isEmpty()) {
        $cacheMetadata->addCacheableDependency($context->pop());
      }
      return $html;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to render component @plugin: @message', [
        '@plugin' => $component->getPluginId(),
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Returns TRUE if the plugin ID matches the component denylist.
   */
  private function isDenylisted(string $pluginId): bool {
    if (in_array($pluginId, self::DENYLIST_EXACT, TRUE)) {
      return TRUE;
    }
    foreach (self::DENYLIST_PREFIX as $prefix) {
      if (str_starts_with($pluginId, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
