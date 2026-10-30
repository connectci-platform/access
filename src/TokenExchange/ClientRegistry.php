<?php

namespace Drupal\access\TokenExchange;

use Drupal\access\AccessJwtKeyProvider;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;

/**
 * The registry of clients that may obtain access tokens from this site.
 *
 * One registry, keyed by client id, in the access.token_exchange config:
 *
 * - browser clients get tokens from /api/agent-token. They are identified by
 *   the page's Origin, and each registered origin carries its own scopes.
 * - confidential clients authenticate to /api/token-exchange with a secret,
 *   and exchange a browser client's token for one with their own audience.
 *
 * Every client is validated on load, and a client that breaks a rule is
 * unusable (and logged) rather than partly honored. The rules keep the token
 * types disjoint: browser clients hold only agent:* scopes, confidential
 * clients hold none, and no confidential client may mint a browser client's
 * audience, so an agent token can never be exchanged for more agent tokens.
 *
 * Origins are https://host only. An audience may also be
 * http://localhost:<port>, for a developer's local agent: the token reaches
 * the agent as a Bearer header, so the audience is a label, not an origin.
 *
 * Live's registry is this config. Other environments set
 * $settings['access_token_clients'] in settings.php, which is used instead of
 * the config, whole: a live database cloned to dev carries live's stored
 * registry, and a $config override would merge into it entry by entry and
 * leave live's other origins registered. For the same reason a $config
 * override can add but never remove a client, origin or scope; narrow live by
 * changing the config itself.
 *
 * Secrets are never in config. A confidential client's secret is stored as a
 * SHA-256 hex digest, in Pantheon Secrets or secrets.json as
 * access_token_exchange_secret_sha256_<id> or in the env var
 * ACCESS_TOKEN_EXCHANGE_SECRET_SHA256_<ID>. In both names - is written as _,
 * since Pantheon secret names allow only letters, digits and underscores.
 * Ids can't contain _, so no two clients share a name.
 */
class ClientRegistry {

  const TYPE_BROWSER = 'browser';
  const TYPE_CONFIDENTIAL = 'confidential';

  const KNOWN_SCOPES = ['agent:read', 'agent:write', 'xdmod:read'];

  /**
   * Valid clients keyed by id, built on first use.
   *
   * @var array<string, array<string, mixed>>|null
   */
  protected ?array $clients = NULL;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AccessJwtKeyProvider $keyProvider,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns a valid client.
   *
   * @param string $id
   *   The client id.
   *
   * @return array<string, mixed>|null
   *   The client: id, type, audience, and for browser clients origins
   *   (origin => scopes), or for confidential clients scopes and
   *   subject_clients. NULL if unknown or invalid.
   */
  public function getClient(string $id): ?array {
    return $this->clients()[$id] ?? NULL;
  }

  /**
   * Finds the browser client registered for an exact Origin value.
   *
   * @param string $origin
   *   The request's Origin header.
   *
   * @return array{client_id: string, audience: string, scopes: string[]}|null
   *   The match, or NULL if the origin is not registered.
   */
  public function findBrowserOrigin(string $origin): ?array {
    foreach ($this->clients() as $id => $client) {
      if ($client['type'] === self::TYPE_BROWSER && isset($client['origins'][$origin])) {
        return [
          'client_id' => $id,
          'audience' => $client['audience'],
          'scopes' => $client['origins'][$origin],
        ];
      }
    }
    return NULL;
  }

  /**
   * Checks a confidential client's secret against its stored hash.
   *
   * Registry membership is checked before any secret lookup, so an unknown
   * id never reaches the secrets store.
   */
  public function verifySecret(string $id, string $secret): bool {
    $client = $this->getClient($id);
    if ($client === NULL || $client['type'] !== self::TYPE_CONFIDENTIAL || $secret === '') {
      return FALSE;
    }
    $name = str_replace('-', '_', $id);
    $stored = $this->keyProvider->getConfiguredSecret(
      'access_token_exchange_secret_sha256_' . $name,
      'ACCESS_TOKEN_EXCHANGE_SECRET_SHA256_' . strtoupper($name)
    );
    if ($stored === NULL || !preg_match('/^[0-9a-f]{64}$/i', $stored)) {
      $this->logger->error('Token client @id has no valid secret hash configured.', ['@id' => $id]);
      return FALSE;
    }
    return hash_equals(strtolower($stored), hash('sha256', $secret));
  }

