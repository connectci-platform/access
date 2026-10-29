<?php

namespace Drupal\access;

/**
 * Provides the EC P-256 signing keys and derived kids for JWT operations.
 *
 * Shared by AccessAuthCookieSubscriber, the token endpoints (signing) and
 * JwksController (public key publishing) so key loading lives in one place.
 *
 * There is one key per purpose, each with its own kid:
 *   - cookie: signs SESSaccess_auth. Published in /.well-known/jwks.json.
 *   - agent: signs agent tokens (/api/agent-token).
 *   - xdmod: signs XDMoD tokens (/api/token-exchange).
 * The agent and xdmod keys are published in /.well-known/exchange-jwks.json
 * and the cookie key in /.well-known/jwks.json, so each document holds one
 * kind of key and a verifier of one kind never sees the other's keys.
 *
 * Each private key is loaded in order from the names in KEY_SOURCES:
 *   1. Pantheon secrets.json in sites/default/files/private/.keys/
 *   2. An env var holding the PEM string (DDEV/local)
 *   3. An env var holding a path to a PEM file
 *
 * The agent and xdmod keys, the explicit issuer, the verify-only key lists
 * and the client secret hashes are first read from Pantheon Secrets
 * (pantheon_get_secret(), type runtime, scope web) under the same names. A
 * Pantheon secret is authoritative when it exists: its value PANTHEON_UNSET
 * means "not configured", and secrets.json is then not consulted. Pantheon
 * cannot hold a secret for one environment only, so each secret's
 * all-environments value is PANTHEON_UNSET and an environment that should
 * mint gets its real value as an environment override. Every other
 * environment, including a new multidev cloned from live's files, then loads
 * no signing key at all. The cookie key is not read from Pantheon Secrets.
 */
class AccessJwtKeyProvider {

  /**
   * Default issuer for JWTs signed by this site.
   *
   * Override via ACCESS_JWT_ISSUER env var for staging/DDEV.
   */
  const JWT_ISSUER_DEFAULT = 'https://support.access-ci.org';

  const PURPOSE_COOKIE = 'cookie';
  const PURPOSE_AGENT = 'agent';
  const PURPOSE_XDMOD = 'xdmod';

  /**
   * Where each purpose's private key is read from.
   *
   * Values: [secrets.json key, PEM env var, PEM file path env var].
   */
  const KEY_SOURCES = [
    self::PURPOSE_COOKIE => [
      'access_jwt_private_key',
      'ACCESS_JWT_PRIVATE_KEY',
      'ACCESS_JWT_PRIVATE_KEY_FILE',
    ],
    self::PURPOSE_AGENT => [
      'access_jwt_agent_private_key',
      'ACCESS_JWT_AGENT_PRIVATE_KEY',
      'ACCESS_JWT_AGENT_PRIVATE_KEY_FILE',
    ],
    self::PURPOSE_XDMOD => [
      'access_jwt_exchange_private_key',
      'ACCESS_JWT_EXCHANGE_PRIVATE_KEY',
      'ACCESS_JWT_EXCHANGE_PRIVATE_KEY_FILE',
    ],
  ];

  const DOCUMENT_COOKIE = 'cookie';
  const DOCUMENT_EXCHANGE = 'exchange';

  /**
   * The purposes whose keys each JWKS document publishes.
   */
  const DOCUMENT_PURPOSES = [
    self::DOCUMENT_COOKIE => [self::PURPOSE_COOKIE],
    self::DOCUMENT_EXCHANGE => [self::PURPOSE_AGENT, self::PURPOSE_XDMOD],
  ];

  /**
   * The secrets.json key listing each document's verify-only public keys.
   *
   * Used for rotation: a new key is published here before it signs, and an
   * old one stays here after it stops, so relying parties never see a kid
   * they cannot verify.
   */
  const VERIFY_ONLY_SECRETS = [
    self::DOCUMENT_COOKIE => 'access_jwt_cookie_jwks_extra_public_keys',
    self::DOCUMENT_EXCHANGE => 'access_jwt_exchange_jwks_extra_public_keys',
  ];

  /**
   * The Pantheon Secrets value meaning "not configured here".
   */
  const PANTHEON_UNSET = 'none';

  const STATUS_NONE = 'none';
  const STATUS_LOADED = 'loaded';
  const STATUS_BROKEN = 'broken';

  /**
   * Loaded non-cookie slots, keyed by purpose.
   *
   * Per instance rather than static: the cookie key keeps its static cache
   * below, but these are only read on the token and JWKS routes.
   *
   * @var array<string, array{status: string, key: ?\OpenSSLAsymmetricKey}>
   */
  protected array $slots = [];

