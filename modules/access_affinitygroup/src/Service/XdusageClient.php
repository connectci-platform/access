<?php

namespace Drupal\access_affinitygroup\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

/**
 * Thin wrapper around xdusage v2 endpoints at allocations-api.access-ci.org.
 */
class XdusageClient {

  private const BASE = 'https://allocations-api.access-ci.org';
  private const RESOURCE = 'support.access-ci.org';
  private const AGENT = 'xdusage';
  private const PROJECTS_CACHE_KEY = 'xdusage:projects_map';
  private const PROJECTS_CACHE_TTL = 86400;

  public function __construct(
    private readonly ClientInterface $http,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function getPersonByPortalUsername(string $username): ?array {
    return $this->lookupPerson($username)['person'];
  }

  /**
   * Look up an ACCESS person by portal username, discriminating the outcome.
   *
   * getPersonByPortalUsername() collapses "no such person" and "the lookup
   * failed" into a single NULL. Callers that must tell them apart — e.g. to
   * negative-cache a confirmed-absent user without poisoning the cache during
   * an ACCESS API outage — use this instead.
   *
   * @return array{status: 'found'|'absent'|'error', person: ?array}
   *   - 'found':  a person record was returned (in 'person').
   *   - 'absent': the API responded successfully but with no matching person
   *               (authoritative "not an ACCESS user" — safe to negative-cache).
   *   - 'error':  the request failed (connection/timeout/>=400 or missing key);
   *               the true membership is UNKNOWN and must NOT be cached.
   */
  public function lookupPerson(string $username): array {
    $url = self::BASE . '/acdb/xdusage/v2/people/by_portal_username/' . rawurlencode($username);
    $data = $this->callJson('GET', $url);
    // callJson returns NULL only on a transport/HTTP failure; a successful call
    // with no matches decodes to an array with an empty 'result'.
    if ($data === NULL) {
      return ['status' => 'error', 'person' => NULL];
    }
    $result = $data['result'] ?? [];
    if (!$result) {
      return ['status' => 'absent', 'person' => NULL];
    }
    return ['status' => 'found', 'person' => reset($result)];
  }

  public function getProjectsMap(): array {
    $cached = $this->cache->get(self::PROJECTS_CACHE_KEY);
    if ($cached) {
      return $cached->data;
    }

    $url = self::BASE . '/acdb/xdusage/v2/projects';
    $data = $this->callJson('GET', $url);
    if ($data === NULL) {
      // Don't cache an empty map on upstream failure — would poison cache for 24h.
      return [];
    }
    $rows = $data['result'] ?? [];

    $map = [];
    foreach ($rows as $r) {
      $grant = $r['grant_number'] ?? NULL;
      $iri = $r['info_resource_id'] ?? NULL;
      if (!$grant || !$iri) {
        continue;
      }
      $map[$grant][$iri] = [
        'project_id' => (int) $r['project_id'],
        'resource_id' => (int) $r['resource_id'],
        'project_balance' => $r['project_balance'] ?? NULL,
        'project_end' => $r['project_end'] ?? NULL,
        'project_state' => $r['project_state'] ?? NULL,
        'is_expired' => !empty($r['is_expired']),
        'billable_unit' => $r['billable_unit_type'] ?? NULL,
      ];
    }

    $this->cache->set(self::PROJECTS_CACHE_KEY, $map, time() + self::PROJECTS_CACHE_TTL);
    return $map;
  }

  public function getAccountForUser(int $project_id, int $resource_id, int $person_id): ?array {
    $url = sprintf(
      '%s/acdb/xdusage/v2/accounts/%d/%d?person_id=%d',
      self::BASE,
      $project_id,
      $resource_id,
      $person_id
    );
    $data = $this->callJson('GET', $url);
    if ($data === NULL) {
      return NULL;
    }
    $rows = $data['result'] ?? [];
    foreach ($rows as $row) {
      if ((int) ($row['person_id'] ?? 0) === $person_id) {
        return $row;
      }
    }
    return NULL;
  }

  public function getLiveBalance(int $project_id, int $resource_id, ?int $person_id = NULL): ?array {
    $url = sprintf(
      '%s/acdb/xdusage/v2/accounts/%d/%d',
      self::BASE,
      $project_id,
      $resource_id
    );
    if ($person_id !== NULL) {
      $url .= '?person_id=' . $person_id;
    }
    $data = $this->callJson('GET', $url);
    if ($data === NULL) {
      return NULL;
    }
    $rows = $data['result'] ?? [];
    if ($person_id !== NULL) {
      foreach ($rows as $row) {
        if ((int) ($row['person_id'] ?? 0) === $person_id) {
          return $this->extractBalance($row);
        }
      }
      // Caller asked for a specific person; don't fall back to another row.
      return NULL;
    }
    $first = $rows ? reset($rows) : NULL;
    return $first ? $this->extractBalance($first) : NULL;
  }

  /**
   * Bounded parallel batch fetch of (project_id, resource_id) accounts.
   *
   * @param array $rows
   *   Each item must contain integer 'project_id' and 'resource_id'.
   * @param int|null $person_id
   *   If given, requests are filtered to that user (per-user balance).
   * @param int $concurrency
   * @param float $perCallTimeout
   * @param float $totalTimeout
   *
   * @return array
   *   Keyed map "{project_id}:{resource_id}" => {project_balance, account_charges?, billable_unit}.
   */
  public function getLiveBalanceBatch(
    array $rows,
    ?int $person_id,
    int $concurrency = 4,
    float $perCallTimeout = 4.0,
    float $totalTimeout = 8.0
  ): array {
    if (!$rows) {
      return [];
    }
    $key = $this->keyRepository->getKey('xdusage_api');
    if (!$key) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('xdusage_api key missing from key repository.');
      return [];
    }
    $headers = [
      'XA-RESOURCE' => self::RESOURCE,
      'XA-AGENT' => self::AGENT,
      'XA-API-KEY' => $key->getKeyValue(),
    ];

    $rowKeys = array_map(
      fn ($r) => $r['project_id'] . ':' . $r['resource_id'],
      $rows
    );

    $requests = function () use ($rows, $person_id, $headers) {
      foreach ($rows as $row) {
        $url = sprintf(
          '%s/acdb/xdusage/v2/accounts/%d/%d',
          self::BASE,
          (int) $row['project_id'],
          (int) $row['resource_id']
        );
        if ($person_id !== NULL) {
          $url .= '?person_id=' . $person_id;
        }
        yield new Request('GET', $url, $headers);
      }
    };

    $results = [];
    $start = microtime(TRUE);
    $logger = $this->loggerFactory->get('access_affinitygroup');

    $pool = new Pool($this->http, $requests(), [
      'concurrency' => $concurrency,
      'options' => [
        'timeout' => $perCallTimeout,
        'http_errors' => FALSE,
      ],
      'fulfilled' => function ($response, $index) use (&$results, $rowKeys, $person_id, $start, $totalTimeout) {
        if ((microtime(TRUE) - $start) > $totalTimeout) {
          return;
        }
        $body = (string) $response->getBody();
        $data = json_decode($body, TRUE);
        $apiRows = $data['result'] ?? [];
        $matched = NULL;
        if ($person_id !== NULL) {
          foreach ($apiRows as $r) {
            if ((int) ($r['person_id'] ?? 0) === $person_id) {
              $matched = $r;
              break;
            }
          }
          // Caller asked for a specific person; don't fall back to another row.
        }
        else {
          // No person_id requested — return project-level row (first/only row).
          $matched = $apiRows ? reset($apiRows) : NULL;
        }
        if ($matched) {
          $results[$rowKeys[$index]] = $this->extractBalance($matched);
        }
      },
      'rejected' => function ($_reason, $index) use ($rowKeys, $logger) {
        $logger->warning('xdusage live balance failed for tuple @k', ['@k' => $rowKeys[$index]]);
      },
    ]);
    $pool->promise()->wait();
    return $results;
  }

