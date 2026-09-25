<?php

namespace Drupal\access_misc\Plugin\views\style;

use Drupal\views\Plugin\views\style\Table;

/**
 * Table style that ignores ?order= values the table does not offer.
 *
 * Core's Table style click-sorts on any field named in ?order=, even one not
 * marked sortable. Bots requesting URLs like
 * /tags/foo?order=webform_submission_value_1 made the resources view
 * click-sort a webform field that never joined into the query, producing
 * ORDER BY "unknown" and a 500. This only honors the order values core would
 * render as sortable header links; anything else is treated as though no
 * order was requested, so the table's default sort applies.
 *
 * Swapped in for core's table plugin by
 * access_misc_views_plugins_style_alter().
 */
class ClickSortGuardedTable extends Table {

  /**
   * {@inheritdoc}
   */
  public function buildSort(): bool {
    return $this->withoutUnsortableOrder(fn() => parent::buildSort());
  }

  /**
   * {@inheritdoc}
   */
  public function buildSortPost(): void {
    $this->withoutUnsortableOrder(fn() => parent::buildSortPost());
  }

  /**
   * Whether this table offers a click sort on the given field.
   *
   * Mirrors the sortable header link check in
   * template_preprocess_views_view_table().
   */
  public function isClickSortable(string $field): bool {
    $handler = $this->view->field[$field] ?? NULL;
    if (!$handler) {
      return FALSE;
    }
    $columns = $this->sanitizeColumns($this->options['columns']);
    return ($columns[$field] ?? NULL) === $field
      && empty($handler->options['exclude'])
      && !empty($this->options['info'][$field]['sortable'])
      && $handler->clickSortable();
  }

  /**
   * Runs a parent sort method with an unsortable ?order= hidden from it.
   */
  protected function withoutUnsortableOrder(callable $callback): mixed {
    $query = $this->view->getRequest()->query;
    $order = $query->get('order');
    if ($order === NULL || $this->isClickSortable((string) $order)) {
      return $callback();
    }

    // The request is shared with every other view on the page, so only hide
    // the order for this call and put the query back exactly as it was.
    $original = $query->all();
    $query->remove('order');
    try {
      return $callback();
    }
    finally {
      $query->replace($original);
    }
  }

}
