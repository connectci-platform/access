<?php

namespace Drupal\access\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AI-powered content assistance endpoints.
 *
 * Wraps the existing access_llm.ai_references_generator service as JSON API
 * endpoints so MCP servers can request tag suggestions and summaries without
 * duplicating LLM logic.
 */
class ContentAssistController extends ControllerBase {

  /**
   * The AI reference generator service.
   *
   * @var \Drupal\access_llm\AiReferenceGenerator
   */
  protected $aiGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    if ($container->has('access_llm.ai_references_generator')) {
      $instance->aiGenerator = $container->get('access_llm.ai_references_generator');
    }
    return $instance;
  }

  /**
   * Suggest tags based on content text.
   *
   * POST {"text": "content to analyze", "limit": 6}
   * Returns {"tags": [{"tid": 123, "name": "big-data", "uuid": "..."}]}
   */
  public function suggestTags(Request $request): JsonResponse {
    if (!$this->aiGenerator) {
      return new JsonResponse(['error' => 'Tag suggestion service not available'], 503);
    }

    $content = json_decode($request->getContent(), TRUE);

    if (!is_array($content) || empty($content['text'])) {
      return new JsonResponse(['error' => 'Missing required "text" field'], 400);
    }

    $text = $content['text'];
    $limit = (int) ($content['limit'] ?? 6);

    if (strlen($text) < 100) {
      return new JsonResponse([
        'error' => 'Text must be at least 100 characters for tag suggestions',
      ], 400);
    }

    if (strlen($text) > 50000) {
      return new JsonResponse([
        'error' => 'Text must not exceed 50000 characters',
      ], 400);
    }

    try {
      $this->aiGenerator->generateTaxonomyPrompt('tags', 1, $text);
      $suggested_tids = array_slice($this->aiGenerator->taxonomyIdSuggested(), 0, $limit);

      $tags = [];
      if (!empty($suggested_tids)) {
        $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
        $terms = $term_storage->loadMultiple($suggested_tids);
        foreach ($terms as $term) {
          $tags[] = [
            'tid' => (int) $term->id(),
            'name' => $term->getName(),
            'uuid' => $term->uuid(),
          ];
        }
      }

      return new JsonResponse(['tags' => $tags]);
    }
    catch (\Throwable $e) {
      $this->getLogger('access')->error('Tag suggestion failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Tag suggestion service unavailable',
      ], 503);
    }
  }

  /**
   * Generate a summary for content text.
   *
   * POST {"text": "content to summarize"}
   * Returns {"summary": "A concise summary of the content."}
   */
  public function suggestSummary(Request $request): JsonResponse {
    if (!$this->aiGenerator) {
      return new JsonResponse(['error' => 'Summary generation service not available'], 503);
    }

    $content = json_decode($request->getContent(), TRUE);

    if (!is_array($content) || empty($content['text'])) {
      return new JsonResponse(['error' => 'Missing required "text" field'], 400);
    }

    $text = $content['text'];

    if (strlen($text) < 100) {
      return new JsonResponse([
        'error' => 'Text must be at least 100 characters for summary generation',
      ], 400);
    }

    if (strlen($text) > 50000) {
      return new JsonResponse([
        'error' => 'Text must not exceed 50000 characters',
      ], 400);
    }

    try {
      $this->aiGenerator->generateSummaryPrompt($text);
      $summary = $this->aiGenerator->summarySuggested();

      return new JsonResponse(['summary' => trim($summary)]);
    }
    catch (\Throwable $e) {
      $this->getLogger('access')->error('Summary generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Summary generation service unavailable',
      ], 503);
    }
  }

}
