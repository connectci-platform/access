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
    // Retrieve the keys from the key repository.
    $headers = $this->key->getKey('xsede_api')->getKeyValue();
    // Ensure $headers is a string before passing it to explode().
    // Default to an empty string if null.
    $headers = $headers ?? '';

    // Explode the headers into an array.
    $this->headerKeys = explode(',', $headers);

  }

  /**
   * Make Api call to pull in results.
   */
  private function apiCall($path) {
    $headers = $this->headerKeys;
    if (count($headers) < 3) {
      $this->messenger->addMessage($this->t('No Allocations API keys found.'), 'warning');
      return;
    }

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
      $response = Xss::filter($response);

      $this->apiResults = json_decode($response, TRUE);
    }
    catch (RequestException $e) {
      $this->messenger->addMessage($this->t('An error occurred with the Allocations API.'), 'error');
      $this->logger->error($e->getMessage());
    }

  }

  /**
   * Get the ramps API key for identity API calls.
   *
   * @return string|null
   *   The API key or NULL if not found.
   */
  private function getRampsApiKey() {
    $path = \Drupal::service('file_system')->realpath("private://") . '/.keys/secrets.json';
    if (!file_exists($path)) {
      $this->logger->error('Unable to get ramps API key. File not found: @path', ['@path' => $path]);
      return NULL;
    }
    $secretsData = json_decode(file_get_contents($path), TRUE);
    return $secretsData['ramps_api_key'] ?? NULL;
  }

  /**
   * Make Api call to identity API endpoints.
   */
  private function identityApiCall($path) {
    $apiKey = $this->getRampsApiKey();
    if (!$apiKey) {
      $this->messenger->addMessage($this->t('No Identity API key found.'), 'warning');
      return;
    }

    $url = $this->apiBaseUrl . $path;

    try {
      $response = $this->httpClient->get($url, [
        'verify' => TRUE,
        'headers' => [
          'XA-API-KEY' => $apiKey,
          'XA-REQUESTER' => 'MATCH',
          'Content-Type' => 'application/json',
        ],
      ])->getBody()->getContents();
      $response = Xss::filter($response);

      $this->apiResults = json_decode($response, TRUE);
    }
    catch (RequestException $e) {
      $status_code = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'unknown';
      $error_body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'no response body';

      $this->logger->error('Identity API call failed with status @status. URL: @url, Error: @error, Response: @response', [
        '@status' => $status_code,
        '@url' => $url,
        '@error' => $e->getMessage(),
        '@response' => $error_body,
      ]);

      if ($status_code == 401) {
        $this->messenger->addMessage($this->t('Authentication failed for Allocations API. Please check API credentials.'), 'error');
      }
      else {
        $this->messenger->addMessage($this->t('An error occurred with the Allocations API.'), 'error');
      }
    }

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

    if (!empty($this->apiResults['result'])) {
      foreach ($this->apiResults['result'] as $result) {
        $key = $result['grantNumber'];
        $title = $result['title'];
        $this->grantList["$key"] = $title;
      }
    }

    return $this->grantList;
  }

  /**
   * Make Api call to pull in users spState.
   */
  public function getSpState($grant_id, $user) {
    $this->apiCall($this->apiUrl . $grant_id);

    $this->spState = '';
    if ($this->apiResults != NULL) {
      foreach ($this->apiResults['result'] as $result) {
        if ($result['username'] == $user) {
          $this->spState = $result['spState'];
          break;
        }
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
    $this->apiCall('/acdb/usermanagement/v1/requests/request/' . $grantId);

    if (isset($this->apiResults['result']['masters'][0]['requests'][0]['projectTitle'])) {
      return $this->apiResults['result']['masters'][0]['requests'][0]['projectTitle'];
    }

    \Drupal::logger('access_events')
      ->warning('XSEDE API getTitle() failed to retrieve title for grant @grant_id', [
        '@grant_id' => $grantId,
      ]);
    return NULL;
  }

  /**
   * Get person profile from identity API.
   *
   * @param string $username
   *   The username to look up.
   *
   * @return array|null
   *   The person profile data or NULL if not found.
   */
  public function getPersonProfile($username) {
    $this->identityApiCall('/identity/profiles/v1/people/' . $username);

    if (!empty($this->apiResults)) {
      return $this->apiResults;
    }

    $this->logger->warning('No profile data returned from identity API for username: @username', [
      '@username' => $username,
    ]);
    return NULL;
  }

  /**
   * Check if a person is eligible for allocation access.
   *
   * @param string $username
   *   The username to check.
   *
   * @return array
   *   An array with keys:
   *   - 'eligible': (bool) TRUE if eligible, FALSE otherwise.
   *   - 'reason': (string|null) The eligibleReason from the API, if available.
   */
  public function isPersonEligible($username) {
    $profile = $this->getPersonProfile($username);

    if ($profile && isset($profile['isEligible'])) {
      // API returns "yes" or "no" as a string, not a boolean.
      $is_eligible = ($profile['isEligible'] === 'yes');
      $reason = $profile['eligibleReason'] ?? NULL;

      return [
        'eligible' => $is_eligible,
        'reason' => $reason,
      ];
    }

    // If we can't determine eligibility, default to FALSE for safety.
    $this->logger->warning('Could not determine eligibility for @username (isEligible field missing). Defaulting to NOT ELIGIBLE.', [
      '@username' => $username,
    ]);
    return [
      'eligible' => FALSE,
      'reason' => NULL,
    ];
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
      'comment' => "class registration add",
      'usernames' => $usernames,
    ];

    $post = json_encode($api_body);
    $path = $this->apiUrl . $grantNumber . '/' . $action;
    $this->apiPost($path, $post);

  }

}
