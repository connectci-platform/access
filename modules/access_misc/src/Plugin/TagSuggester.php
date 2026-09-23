<?php

namespace Drupal\access_misc\Plugin;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Builds the shared AI tag-suggestion widget and its AJAX callback.
 *
 * Adds the field_tags_replace container (a teal "suggest" panel, the
 * Suggest Tags button, and a message region) and attaches the tag JS library.
 * Callers keep their own tag-cloud markup element (node_add_tags / custom_tags)
 * which renders the access_misc.addtags view.
 */
class TagSuggester {

  use StringTranslationTrait;

  /**
   * The 'access_misc.addtags' service.
   *
   * @var \Drupal\access_misc\Plugin\NodeAddTags
   */
  protected $addTags;

  /**
   * Constructs a TagSuggester object.
   *
   * @param \Drupal\access_misc\Plugin\NodeAddTags $add_tags
   *   The 'access_misc.addtags' service.
   */
  public function __construct(NodeAddTags $add_tags) {
    $this->addTags = $add_tags;
  }

  /**
   * Inject the tag-suggestion machinery into a form.
   *
   * @param array $form
   *   The form, by reference.
   * @param array $options
   *   Keyed options:
   *   - weight: (int) required. Weight for the field_tags_replace container.
   *   - panel_intro: (string) text shown in the teal panel.
   *   - panel_title: (string|null) optional HTML rendered above the panel.
   *   - body_field: (string) user-input key for description. Default 'body'.
   *   - tags_field: (string) user-input key for selected tags.
   *     Default 'field_tags'.
   *   - tag_limit: (int) max tags. Default 6.
   *   - min_chars: (int) min description length before suggesting. Default 100.
   *   - library: (string) JS library to attach.
   *     Default 'access_misc/node_add_tags'.
   */
  public function build(array &$form, array $options) {
    $options += [
      'panel_intro' => 'Get tag suggestions based on your description and then curate as necessary.',
      'panel_title' => NULL,
      'body_field' => 'body',
      'tags_field' => 'field_tags',
      'tag_limit' => 6,
      'min_chars' => 100,
      'library' => 'access_misc/node_add_tags',
    ];

    $form['field_tags_replace'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'field-tags-replace',
        'data-suggest' => '0',
      ],
      '#weight' => $options['weight'],
      '#tag_suggest_config' => [
        'body_field' => $options['body_field'],
        'tags_field' => $options['tags_field'],
        'tag_limit' => $options['tag_limit'],
        'min_chars' => $options['min_chars'],
      ],
    ];

    if ($options['panel_title'] !== NULL) {
      $form['field_tags_replace']['field_suggest_title'] = [
        '#markup' => $options['panel_title'],
        '#allowed_tags' => ['h2', 'h4', 'div', 'span', 'label'],
      ];
    }

    $form['field_tags_replace']['field_suggest'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['bg-light-teal', 'my-5', 'p-5'],
      ],
    ];

    $form['field_tags_replace']['field_suggest']['tag_list'] = [
      '#markup' => "<div id='match-tag-list' class='mb-3'>{$options['panel_intro']}</div>",
      '#allowed_tags' => ['div', 'label'],
    ];

    $form['field_tags_replace']['field_suggest']['replace_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Suggest Tags'),
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['ml-0'],
      ],
      '#ajax' => [
        'callback' => [static::class, 'ajaxCallback'],
        'wrapper' => 'field-tags-replace',
      ],
    ];

    $form['field_tags_replace']['user_message'] = ['#markup' => ''];

    $form['#attached']['library'][] = $options['library'];
    // Tell node_add_tags.js which select element holds the tags. Derive the
    // DOM id from the tags field key so the canonical field_tags forms keep
    // targeting #edit-field-tags unchanged.
    $form['#attached']['drupalSettings']['accessTagSuggester']['fieldId'] = 'edit-' . str_replace('_', '-', $options['tags_field']);
  }

  /**
   * Shared AJAX callback for the Suggest Tags button.
   *
   * Reads $form['field_tags_replace']['#tag_suggest_config'], asks the LLM for
   * tag suggestions, writes the user message and the data-suggest attribute,
   * and returns the rebuilt container.
   */
  public static function ajaxCallback(array &$form, FormStateInterface $form_state) {
    $config = $form['field_tags_replace']['#tag_suggest_config'] ?? [];
    $config += [
      'body_field' => 'body',
      'tags_field' => 'field_tags',
      'tag_limit' => 6,
      'min_chars' => 100,
    ];

    $raw_data = $form_state->getUserInput();
    $raw = $raw_data[$config['body_field']] ?? '';
    $body = is_array($raw) ? ($raw[0]['value'] ?? '') : $raw;
    $body_filter = $body ? Xss::filter($body) : '';

    $tag_count = count(array_filter((array) ($raw_data[$config['tags_field']] ?? [])));
    $tag_amount = $config['tag_limit'] - $tag_count;
    $suggested_tag_ids = '0';

    if ($tag_amount <= 0) {
      $form['field_tags_replace']['user_message'] = [
        '#markup' => "<div class='match-tag-list bg-blue-200 text-sky-900 my-5 p-5'><strong class='text-sky-900'>You already have {$config['tag_limit']} tags</strong><br />You can remove some tags to get more suggestions.</div>",
      ];
    }
    elseif (strlen($body_filter) >= $config['min_chars']) {
      try {
        $llm = \Drupal::service('access_llm.ai_references_generator');
        $llm->generateTaxonomyPrompt('tags', 1, $body_filter);
        $suggestions = array_slice($llm->taxonomyIdSuggested(), 0, $tag_amount);
        $suggested_tag_ids = implode(', ', $suggestions);
        $form['field_tags_replace']['user_message'] = ['#markup' => ''];
      }
      catch (\Throwable $e) {
        $form['field_tags_replace']['user_message'] = [
          '#markup' => "<div class='match-tag-list bg-yellow-200 text-yellow-900 my-5 p-5'><strong class='text-yellow-900'>AI tag suggestions are not available</strong><br />Please select tags manually from the list below.</div>",
        ];
      }
    }
    else {
      $form['field_tags_replace']['user_message'] = [
        '#markup' => "<div class='match-tag-list bg-blue-200 text-sky-900 my-5 p-5'><strong class='text-sky-900'>Fill in the description above to get suggested tags.</strong><br />Your description must be over {$config['min_chars']} characters to get a suggestion.</div>",
      ];
    }

    $form['field_tags_replace']['#attributes']['data-suggest'] = $suggested_tag_ids;
    return $form['field_tags_replace'];
  }

}