  /**
   * Make a JSON GET request with the standard headers.
   */
  private function callJson(string $method, string $url): ?array {
    $key = $this->keyRepository->getKey('xdusage_api');
    if (!$key) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('xdusage_api key missing from key repository.');
      return NULL;
    }
    try {
      $response = $this->http->request($method, $url, [
        'headers' => [
          'XA-RESOURCE' => self::RESOURCE,
          'XA-AGENT' => self::AGENT,
          'XA-API-KEY' => $key->getKeyValue(),
        ],
        'http_errors' => FALSE,
        'timeout' => 8,
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('access_affinitygroup')
        ->error('xdusage HTTP error for @url: @msg', [
          '@url' => $url, '@msg' => $e->getMessage(),
        ]);
      return NULL;
    }
    $status = $response->getStatusCode();
    if ($status >= 400) {
      $this->loggerFactory->get('access_affinitygroup')
        ->warning('xdusage HTTP @status for @url', ['@status' => $status, '@url' => $url]);
      return NULL;
    }
    return json_decode((string) $response->getBody(), TRUE);
  }

  private function extractBalance(array $row): array {
    return [
      'project_balance' => $row['project_balance'] ?? NULL,
      'account_charges' => $row['account_charges'] ?? NULL,
      'billable_unit' => $row['billable_unit_type'] ?? NULL,
    ];
  }
}
