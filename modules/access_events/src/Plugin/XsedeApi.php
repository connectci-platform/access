<?php

namespace Drupal\access_events\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Notify people by roles.
 */
class XsedeApi {

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
      watchdog_exception('access_events', $e);
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
      $request = \Drupal::httpClient()->post($url, [
        'verify' => true,
        'headers' => [
          'XA-RESOURCE' => $headers[0],
          'XA-AGENT' => $headers[1],
          'XA-API-KEY' => $headers[2],
        ],
      ]);
      $request->setBody($body);
      $response = $request->send();

    }
    catch (RequestException $e) {
      watchdog_exception('access_events', $e);
    }

    kint(json_decode($response));
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
   * Make Api call to pull in user list for a given grant.
   */
  public function getGrantedUsers($grant) {
    $this->apiCall('/xdcdb-api-test/usermanagement/v1/users/' . $grant);

    return $this->apiResults;
  }

  /**
   * Make Api post to update user list — send back full list from above
   * (getGrantUsers)) plus new user.
   */
  public function setGrantedUsers($grantNumber, $post) {
    $path = '/xdcdb-api-test/usermanagement/v1/users/' . $grantNumber;
    $this->apiPost($path, $post);;
  }

}
