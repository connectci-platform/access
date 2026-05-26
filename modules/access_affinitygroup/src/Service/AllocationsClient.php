<?php

namespace Drupal\access_affinitygroup\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Wrapper for the identity profiles API at allocations-api.access-ci.org.
 */
class AllocationsClient {

  private const BASE = 'https://allocations-api.access-ci.org';

  public function __construct(
    private readonly ClientInterface $http,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the projects array for a user, or NULL on any failure.
   *
   * Each item is an associative array with the following keys (as
   * returned by /identity/profiles/v1/people/{username}?projects=1):
   * - grant_number  (string) e.g. "PHY250173"
   * - allocation_type (string) e.g. "Accelerate", "Explore"
   * - grant_type    (string) e.g. "Dissertation or Thesis"
   * - title         (string) free-form project title
   *
   * Additional keys may be present and should be passed through opaquely.
   *
   * @return array<int, array<string, mixed>>|null
   *   Returns the projects array on success (may be empty if the user has
   *   no grants). Returns NULL on any failure (missing API key, HTTP error,
   *   non-200 response, malformed JSON). Callers must distinguish empty
   *   success ([]) from failure (null) — they have different semantics.
   */
  public function getProjectsForUser(string $username): ?array {
    $apiKey = $this->getApiKey();
    if (!$apiKey) {
      return NULL;
    }
    $url = self::BASE . '/identity/profiles/v1/people/' . rawurlencode($username) . '?projects=1';
    try {
      $response = $this->http->request('GET', $url, [
        'headers' => [
          'XA-API-KEY' => $apiKey,
          'XA-REQUESTER' => 'MATCH',
          'Content-Type' => 'application/json',
        ],
        'http_errors' => FALSE,
        'timeout' => 8,
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('Identity API HTTP error for user @u: @msg', [
          '@u' => $username, '@msg' => $e->getMessage(),
        ]);
      return NULL;
    }
    $status = $response->getStatusCode();
    if ($status !== 200) {
      $this->loggerFactory->get('access_affinitygroup')
        ->warning('Identity API HTTP @status for user @u', [
          '@status' => $status, '@u' => $username,
        ]);
      return NULL;
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || !array_key_exists('projects', $data) || !is_array($data['projects'])) {
      return NULL;
    }
    return $data['projects'];
  }

  /**
   * Returns the user's resources array, or NULL on failure.
   *
   * GET /identity/profiles/v1/people/{username}?resources=1
   *
   * Each item is an associative array including these keys (we care about
   * the first three; pass others through opaquely):
   * - cider_resource_id    (string) e.g. "delta-cpu.ncsa.access-ci.org"
   * - billable_unit_type   (string) e.g. "Core-hours", "GPU-hours"
   * - resource_name        (string) xdusage's internal "*.xsede.org" form
   *
   * Returns NULL on transient failure (HTTP error, missing key, malformed
   * JSON), [] when the API succeeds but the user has no resources, or the
   * array of resources on success.
   *
   * @return array<int, array<string, mixed>>|null
   */
  public function getResourcesForUser(string $username): ?array {
    $apiKey = $this->getApiKey();
    if (!$apiKey) {
      return NULL;
    }
    $url = self::BASE . '/identity/profiles/v1/people/' . rawurlencode($username) . '?resources=1';
    try {
      $response = $this->http->request('GET', $url, [
        'headers' => [
          'XA-API-KEY' => $apiKey,
          'XA-REQUESTER' => 'MATCH',
          'Content-Type' => 'application/json',
        ],
        'http_errors' => FALSE,
        'timeout' => 8,
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('Identity API HTTP error (resources) for user @u: @msg', [
          '@u' => $username, '@msg' => $e->getMessage(),
        ]);
      return NULL;
    }
    $status = $response->getStatusCode();
    if ($status !== 200) {
      $this->loggerFactory->get('access_affinitygroup')
        ->warning('Identity API HTTP @status (resources) for user @u', [
          '@status' => $status, '@u' => $username,
        ]);
      return NULL;
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)
        || !array_key_exists('resources', $data)
        || !is_array($data['resources'])) {
      return NULL;
    }
    return $data['resources'];
  }

  /**
   * Returns the user's eligibility for ACCESS allocations, or NULL on failure.
   *
   * GET /identity/profiles/v1/people/{username}
   *
   * Inspects the `isEligible` and `eligibleReason` fields on the user's
   * profile. The reason text is the human-readable explanation of what's
   * missing (e.g. "Country of Residence is not set.") — display verbatim.
   *
   * @return array{eligible: bool, reason: string|null}|null
   *   Returns `['eligible' => TRUE, 'reason' => NULL]` for eligible users,
   *   `['eligible' => FALSE, 'reason' => '...']` for ineligible users, or
   *   NULL on transient failure (HTTP error, missing key, malformed JSON,
   *   non-200 response). Callers must distinguish NULL (unknown) from
   *   FALSE (known ineligible). An empty-string `eligibleReason` on an
   *   ineligible user is normalized to NULL — callers can test `reason
   *   !== NULL` to decide whether to display a reason message.
   */
  public function getEligibilityForUser(string $username): ?array {
    $apiKey = $this->getApiKey();
    if (!$apiKey) {
      return NULL;
    }
    $url = self::BASE . '/identity/profiles/v1/people/' . rawurlencode($username);
    try {
      $response = $this->http->request('GET', $url, [
        'headers' => [
          'XA-API-KEY' => $apiKey,
          'XA-REQUESTER' => 'MATCH',
          'Content-Type' => 'application/json',
        ],
        'http_errors' => FALSE,
        'timeout' => 8,
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('Identity API HTTP error for user @u (eligibility): @msg', [
          '@u' => $username, '@msg' => $e->getMessage(),
        ]);
      return NULL;
    }
    $status = $response->getStatusCode();
    if ($status !== 200) {
      $this->loggerFactory->get('access_affinitygroup')
        ->warning('Identity API HTTP @status for user @u (eligibility)', [
          '@status' => $status, '@u' => $username,
        ]);
      return NULL;
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || !array_key_exists('isEligible', $data)) {
      return NULL;
    }
    $eligible = ($data['isEligible'] === 'yes');
    $reason = $eligible ? NULL : (string) ($data['eligibleReason'] ?? '');
    return ['eligible' => $eligible, 'reason' => $reason ?: NULL];
  }

  private function getApiKey(): ?string {
    $base = $this->fileSystem->realpath('private://');
    if ($base === FALSE) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('private:// stream not available for secrets lookup.');
      return NULL;
    }
    $path = $base . '/.keys/secrets.json';
    if (!is_readable($path)) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('secrets.json not readable at @p', ['@p' => $path]);
      return NULL;
    }
    $secrets = json_decode((string) file_get_contents($path), TRUE);
    if (!is_array($secrets)) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('secrets.json malformed at @p', ['@p' => $path]);
      return NULL;
    }
    return $secrets['ramps_api_key'] ?? NULL;
  }

}
