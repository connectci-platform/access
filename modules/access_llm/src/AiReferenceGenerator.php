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
   * Gets AI auto-reference suggestions.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node object.
   * @param string $field_name
   *   Field machine name.
   * @param string $view_mode
   *   Entity view mode.
   *
   * @return array
   *   Array with suggestions.
   */
  public function getAiSuggestions(NodeInterface $node, $field_name, $view_mode) {
    $config = $this->config->get('ai_auto_reference.settings');

    try {
      // Might be 4000, 8000, 32000 depending on version used and
      // beta / waiting list access approval.
      $token_limit = $config->get('token_limit');

      $possible_results = $this->getFieldAllowedValues($node, $field_name);
      $imploded_possible_results = implode(',', $possible_results);

      // Getting current (in most cases – admin) theme.
      $active_theme = $this->themeManager->getActiveTheme();
      // Getting default theme.
      $default_theme = $this->config->get('system.theme')->get('default');

      // We need to be sure we render content in default theme.
      $this->themeManager->setActiveTheme($this->themeInitialization->initTheme($default_theme));
      $render_controller = $this->entityTypeManager->getViewBuilder($node->getEntityTypeId());

      // Get the rendered contents of the node.
      $render_output = $render_controller->view($node, $view_mode);
      $rendered_output = $this->renderer->renderPlain($render_output);
      $contents = strip_tags($rendered_output);

      // Switching back to admin theme.
      $this->themeManager->setActiveTheme($active_theme);

      // For counting the number of tokens.
      $tokenizer_config = new Gpt3TokenizerConfig();
      $tokenizer = new Gpt3Tokenizer($tokenizer_config);

      // Build the prompt to send.
      // @todo allow configuration of the prompt.
      $prompt = 'For the contents within brackets: ({CONTENTS})';
      $prompt .= 'Which two to four of the following | separated options are highly relevant and moderately relevant? [{POSSIBLE_RESULTS}]';
      $prompt .= 'Return selections from within the square brackets only and as a valid json array within two array keys "highly" and "moderately" for your relevance';

      // Node edit link to use in logs.
      $node_edit_link = Link::fromTextAndUrl($node->label(), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]))->toString();

      // If the prompt is longer than the limit, get a summary of the contents.
      $prompt_length = $tokenizer->count($prompt);
      $possible_results_length = $tokenizer->count($imploded_possible_results);
      $contents_length = $tokenizer->count($contents);
      if ($prompt_length + $possible_results_length > $token_limit) {
        $this->logger->error('The prompt and possible results alone use more tokens than the token limit for %field_name for %node_edit. The job cannot be completed', [
          '%field_name' => $field_name,
          '%node_edit' => $node_edit_link,
        ]);
      }
      elseif ($prompt_length + $possible_results_length + $contents_length > $token_limit) {
        // Make an API call to get a summary of the content.
        $available_contents_tokens = $token_limit - $prompt_length - $possible_results_length;
        // @todo allow configuration of the text shortening prompt.
        $summary_prompt = 'Shorten this text into a maximum of ' . $available_contents_tokens . ' tokens and minimum of ' . ($available_contents_tokens - 1000) . ' tokens: ';
        $summary_prompt_length = $tokenizer->count($summary_prompt);
        if ($contents_length + $summary_prompt_length > $token_limit) {

          // If even this without the possible results is too long, we
          // just crop it - no choice.
          $content_chunks = $tokenizer->chunk($contents, ($token_limit - $summary_prompt_length));
          $contents = reset($content_chunks);
        }

        // Add contents (potentially cropped) to the prompt.
        $summary_prompt .= $contents;

        // Get the summary back.
        $response = $this->aiApiCall($config, $summary_prompt);
        if (isset($response->choices[0]->message->content)) {
          // Replace the contents with summarized contents.
          $contents = $response->choices[0]->message->content;
        }
      }

      // Run replacements in the prompt.
      $prompt = str_replace('{POSSIBLE_RESULTS}', $imploded_possible_results, $prompt);
      $prompt = str_replace('{CONTENTS}', $contents, $prompt);
      // Get the array of 'highly' and 'moderately' related results back.
      if ($answer = $this->aiApiCall($config, $prompt)) {
        $answer = json_decode($answer, 1);
        $suggestions['h'] = !empty($answer['highly']) ? array_keys(array_intersect($possible_results, $answer['highly'])) : [];
        $suggestions['m'] = !empty($answer['moderately']) ? array_keys(array_intersect($possible_results, $answer['moderately'])) : [];
        return $suggestions;
      }

    }
    catch (\Exception $exception) {
      $this->logger->error('AI auto-reference generation failed for %field_name for %node_edit. Error message: %error_message', [
        '%field_name' => $field_name,
        '%node_edit' => $node_edit_link,
        '%error_message' => $exception->getMessage(),
      ]);
    }
    // Fallback to empty array.
    return [];
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
    $prompt .= 'It is very important that you do not exceed 150 characters.';

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
