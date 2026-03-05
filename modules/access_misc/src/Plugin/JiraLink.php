<?php

namespace Drupal\access_misc\Plugin;

use Drupal\Core\Url;

/**
 * Environment icon to be used on header title.
 *
 * @JiraLink(
 *   id = "jira_link",
 *   title = @Translation("Create Jira Link"),
 *   description = @Translation("Create Jira link")
 * )
 */
class JiraLink {

  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  /**
   * Store link.
   *
   * @var string
   */
  private $link;

  /**
   * Query block and pull bid via uuid.
   */
  public function __construct($link, $text) {
    if (\Drupal::currentUser()->isAuthenticated()) {
      $username = \Drupal::currentUser()->getAccountName();
      $username = explode('@', $username);
      $username = $username[0];
      $display_name = \Drupal::currentUser()->getDisplayName();
      $uri = Url::fromUri('https://access-ci.atlassian.net/servicedesk/customer/portal/2/group/3/create/17',
        [
          'query' => [
            'customfield_10103' => $username,
            'customfield_10108' => $display_name,
          ],
        ]
      );
      $url = $uri->toString();
      $link = [
        '#type' => 'inline_template',
        '#template' => '<a class="btn btn-primary" href="{{ link }}">{{ text }}</a>',
        '#context' => [
          'link' => $url,
          'text' => $text,
        ],
        '#attached' => [
          'library' => [
            'access_misc/misc_library',
          ],
        ],
      ];
    }
    else {
      $link = [
        '#type' => 'inline_template',
        '#template' => '<a class="btn btn-primary" href="/user/login">{{ text }}</a>',
        '#context' => [
          'text' => $this->t('Login to') . $text,
        ],
      ];
    }
    $this->link = $link;
  }

  /**
   * Get link.
   */
  public function getLink() {
    return $this->link;
  }

}
