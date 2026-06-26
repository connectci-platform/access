<?php

namespace Drupal\access_events\Plugin\views\field;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\search_api\Plugin\views\field\SearchApiStandard;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\ResultRow;

/**
 * Search API Views field handler that emits decoded plain text.
 *
 * The default handler HTML-escapes values (e.g. ' becomes &#039;), which is
 * wrong when the value is serialized into the events REST API JSON: it is noise
 * for JSON consumers and inflates the length past the documented limit. This
 * handler returns the value as plain text instead. Any markup is stripped
 * first, so wrapping the result as safe Markup cannot introduce an XSS vector
 * in the view's HTML displays. The plain text is then capped at the documented
 * 150-character summary limit; the eventseries form validates new summaries to
 * the same length, but historical content can still exceed it, so the API path
 * enforces the cap itself. Registered against event_summary via
 * access_events_views_data_alter().
 *
 * @ingroup views_field_handlers
 */
#[ViewsField('access_events_search_api_plain_text')]
class SearchApiPlainText extends SearchApiStandard {

  /**
   * {@inheritdoc}
   */
  public function getItems(ResultRow $values) {
    $items = parent::getItems($values);
    foreach ($items as &$item) {
      if (isset($item['value']) && is_string($item['value'])) {
        // Strip any tags, then decode entities to plain text. Stripping first
        // keeps the value safe to mark as Markup (no live HTML can survive).
        $plain = Html::decodeEntities(strip_tags($item['value']));
        // Enforce the documented 150-character summary cap. Use mb_* so a
        // multibyte character is never split. Matches the 147 + ellipsis the
        // eventseries summary helper applies on input.
        if (mb_strlen($plain) > 150) {
          $plain = mb_substr($plain, 0, 147) . '...';
        }
        $item['value'] = Markup::create($plain);
      }
    }
    return $items;
  }

}
