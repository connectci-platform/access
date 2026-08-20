<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Url;

/**
 * Lookup the end of the url.
 *
 * @EndUrl(
 *   id = "end_url",
 *   title = @Translation("End URL"),
 *   description = @Translation("Lookup the end of the url.")
 * )
 */
class EndUrl {

  /**
   * End of the url.
   *
   * @var string
   */
  private string $urlEnd;

  /**
   * Url parts.
   *
   * @var string[]
   */
  private array $urlParts;

  /**
   * Splits the current request path into its parts.
   */
  public function __construct() {
    $current_url = Url::fromRoute('<current>');
    $url_clean = $current_url->toString() ? Xss::filter($current_url->toString()) : '';
    $url_parts = explode('/', $url_clean);
    $this->urlParts = $url_parts;
    $this->urlEnd = trim(end($url_parts));
  }

  /**
   * Returns a single path part.
   *
   * @param int|string $arg
   *   The zero-based index of the path part.
   *
   * @return string|false
   *   The path part, or FALSE when the index does not exist.
   */
  public function getUrlArg(int|string $arg): string|bool {
    if (isset($this->urlParts[$arg]) === FALSE) {
      return FALSE;
    }
    return $this->urlParts[$arg];
  }

  /**
   * Returns the last part of the current path.
   *
   * @return string
   *   The last path part.
   */
  public function getUrlEnd(): string {
    return $this->urlEnd;
  }

}
