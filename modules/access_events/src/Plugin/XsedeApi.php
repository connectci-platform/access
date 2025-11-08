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
      $this->logger->warning('Allocations API keys not configured properly. Expected 3 keys, found @count', [
        '@count' => count($headers),
      ]);
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

      // Log successful request for debugging.
      $this->logger->info('Allocations API GET request successful. URL: @url', [
        '@url' => $url,
      ]);
    }
    catch (RequestException $e) {
      // Get the full error response if available.
      $response_body = '';
      $status_code = 0;

      if ($e->hasResponse()) {
        $response = $e->getResponse();
        $status_code = $response->getStatusCode();
        $response_body = $response->getBody()->getContents();
      }

      // Log comprehensive error details.
      $this->logger->error('Allocations API GET request failed. URL: @url, Status: @status, Response: @response, Exception: @exception', [
        '@url' => $url,
        '@status' => $status_code,
        '@response' => $response_body,
        '@exception' => $e->getMessage(),
      ]);

      // Show user-friendly error message.
      if ($status_code == 404) {
        $this->messenger->addMessage($this->t('Resource not found in Allocations API. Please verify the grant number or username.'), 'error');
      }
      elseif ($status_code == 403) {
        $this->messenger->addMessage($this->t('Permission denied: Unable to access this allocation data.'), 'error');
      }
      elseif ($status_code >= 500) {
        $this->messenger->addMessage($this->t('Allocations API server error. Please try again later.'), 'error');
      }
      else {
        $this->messenger->addMessage($this->t('An error occurred with the Allocations API. Please check the logs for details.'), 'error');
      }
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
      $response = $this->httpClient->post($url, [
        'verify' => TRUE,
        'headers' => [
          'XA-RESOURCE' => $headers[0],
          'XA-AGENT' => $headers[1],
          'XA-API-KEY' => $headers[2],
          'Content-Type' => 'application/json',
        ],
        'body' => $body,
      ])->getBody()->getContents();

      // Log successful request for debugging.
      $this->logger->info('Allocations API POST request successful. URL: @url, Body: @body', [
        '@url' => $url,
        '@body' => $body,
      ]);

      return $response;
    }
    catch (RequestException $e) {
      // Get the full error response if available.
      $response_body = '';
      $status_code = 0;

      if ($e->hasResponse()) {
        $response = $e->getResponse();
        $status_code = $response->getStatusCode();
        $response_body = $response->getBody()->getContents();
      }

      // Decode the request body to get username info for logging.
      $request_data = json_decode($body, TRUE);
      $usernames = isset($request_data['usernames']) ? implode(', ', $request_data['usernames']) : 'unknown';

      // Log comprehensive error details.
      $this->logger->error('Allocations API POST request failed. URL: @url, Status: @status, Request body: @request, Response: @response, Exception: @exception', [
        '@url' => $url,
        '@status' => $status_code,
        '@request' => $body,
        '@response' => $response_body,
        '@exception' => $e->getMessage(),
      ]);

      // Parse response for better error messages.
      $api_error_message = '';
      if (!empty($response_body)) {
        $response_data = json_decode($response_body, TRUE);
        if (isset($response_data['message'])) {
          $api_error_message = $response_data['message'];
        }
        elseif (isset($response_data['error'])) {
          $api_error_message = $response_data['error'];
        }
      }

      // Show user-friendly error messages based on status code.
      if ($status_code == 400) {
        if (!empty($api_error_message)) {
          $this->messenger->addMessage(
            $this->t('Allocations API error: @message (User: @user)', [
              '@message' => $api_error_message,
              '@user' => $usernames,
            ]),
            'error'
          );
        }
        else {
          $this->messenger->addMessage(
            $this->t('Unable to add user @user to allocation. The user may already be on the project, the username may not exist, or the allocation may not accept new members.', [
              '@user' => $usernames,
            ]),
            'error'
          );
        }
      }
      elseif ($status_code == 403) {
        $this->messenger->addMessage(
          $this->t('Permission denied: Unable to modify this allocation. Please check API credentials.'),
          'error'
        );
      }
      elseif ($status_code == 404) {
        $this->messenger->addMessage(
          $this->t('Allocation not found. Please verify the grant number is correct.'),
          'error'
        );
      }
      elseif ($status_code >= 500) {
        $this->messenger->addMessage(
          $this->t('Allocations API server error. Please try again later or contact support.'),
          'error'
        );
      }
      else {
        // Generic error with status code.
        $this->messenger->addMessage(
          $this->t('Failed to add user @user to allocation (Status: @status). Please check the logs for details.', [
            '@user' => $usernames,
            '@status' => $status_code ?: 'unknown',
          ]),
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