  /**
   * Cached private key (NULL = not loaded, FALSE = load failed).
   *
   * @var \OpenSSLAsymmetricKey|null|false
   */
  protected static $privateKey = NULL;

  /**
   * Whether the key has been loaded (to distinguish NULL from "not tried").
   *
   * @var bool
   */
  protected static bool $loaded = FALSE;

  /**
   * Returns the JWT issuer (iss) for this site.
   *
   * @return string
   *   The issuer URL.
   */
  public function getIssuer(): string {
    $issuer = $this->getSecretFromFile('access_jwt_issuer');
    if (!empty($issuer)) {
      return $issuer;
    }
    $issuer = getenv('ACCESS_JWT_ISSUER');
    return !empty($issuer) ? $issuer : self::JWT_ISSUER_DEFAULT;
  }

  /**
   * Returns the issuer only when it is set explicitly, never the default.
   *
   * The agent and xdmod keys sign only under an explicit issuer, so an
   * environment holding a key but no issuer cannot mint tokens that claim to
   * come from production.
   *
   * @return string|null
   *   The issuer URL, or NULL if none is configured.
   */
  public function getExplicitIssuer(): ?string {
    $pantheon = $this->getPantheonSecret('access_jwt_issuer');
    if ($pantheon !== NULL) {
      return $pantheon === self::PANTHEON_UNSET || $pantheon === '' ? NULL : $pantheon;
    }
    $issuer = $this->getSecretFromFile('access_jwt_issuer');
    if (is_string($issuer) && $issuer !== '') {
      return $issuer;
    }
    return $this->getEnv('ACCESS_JWT_ISSUER');
  }

  /**
   * Gets the EC private key for JWT signing.
   *
   * Uses static caching so the key is loaded at most once per PHP process.
   *
   * @return \OpenSSLAsymmetricKey|null
   *   The private key, or NULL if not configured or invalid.
   */
  public function getPrivateKey() {
    if (self::$loaded) {
      return self::$privateKey ?: NULL;
    }
    self::$loaded = TRUE;

    // Try Pantheon secrets.json first (same pattern as Turnstile keys).
    $pem = $this->getSecretFromFile('access_jwt_private_key');
    if (!empty($pem)) {
      $key = openssl_pkey_get_private($pem);
      if ($key === FALSE) {
        \Drupal::logger('access')->warning(
          'access_jwt_private_key in secrets.json is not a valid EC private key.'
        );
        self::$privateKey = FALSE;
        return NULL;
      }
      self::$privateKey = $key;
      return $key;
    }

    // Try PEM string from env var (DDEV / local dev).
    $pem = getenv('ACCESS_JWT_PRIVATE_KEY');
    if (!empty($pem)) {
      $key = openssl_pkey_get_private($pem);
      if ($key === FALSE) {
        \Drupal::logger('access')->warning(
          'ACCESS_JWT_PRIVATE_KEY is set but is not a valid EC private key.'
        );
        self::$privateKey = FALSE;
        return NULL;
      }
      self::$privateKey = $key;
      return $key;
    }

    // Try file path from env var.
    $path = getenv('ACCESS_JWT_PRIVATE_KEY_FILE');
    if (!empty($path) && file_exists($path)) {
      $pem = file_get_contents($path);
      $key = openssl_pkey_get_private($pem);
      if ($key === FALSE) {
        \Drupal::logger('access')->warning(
          'ACCESS_JWT_PRIVATE_KEY_FILE (@path) is not a valid EC private key.', [
            '@path' => $path,
          ]
        );
        self::$privateKey = FALSE;
        return NULL;
      }
      self::$privateKey = $key;
      return $key;
    }

    \Drupal::logger('access')->warning(
      'JWT signing key not found. Set ACCESS_JWT_PRIVATE_KEY (PEM string) or ACCESS_JWT_PRIVATE_KEY_FILE (path).'
    );
    self::$privateKey = FALSE;
    return NULL;
  }

  /**
   * Reads a named secret from the Pantheon private secrets file.
   *
   * Mirrors the _get_turnstile_secret() pattern in settings.php.
   *
   * @param string $name
   *   The key name in secrets.json.
   *
   * @return mixed
   *   The secret value (usually a string), or NULL if not found.
   */
  protected function getSecretFromFile(string $name): mixed {
    static $secrets = NULL;

    if ($secrets === NULL) {
      $secrets = [];
      // Use Drupal's private file path setting (same location as Turnstile secrets).
      $private_path = \Drupal::service('file_system')->realpath('private://');
      if ($private_path) {
        $file = $private_path . '/.keys/secrets.json';
        if (file_exists($file)) {
          $raw = file_get_contents($file);
          $secrets = json_decode($raw, TRUE) ?: [];
        }
      }
    }

    return $secrets[$name] ?? NULL;
  }

