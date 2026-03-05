<?php

namespace Drupal\access_misc\Plugin\Util;

/**
 * Views tools.
 */
class ViewsTools {

  /**
   * Construct object.
   */
  public function __construct() {
  }

  /**
   * Set the footer handler on a view.
   *
   * @param object $view
   *   The view object.
   * @param string $url_title
   *   The link text for the footer URL.
   * @param string $url
   *   The footer URL.
   * @param string $id
   *   The handler ID.
   */
  public function setFooter($view, $url_title, $url, $id) {
    $options = [
      'id' => 'area_text_custom',
      'table' => 'views',
      'field' => 'area_text_custom',
      'relationship' => 'none',
      'group_type' => 'none',
      'admin_label' => '',
      'empty' => FALSE,
      'tokenize' => FALSE,
      'content' => '<a href="' . $url . '" class="btn btn-md-teal mb-6 ms-0">' . $url_title . '</a>',
      'plugin_id' => 'text_custom',
    ];
    return $view->setHandler($id, 'footer', 'area', $options);
  }

}
