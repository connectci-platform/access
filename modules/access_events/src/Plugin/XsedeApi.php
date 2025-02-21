<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Interact with xsede api.
 */
class XsedeApi {

  /**
   * The beginning of api call url.
   *
   * @string $url
   */
  protected $apiUrl = '/xdcdb-api-test/usermanagement/v1/users/';

  /**
   * Store header keys.
   *
   * @array $header_keys
   */
  protected $headerKeys;

  /**
   * Api Results.
   *
   * @array $api_results
   */
  protected $apiResults;

  /**
   * Grant List.
   *
   * @array $grant_list
   */
  protected $grantList;

  /**
   * sp state.
   *
   * @string $sp_state
   */
  protected $spState;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $key;

  /**
   * Run Entity Query.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Construct object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    KeyRepositoryInterface $key_repository
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->key = $key_repository;

    $this->headerKeys();
  }

  private function headerKeys() {
    $headers = $this->key->getKey('xsede_api')->getKeyValue();
    $this->headerKeys = explode(",", $headers);
  }

  /**
   * Make Api call to pull in results.
   */
  private function apiCall($path) {
    $headers = $this->headerKeys;

    $url = 'https://a3mdev.xsede.org' . $path;

    $client = \Drupal::httpClient();
    try {
      $response = \Drupal::httpClient()->get($url, [
        'verify' => true,
        'headers' => [
          'XA-RESOURCE' => $headers[0],
          'XA-AGENT' => $headers[1],
          'XA-API-KEY' => $headers[2],
        ],
      ])->getBody()->getContents();

    }
    catch (RequestException $e) {
      \Drupal::logger('access_events')->error($e->getMessage());
    }

    $this->apiResults = json_decode($response);
  }

  /**
   * Make Api post.
   */
  private function apiPost($path, $body) {
    $headers = $this->headerKeys;

    $url = 'https://a3mdev.xsede.org' . $path;

    $client = \Drupal::httpClient();
    try {
        $response = $client->post($url, [
            'verify' => true,
            'headers' => [
                'XA-RESOURCE' => $headers[0],
                'XA-AGENT' => $headers[1],
                'XA-API-KEY' => $headers[2],
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ])->getBody()->getContents();

    }
    catch (RequestException $e) {
      \Drupal::logger('access_events')->error($e->getMessage());
    }
  }

  /**
   * Make Api call to pull in users grant results.
   */
  public function getGrantList($user) {
    $this->apiCall('/xdcdb-api-test/usermanagement/v1/users/' . $user . '/projects_managed');

    $this->grantList = [];
    foreach ($this->apiResults->result as $result) {
      $key = $result->grantNumber;
      $title = $result->title;
      $this->grantList["$key"] = $title;
    }

    return $this->grantList;
  }

  /**
   * Make Api call to pull in users spState.
   */
  public function getSpState($grant_id, $user) {
    $this->apiCall($this->apiUrl . $grant_id);

    $this->spState = '';
    foreach ($this->apiResults->result as $result) {
      if ($result->username == $user) {
        $this->spState = $result->spState;
        break;
      }
    }

    return $this->spState;
  }

  /**
   * Make Api call to pull in user list for a given grant.
   */
  public function getGrantedUsers($grant) {
    $this->apiCall('/xdcdb-api-test/usermanagement/v1/users/' . $grant);

    return $this->apiResults;
  }

  /**
   * Add User.
   */
  public function setGrantedUsers($grantNumber, $usernames) {
    $this->addRemoveUsers($grantNumber, $usernames, 'add_multiple');
  }

  /**
   * Remove User.
   */
  public function removeGrantedUsers($grantNumber, $usernames) {
    $this->addRemoveUsers($grantNumber, $usernames, 'remove_multiple');
  }

  /**
   * Make Api post to update user list.
   * @string $grantNumber
   *    The grant number.
   *  @array $usernames
   *    The usernames to add.
   */
  private function addRemoveUsers($grantNumber, $usernames, $action) {
    $api_body = [
      'comment' => "bulk add",
      'usernames' => $usernames,
    ];


    $post = json_encode($api_body);
    $path = '/xdcdb-api-test/usermanagement/v1/users/' . $grantNumber . '/' . $action;
    $this->apiPost($path, $post);;
  }

}