  /**
   * Derives a Key ID (kid) from the private key's public component.
   *
   * Uses the SHA-256 thumbprint of the public key PEM, truncated to 16 hex
   * chars. The same kid appears in JWT headers and in the JWKS endpoint so
   * the agent can match tokens to verification keys.
   *
   * @param \OpenSSLAsymmetricKey|null $private_key
   *   The private key. If NULL, attempts to load it.
   *
   * @return string|null
   *   The key ID, or NULL if key details cannot be extracted.
   */
  public function getKeyId($private_key = NULL): ?string {
    $private_key = $private_key ?? $this->getPrivateKey();
    if ($private_key === NULL) {
      return NULL;
    }

    $kid = $this->thumbprint($private_key);
    if ($kid === NULL) {
      \Drupal::logger('access')->warning('Failed to extract public key details for kid derivation.');
    }
    return $kid;
  }

  /**
   * The kid for a key: the first 16 hex chars of its public PEM's SHA-256.
   *
   * A private key and its public half give the same value, so a key published
   * verify-only ahead of a rotation keeps its kid once it starts signing.
   *
   * @param \OpenSSLAsymmetricKey $key
   *   A private or public key.
   *
   * @return string|null
   *   The kid, or NULL if the key details cannot be read.
   */
  public function thumbprint($key): ?string {
    $details = openssl_pkey_get_details($key);
    if ($details === FALSE || empty($details['key'])) {
      return NULL;
    }
    return substr(hash('sha256', $details['key']), 0, 16);
  }

  /**
   * Reports whether a purpose's key is not configured, loaded or broken.
   *
   * Broken means configured but unusable: unparseable, not EC P-256, or (for
   * the agent and xdmod slots) the same key as another slot. JwksController
   * answers 503 for a document with a broken key rather than serve a subset.
   *
   * @param string $purpose
   *   One of the PURPOSE_* constants.
   *
   * @return string
   *   One of the STATUS_* constants.
   */
  public function getKeyStatus(string $purpose): string {
    if ($purpose === self::PURPOSE_COOKIE) {
      $key = $this->getPrivateKey();
      if ($key === NULL) {
        return $this->readPem(self::PURPOSE_COOKIE) === NULL ? self::STATUS_NONE : self::STATUS_BROKEN;
      }
      return $this->isP256($key) ? self::STATUS_LOADED : self::STATUS_BROKEN;
    }
    return $this->loadSlot($purpose)['status'];
  }

  /**
   * The kid of a loaded agent or xdmod key.
   *
   * @param string $purpose
   *   PURPOSE_AGENT or PURPOSE_XDMOD.
   *
   * @return string|null
   *   The kid, or NULL if the slot is not loaded.
   */
  public function getSlotKeyId(string $purpose): ?string {
    $slot = $this->loadSlot($purpose);
    return $slot['status'] === self::STATUS_LOADED ? $this->thumbprint($slot['key']) : NULL;
  }

  /**
   * The public key of a loaded slot, for building a JWK or verifying.
   *
   * @param string $purpose
   *   One of the PURPOSE_* constants.
   *
   * @return \OpenSSLAsymmetricKey|null
   *   The public key, or NULL if the slot is not loaded.
   */
  public function getPublicKey(string $purpose) {
    if ($this->getKeyStatus($purpose) !== self::STATUS_LOADED) {
      return NULL;
    }
    $key = $purpose === self::PURPOSE_COOKIE ? $this->getPrivateKey() : $this->loadSlot($purpose)['key'];
    $details = openssl_pkey_get_details($key);
    return $details ? (openssl_pkey_get_public($details['key']) ?: NULL) : NULL;
  }

