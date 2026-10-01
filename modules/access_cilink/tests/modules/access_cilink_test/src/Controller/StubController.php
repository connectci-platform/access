<?php

namespace Drupal\access_cilink_test\Controller;

/**
 * Stub for the KB resource detail page.
 */
class StubController {

  /**
   * Returns a marker so tests can tell the page was reached.
   *
   * @return array<string, string>
   *   A render array.
   */
  public function detail(): array {
    return ['#markup' => 'KB resource detail stub'];
  }

}
