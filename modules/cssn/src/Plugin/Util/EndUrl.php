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
  private $urlEnd;

  /**
   * Url Parts.
   *
   * @var array
   */
  private $urlParts;

  /**
   * {@inheritDoc}
   */
  public function __construct() {
    $current_url = Url::fromRoute('<current>');
    $url_clean = $current_url->toString() ? Xss::filter($current_url->toString()) : '';
    $url_parts = explode('/', $url_clean);
    $this->urlParts = $url_parts;
    $this->urlEnd = trim(end($url_parts));
  }

  /**
   * {@inheritDoc}
   */
  public function getUrlArg($arg) {
    if (isset($this->urlParts[$arg]) === FALSE) {
      return FALSE;
    }
    return $this->urlParts[$arg];
  }

  /**
   * {@inheritDoc}
   */
  public function getUrlEnd() {
    return $this->urlEnd;
  }

}