  /**
   * Returns what a token endpoint needs to sign, or NULL if it must not.
   *
   * Signing needs a loaded agent or xdmod key and an explicit issuer whose
   * host is the host serving this request. The host check is what keeps a
   * Pantheon multidev, which is cloned with dev's secrets.json, from minting
   * tokens that claim to come from dev; it equally stops a lower environment
   * that has been given live's secrets from signing as live.
   *
   * @param string $purpose
   *   PURPOSE_AGENT or PURPOSE_XDMOD. The cookie key never signs here.
   * @param string $host
   *   The current request's host.
   *
   * @return array{key: \OpenSSLAsymmetricKey, kid: string, iss: string}|null
   *   The signing key, its kid and the issuer, or NULL.
   */
  public function getSigningKey(string $purpose, string $host): ?array {
    if (!in_array($purpose, [self::PURPOSE_AGENT, self::PURPOSE_XDMOD], TRUE)) {
      return NULL;
    }
    $slot = $this->loadSlot($purpose);
    if ($slot['status'] !== self::STATUS_LOADED) {
      \Drupal::logger('access')->error('Token signing unavailable: the @purpose key is @status.', [
        '@purpose' => $purpose,
        '@status' => $slot['status'] === self::STATUS_NONE ? 'not configured' : 'unusable',
      ]);
      return NULL;
    }
    $issuer = $this->getExplicitIssuer();
    if ($issuer === NULL) {
      \Drupal::logger('access')->error('Token signing unavailable: no explicit issuer is configured (access_jwt_issuer or ACCESS_JWT_ISSUER).');
      return NULL;
    }
    $issuer_host = parse_url($issuer, PHP_URL_HOST);
    if (!is_string($issuer_host) || strcasecmp($issuer_host, $host) !== 0) {
      \Drupal::logger('access')->error('Token signing unavailable: issuer host @issuer_host does not match request host @host.', [
        '@issuer_host' => (string) $issuer_host,
        '@host' => $host,
      ]);
      return NULL;
    }
    return [
      'key' => $slot['key'],
      'kid' => $this->thumbprint($slot['key']),
      'iss' => $issuer,
    ];
  }

  /**
   * Returns a JWKS document's verify-only public keys.
   *
   * The secrets.json value is a list of public key PEMs. Anything else, or
   * any entry that is not an EC P-256 public key, makes the list broken.
   *
   * @param string $document
   *   One of the DOCUMENT_* constants.
   *
   * @return array{status: string, keys: \OpenSSLAsymmetricKey[]}
   *   The list's status and its keys.
   */
  public function getVerifyOnlyKeys(string $document): array {
    $name = self::VERIFY_ONLY_SECRETS[$document];
    $pantheon = $this->getPantheonSecret($name);
    if ($pantheon === NULL) {
      $value = $this->getSecretFromFile($name);
    }
    elseif ($pantheon === self::PANTHEON_UNSET || $pantheon === '') {
      $value = NULL;
    }
    else {
      // A Pantheon secret is a string, so the list is stored as JSON.
      $value = json_decode($pantheon, TRUE);
      if (!is_array($value)) {
        return ['status' => self::STATUS_BROKEN, 'keys' => []];
      }
    }
    if ($value === NULL || $value === []) {
      return ['status' => self::STATUS_NONE, 'keys' => []];
    }
    if (!is_array($value) || !array_is_list($value)) {
      return ['status' => self::STATUS_BROKEN, 'keys' => []];
    }
    // A key belonging to the other document must never be published here:
    // the cookie key stays out of the exchange JWKS and vice versa.
    $foreign = [];
    foreach (self::DOCUMENT_PURPOSES as $other => $purposes) {
      if ($other === $document) {
        continue;
      }
      foreach ($purposes as $purpose) {
        $key = $purpose === self::PURPOSE_COOKIE ? $this->getPrivateKey() : $this->loadSlot($purpose)['key'];
        if ($key !== NULL) {
          $foreign[] = $this->thumbprint($key);
        }
      }
    }

    $keys = [];
    foreach ($value as $pem) {
      $key = is_string($pem) ? openssl_pkey_get_public($pem) : FALSE;
      if ($key === FALSE || !$this->isP256($key) || in_array($this->thumbprint($key), $foreign, TRUE)) {
        return ['status' => self::STATUS_BROKEN, 'keys' => []];
      }
      $keys[] = $key;
    }
    return ['status' => self::STATUS_LOADED, 'keys' => $keys];
  }

