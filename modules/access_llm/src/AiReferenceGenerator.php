<?php

namespace Drupal\access_llm;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\key\KeyRepository;
use Drupal\node\NodeInterface;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;

/**
 * Class AiReferencesGenerator.
 */
class AiReferenceGenerator {

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\key\KeyRepository
   */
  protected $key;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Theme initialization service.
   *
   * @var \Drupal\Core\Theme\ThemeInitializationInterface
   */
  protected $themeInitialization;

  /**
   * The theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * The prompt.
   *
   * @var string
   */
  protected $prompt;

  /**
   * The Taxonomy array.
   *
   * @var array
   */
  protected $termData;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new AiReferencesGenerator object.
   */
  public function __construct(
    KeyRepository $key_repository,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    ConfigFactoryInterface $config,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $channel_factory,
    ThemeInitializationInterface $theme_initialization,
    ThemeManagerInterface $theme_manager,
  ) {
    $this->key = $key_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->config = $config;
    $this->currentUser = $current_user;
    $this->logger = $channel_factory->get('ai_auto_reference');
    $this->themeInitialization = $theme_initialization;
    $this->themeManager = $theme_manager;
  }

  /**
   * Gets AI auto-reference configuration per bundle.
   *
   * @param string $bundle
   *   Node bundle machine name.
   *
   * @return array
   *   Array with configurations.
   */
  public function getBundleAiReferencesConfiguration($bundle) {
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load("node.{$bundle}.default");

    $ai_references = [];
    foreach ($form_display->getComponents() as $field_name => $component) {
      // Add AI autogenerate button only if at least one field is configured.
      if (!empty(($component['third_party_settings']['ai_auto_reference']))) {
        $ai_references[] = [
          'field_name' => $field_name,
          'view_mode' => $component['third_party_settings']['ai_auto_reference']['view_mode'],
        ];
      }
    }

    return $ai_references;
  }
  

  /**
   *
   */
  public function generateTaxonomyPrompt($vid, $depth, $contents) {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid);
    foreach ($terms as $term) {
      if ($depth === 0) {
        $term_data[$term->tid] = $term->name;
      }
      elseif ($depth > 0) {
        if ($term->depth == $depth) {
          $term_data[$term->tid] = $term->name;
        }
      }
    }
    $this->termData = $term_data;
    $imploded_keywords = implode(' | ', $term_data);

    $prompt = 'For the contents within brackets: ({CONTENTS}) ';
    $prompt .= 'Which two to four of the following | separated options are highly relevant and moderately relevant? [{POSSIBLE_RESULTS}] ';
    $prompt .= 'Return selections from within the square brackets only and as a valid json array within two array keys "highly" and "moderately" for your relevance';

    // Run replacements in the prompt.
    $prompt = str_replace('{POSSIBLE_RESULTS}', $imploded_keywords, $prompt);
    $prompt = str_replace('{CONTENTS}', $contents, $prompt);

    $this->prompt = $prompt;
  }

  /**
   *
   */
  public function taxonomyIdSuggested() {
    $suggestion = $this->aiApiCall();
    $suggestions = json_decode($suggestion);
    $tag_suggestions = [];
    foreach ($suggestions->highly as $item) {
      $search = array_search($item, $this->termData);
      if ($search) {
        $tag_suggestions[] = $search;
      }
    }
    return $tag_suggestions;
  }

  /**
   * Generate a summary text prompt.
   */
  public function generateSummaryPrompt($text) {

    $prompt = 'For the contents within brackets: ({BODY}) ';
    $prompt .= 'Return a summary of the text.';
    $prompt .= 'It is very important that you do not exceed 15 tokens.';

    $prompt = str_replace('{BODY}', $text, $prompt);

    $this->prompt = $prompt;
  }

  /**
   * Generate a summary text.
   */
  public function summarySuggested() {
    $suggestion = $this->aiApiCall();

    return $suggestion;
  }

  /**
   * Performs AI API call.
   *
   * @return string
   *   AI answer.
   */
  public function aiApiCall() {
    $result = '';
    $key_id = 'access_llm_open_ai_api';
    $api_key = $this->key->getKey($key_id)->getKeyValue();
    $openai_client = \OpenAI::client($api_key);
    $service_model = 'gpt-4';

    // Default payload applicable to all models.
    $payload = [
      'model' => $service_model,
      'messages' => [
        [
          'role' => 'user',
          'content' => $this->prompt,
        ],
      ],
    ];

    // Request a response in JSON for models that support it, which is anything
    // after GPT 4 (eg, turbo, preview, etc).
    if (!in_array($service_model, ['gpt-3.5-turbo', 'gpt-4'])) {
      $payload['response_format'] = [
        'type' => 'json_object',
      ];
    }
    $response = $openai_client->chat()->create($payload);
    if (isset($response->choices[0]->message->content)) {
      $result = $response->choices[0]->message->content;
    }
    return $result;
  }

}
