<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Utility\Xss;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Interact with xsede api.
 */
class XsedeApi {

  /**
   * The beginning of api call url.
   *
   * @var string
   */
  protected $apiBaseUrl = 'https://allocations-api.access-ci.org';

  /**
   * The beginning of api call path.
   *
   * @var string
   */
  protected $apiUrl = '/acdb/usermanagement/v1/users/';

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
   * Messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  use StringTranslationTrait;

  /**
   * Construct object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    KeyRepositoryInterface $key_repository,
    ClientInterface $http_client,
    LoggerInterface $logger,
    MessengerInterface $messenger,
    TranslationInterface $string_translation,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->key = $key_repository;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->messenger = $messenger;
    $this->setStringTranslation($string_translation);

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

    $url = $this->apiBaseUrl . $path;

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

    $this->apiResults = json_decode($response, TRUE);
  }

  /**
   * Make Api post.
   */
  private function apiPost($path, $body) {
    $headers = $this->headerKeys;

    $url = $this->apiBaseUrl . $path;

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
      $this->messenger->addMessage($this->t('An error occurred.'), 'error');
      if (strpos($e->getMessage(), '400 Bad Request') !== FALSE) {
        \Drupal::messenger()->addMessage(
          $this->t('Username not found for Allocation'),
          'error'
        );
      }
      else {
        \Drupal::messenger()->addMessage(
          $this->t('An error occurred.'),
          'error'
        );
      }
    }
  }

  /**
   * Make Api call to pull in users grant results.
   */
  public function getGrantList($user) {
    $this->apiCall($this->apiUrl . $user . '/projects_managed');

    $this->grantList = [];
    foreach ($this->apiResults['result'] as $result) {
      $key = $result['grantNumber'];
      $title = $result['title'];
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
    foreach ($this->apiResults['result'] as $result) {
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
    $this->apiCall($this->apiUrl . $grant);

    return $this->apiResults;
  }

  /**
   * Get grant title.
   */
  public function getTitle($grantId) {
    $this->apiCall($this->apiUrl . '/requests/request/' . $grantId);

    return $this->apiResults['result']->masters[0]->requests[0]->projectTitle;
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
    $path = $this->apiUrl . $grantNumber . '/' . $action;
    $this->apiPost($path, $post);

  }

}