  /**
   * Loads and validates the registry.
   *
   * @return array<string, array<string, mixed>>
   *   Valid clients keyed by id.
   */
  protected function clients(): array {
    if ($this->clients !== NULL) {
      return $this->clients;
    }

    $raw = Settings::get('access_token_clients') ?? $this->configFactory->get('access.token_exchange')->get('clients');
    $valid = [];
    foreach (is_array($raw) ? $raw : [] as $id => $client) {
      $error = $this->validateClient((string) $id, $client);
      if ($error !== NULL) {
        $this->reject((string) $id, $error);
        continue;
      }
      $valid[$id] = $this->normalize((string) $id, $client);
    }

    // An origin under two clients is ambiguous, so neither client gets it.
    $owners = [];
    foreach ($valid as $id => $client) {
      foreach (array_keys($client['origins'] ?? []) as $origin) {
        $owners[$origin][] = $id;
      }
    }
    foreach ($owners as $origin => $ids) {
      if (count($ids) > 1) {
        foreach ($ids as $id) {
          unset($valid[$id]);
          $this->reject($id, "origin $origin is registered under more than one client");
        }
      }
    }

    // Confidential clients are checked against the surviving browser clients.
    $browser_audiences = [];
    foreach ($valid as $client) {
      if ($client['type'] === self::TYPE_BROWSER) {
        $browser_audiences[] = $client['audience'];
      }
    }
    foreach ($valid as $id => $client) {
      if ($client['type'] !== self::TYPE_CONFIDENTIAL) {
        continue;
      }
      $error = NULL;
      if (in_array($client['audience'], $browser_audiences, TRUE)) {
        $error = 'its audience belongs to a browser client';
      }
      foreach ($client['subject_clients'] as $subject) {
        if (($valid[$subject]['type'] ?? NULL) !== self::TYPE_BROWSER) {
          $error = "subject client $subject is not a valid browser client";
        }
      }
      if ($error !== NULL) {
        unset($valid[$id]);
        $this->reject($id, $error);
      }
    }

    return $this->clients = $valid;
  }

  /**
   * Checks one client's own fields.
   *
   * @return string|null
   *   What is wrong, or NULL if the client is valid on its own.
   */
  protected function validateClient(string $id, mixed $client): ?string {
    if (!preg_match('/^[a-z0-9-]+$/', $id)) {
      return 'the id must match ^[a-z0-9-]+$';
    }
    if (!is_array($client)) {
      return 'the entry is not a mapping';
    }
    $type = $client['type'] ?? NULL;
    if (!in_array($type, [self::TYPE_BROWSER, self::TYPE_CONFIDENTIAL], TRUE)) {
      return 'type must be browser or confidential';
    }
    $audience = $client['audience'] ?? NULL;
    if (!is_string($audience) || !preg_match('#^(https://[^\s/]+(/\S*)?|http://localhost:[1-9][0-9]{0,4})$#', $audience)) {
      return 'it must have exactly one https (or http://localhost) audience';
    }

    if ($type === self::TYPE_BROWSER) {
      $origins = $client['origins'] ?? NULL;
      if (!is_array($origins) || $origins === []) {
        return 'a browser client needs at least one origin';
      }
      $seen = [];
      foreach ($origins as $entry) {
        $origin = is_array($entry) ? ($entry['origin'] ?? NULL) : NULL;
        if (!is_string($origin) || !$this->isOrigin($origin)) {
          return 'origins must be https://host with no path, port or wildcard';
        }
        if (isset($seen[$origin])) {
          return "origin $origin is listed twice";
        }
        $seen[$origin] = TRUE;
        $error = $this->validateScopes($entry['scopes'] ?? NULL, TRUE);
        if ($error !== NULL) {
          return $error;
        }
      }
      return NULL;
    }

    $error = $this->validateScopes($client['scopes'] ?? NULL, FALSE);
    if ($error !== NULL) {
      return $error;
    }
    $subjects = $client['subject_clients'] ?? NULL;
    if (!is_array($subjects) || $subjects === [] || array_filter($subjects, fn($s) => !is_string($s))) {
      return 'a confidential client needs subject_clients';
    }
    return NULL;
  }

  /**
   * Checks a scope list for a browser (agent:* only) or confidential client.
   */
  protected function validateScopes(mixed $scopes, bool $browser): ?string {
    if (!is_array($scopes) || $scopes === []) {
      return 'scopes must be a non-empty list';
    }
    foreach ($scopes as $scope) {
      if (!in_array($scope, self::KNOWN_SCOPES, TRUE)) {
        return 'unknown scope ' . (is_scalar($scope) ? $scope : gettype($scope));
      }
      if (str_starts_with($scope, 'agent:') !== $browser) {
        return $browser
          ? "browser clients may hold only agent:* scopes, not $scope"
          : "confidential clients may not hold $scope";
      }
    }
    return NULL;
  }

  /**
   * Whether a value is exactly https://host, with a lowercase DNS host.
   */
  protected function isOrigin(string $origin): bool {
    return (bool) preg_match('#^https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$#', $origin);
  }

  /**
   * Reduces a validated client to the fields the endpoints use.
   *
   * @param string $id
   *   The client id.
   * @param array<string, mixed> $client
   *   The validated config entry.
   *
   * @return array<string, mixed>
   *   The normalized client.
   */
  protected function normalize(string $id, array $client): array {
    $out = [
      'id' => $id,
      'type' => $client['type'],
      'audience' => $client['audience'],
    ];
    if ($client['type'] === self::TYPE_BROWSER) {
      foreach ($client['origins'] as $entry) {
        $out['origins'][$entry['origin']] = array_values(array_unique($entry['scopes']));
      }
    }
    else {
      $out['scopes'] = array_values(array_unique($client['scopes']));
      $out['subject_clients'] = array_values($client['subject_clients']);
    }
    return $out;
  }

  /**
   * Logs a rejected client.
   */
  protected function reject(string $id, string $reason): void {
    $this->logger->error('Token client @id is not usable: @reason.', [
      '@id' => $id,
      '@reason' => $reason,
    ]);
  }

}
