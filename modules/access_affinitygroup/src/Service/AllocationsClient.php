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
   * Returns the projects array for a user, or [] on any failure.
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
   * @return array<int, array<string, mixed>>
   */
  public function getProjectsForUser(string $username): array {
    $apiKey = $this->getApiKey();
    if (!$apiKey) {
      return [];
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
      return [];
    }
    $status = $response->getStatusCode();
    if ($status !== 200) {
      $this->loggerFactory->get('access_affinitygroup')
        ->warning('Identity API HTTP @status for user @u', [
          '@status' => $status, '@u' => $username,
        ]);
      return [];
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    return $data['projects'] ?? [];
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