  /**
   * Loads an agent or xdmod slot, applying the same-key guard.
   *
   * @param string $purpose
   *   PURPOSE_AGENT or PURPOSE_XDMOD.
   *
   * @return array{status: string, key: ?\OpenSSLAsymmetricKey}
   *   The slot.
   */
  protected function loadSlot(string $purpose): array {
    if (isset($this->slots[$purpose])) {
      return $this->slots[$purpose];
    }
    $slot = $this->parseSlot($purpose);

    if ($slot['status'] === self::STATUS_LOADED) {
      // Any two slots holding the same key count as unusable. The cookie key
      // is compared but never demoted, so the cookie path stays as it was.
      $kid = $this->thumbprint($slot['key']);
      $others = [];
      $cookie = $this->getPrivateKey();
      if ($cookie !== NULL) {
        $others[self::PURPOSE_COOKIE] = $this->thumbprint($cookie);
      }
      foreach ([self::PURPOSE_AGENT, self::PURPOSE_XDMOD] as $other) {
        if ($other !== $purpose) {
          $parsed = $this->parseSlot($other);
          if ($parsed['status'] === self::STATUS_LOADED) {
            $others[$other] = $this->thumbprint($parsed['key']);
          }
        }
      }
      $clash = array_keys($others, $kid, TRUE);
      if ($clash) {
        \Drupal::logger('access')->error('The @purpose key is the same key as the @other key, so it is not used.', [
          '@purpose' => $purpose,
          '@other' => implode(', ', $clash),
        ]);
        $slot = ['status' => self::STATUS_BROKEN, 'key' => NULL];
      }
    }

    return $this->slots[$purpose] = $slot;
  }

  /**
   * Parses an agent or xdmod key without the same-key guard.
   *
   * @return array{status: string, key: ?\OpenSSLAsymmetricKey}
   *   The slot.
   */
  protected function parseSlot(string $purpose): array {
    $pem = $this->readPem($purpose);
    if ($pem === NULL) {
      return ['status' => self::STATUS_NONE, 'key' => NULL];
    }
    $key = openssl_pkey_get_private($pem);
    if ($key === FALSE || !$this->isP256($key)) {
      \Drupal::logger('access')->error('The configured @purpose signing key is not a valid EC P-256 private key.', [
        '@purpose' => $purpose,
      ]);
      return ['status' => self::STATUS_BROKEN, 'key' => NULL];
    }
    return ['status' => self::STATUS_LOADED, 'key' => $key];
  }

  /**
   * Reads a purpose's private key PEM from its configured source.
   *
   * @param string $purpose
   *   One of the PURPOSE_* constants.
   *
   * @return string|null
   *   The PEM (possibly invalid), '' for a file path that cannot be read, or
   *   NULL if no source is configured.
   */
  protected function readPem(string $purpose): ?string {
    [$secret, $env, $file_env] = self::KEY_SOURCES[$purpose];
    if ($purpose !== self::PURPOSE_COOKIE) {
      $pantheon = $this->getPantheonSecret($secret);
      if ($pantheon !== NULL) {
        return $pantheon === self::PANTHEON_UNSET || $pantheon === '' ? NULL : $pantheon;
      }
    }
    $pem = $this->getSecretFromFile($secret);
    if (is_string($pem) && $pem !== '') {
      return $pem;
    }
    $pem = $this->getEnv($env);
    if ($pem !== NULL) {
      return $pem;
    }
    $path = $this->getEnv($file_env);
    if ($path !== NULL) {
      return is_readable($path) ? (string) file_get_contents($path) : '';
    }
    return NULL;
  }

  /**
   * Reads a Pantheon Secrets value.
   *
   * @return string|null
   *   The value, or NULL where the secret does not exist or the platform has
   *   no Pantheon Secrets (ddev, CI).
   */
  protected function getPantheonSecret(string $name): ?string {
    if (!function_exists('pantheon_get_secret')) {
      return NULL;
    }
    // Values pasted through terminus may carry surrounding whitespace.
    $value = pantheon_get_secret($name);
    return is_string($value) ? trim($value) : NULL;
  }

  /**
   * Reads a non-empty environment variable.
   */
  protected function getEnv(string $name): ?string {
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Reads a secret from Pantheon Secrets, secrets.json, then an env var.
   *
   * Used for the token-exchange client secret hashes.
   *
   * @param string $secret_name
   *   The secrets.json key.
   * @param string $env_name
   *   The env var name.
   *
   * @return string|null
   *   The value, or NULL if neither is set.
   */
  public function getConfiguredSecret(string $secret_name, string $env_name): ?string {
    $pantheon = $this->getPantheonSecret($secret_name);
    if ($pantheon !== NULL) {
      return $pantheon === self::PANTHEON_UNSET || $pantheon === '' ? NULL : $pantheon;
    }
    $value = $this->getSecretFromFile($secret_name);
    if (is_string($value) && $value !== '') {
      return $value;
    }
    return $this->getEnv($env_name);
  }

  /**
   * Whether a key is an EC key on P-256.
   */
  protected function isP256(\OpenSSLAsymmetricKey $key): bool {
    $details = openssl_pkey_get_details($key);
    return $details !== FALSE
      && ($details['type'] ?? -1) === OPENSSL_KEYTYPE_EC
      && ($details['ec']['curve_name'] ?? '') === 'prime256v1';
  }

}
