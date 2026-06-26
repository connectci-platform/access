<?php

namespace Drupal\access_content_api;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;

/**
 * Renders a node to plain text and hashes it, in an isolated render context.
 *
 * Shared by the per-doc content endpoint and the content index so both emit
 * the SAME content_hash for a given node. The index renders many nodes in a
 * loop; without render isolation, shared render state (breadcrumb, active
 * trail, region population) could make the same node hash differently in-loop
 * versus standalone. executeInRenderContext() gives each node a clean context.
 */
class RenderHash {

  public function __construct(
    protected LayoutWalker $layoutWalker,
    protected TextExtractor $textExtractor,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Extracted plain text for a node, rendered in isolation.
   */
  public function extractedText(NodeInterface $node, CacheableMetadata $cacheMetadata): string {
    // executeInRenderContext() pushes a fresh RenderContext and pops it again
    // even if the callback throws, so each node renders against a clean context
    // — this is what makes the in-loop index hash equal the standalone per-doc
    // hash. If profiling ever shows static #attached / placeholder state
    // bleeding across iterations, wrap LayoutWalker::render in a try/finally
    // that calls the renderer's reset path; not expected to be needed.
    $html = $this->renderer->executeInRenderContext(
      new RenderContext(),
      fn() => $this->layoutWalker->render($node, $cacheMetadata)
    );
    return $this->textExtractor->extract($html);
  }

  /**
   * Hash already-extracted text. The single home of the hash algorithm so the
   * per-doc endpoint and the index can't drift apart.
   */
  public function hashText(string $text): string {
    return hash('sha256', $text);
  }

  /**
   * SHA-256 of the extracted text. This is the content fingerprint consumers
   * use to skip re-embedding unchanged pages even when last_modified shifts.
   */
  public function contentHash(NodeInterface $node, CacheableMetadata $cacheMetadata): string {
    return $this->hashText($this->extractedText($node, $cacheMetadata));
  }

}
