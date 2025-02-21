<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Utility\Xss;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Interact with xsede api.
 */
class XsedeApi {

  /**
   * The beginning of api call url.
   *
   * @var string
   */
  protected $apiUrl = '/xdcdb-api-test/usermanagement/v1/users/';

  /**
   * Store header keys.
   *
   * @var array
   */
  protected $headerKeys;

  /**
   * Api Results.
   *
   * @var array
   */
  protected $apiResults;

  /**
   * Grant List.
   *
   * @var array
   */
  protected $grantList;

  /**
   * Sp state.
   *
   * @var string
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
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Construct object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    KeyRepositoryInterface $key_repository,
    ClientInterface $http_client,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->key = $key_repository;
    $this->httpClient = $http_client;
    $this->logger = $logger;

    $this->headerKeys();
  }

  /**
   * Get header keys.
   */
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

    try {
      $response = $this->httpClient->get($url, [
        'verify' => TRUE,
        'headers' => [
          'XA-RESOURCE' => $headers[0],
          'XA-AGENT' => $headers[1],
          'XA-API-KEY' => $headers[2],
        ],
      ])->getBody()->getContents();

    }
    catch (RequestException $e) {
      $this->logger->error($e->getMessage());
    }

    $response = Xss::filter($response);

    $this->apiResults = json_decode($response);
  }

  /**
   * Make Api post.
   */
  private function apiPost($path, $body) {
    $headers = $this->headerKeys;

    $url = 'https://a3mdev.xsede.org' . $path;

    try {
      $this->httpClient->post($url, [
        'verify' => TRUE,
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
      $this->logger->error($e->getMessage());
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
   *
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
    $this->apiPost($path, $post);

  }

}
