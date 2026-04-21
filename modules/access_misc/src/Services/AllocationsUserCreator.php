<?php

namespace Drupal\access_misc\Services;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;

/**
 * Fetch a user from the ACCESS allocations API and create a Drupal account.
 *
 * Normal sync (AllocationsUsersImport) only imports users who appear in the
 * `?with_allocations=1` filtered list — i.e., users with active allocations.
 * Staff who need a Drupal account for community participation (e.g., affinity
 * group coordinators) but have no allocation don't get pre-populated and can
 * only be created on first CILogon login.
 *
 * This service lets admins pre-create those accounts so they can be tagged,
 * assigned roles, or added to affinity groups before their first login. The
 * resulting account uses `{username}@access-ci.org` as the Drupal username,
 * which matches the CILogon `sub` claim; when the user later authenticates,
 * the existing pre_authorize hook in openid_connect_cilogon_client links
 * the login to this account rather than creating a duplicate.
 */
class AllocationsUserCreator {

  const API_BASE = 'https://allocations-api.access-ci.org/identity/profiles/v1/people';
  const API_REQUESTER = 'MATCH';
  const ACCESS_DOMAIN = '@access-ci.org';

  protected ClientInterface $httpClient;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileSystemInterface $fileSystem;
  protected $logger;

  public function __construct(
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('access_misc');
  }

  /**
   * Create (or find) a Drupal user from an ACCESS allocations-API username.
   *
   * @param string $username
   *   Bare ACCESS username (e.g. "jsill"). Do NOT include the @access-ci.org
   *   suffix — it is appended automatically.
   *
   * @return \Drupal\user\UserInterface
   *   The new or existing Drupal user.
   *
   * @throws \RuntimeException
   *   If the API lookup fails, the user is suspended/archived, or the Drupal
   *   user cannot be saved.
   */
  public function createFromUsername(string $username): UserInterface {
    $username = trim($username);
    if ($username === '' || str_contains($username, '@')) {
      throw new \RuntimeException("Invalid username '$username' — pass the bare ACCESS handle, not the full identity.");
    }

    $drupal_name = $username . self::ACCESS_DOMAIN;

    // Return the existing account unchanged if one already exists.
    $existing = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => $drupal_name]);
    if ($existing) {
      return reset($existing);
    }

    $profile = $this->fetchProfile($username);

    if (!empty($profile['isSuspended']) || !empty($profile['isArchived'])) {
      throw new \RuntimeException("Refusing to create Drupal user for '$username': identity is suspended or archived in the allocations API.");
    }

    $user = User::create();
    $user->setUsername($drupal_name);
    $user->setEmail($profile['email']);
    $user->set('status', 1);

    if (!empty($profile['firstName'])) {
      $user->set('field_user_first_name', $profile['firstName']);
    }
    if (!empty($profile['lastName'])) {
      $user->set('field_user_last_name', $profile['lastName']);
    }
    if (!empty($profile['organizationName'])) {
      $user->set('field_institution', $profile['organizationName']);
    }
    if (!empty($profile['organizationId'])) {
      $org_nid = $this->findAccessOrgNid($profile['organizationId']);
      if ($org_nid) {
        $user->set('field_access_organization', $org_nid);
      }
    }

    $user->save();
    $this->logger->notice('Created Drupal user @name (uid @uid) from allocations API.', [
      '@name' => $drupal_name,
      '@uid' => $user->id(),
    ]);
    return $user;
  }

  /**
   * Fetch a user profile by username from the allocations API.
   */
  protected function fetchProfile(string $username): array {
    $key = $this->getApiKey();
    if (!$key) {
      throw new \RuntimeException('Allocations API key not available (private://.keys/secrets.json).');
    }

    try {
      $response = $this->httpClient->request('GET', self::API_BASE . '/' . urlencode($username), [
        'headers' => [
          'XA-API-KEY' => $key,
          'XA-REQUESTER' => self::API_REQUESTER,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 20,
      ]);
    }
    catch (\Exception $e) {
      throw new \RuntimeException("Allocations API lookup for '$username' failed: " . $e->getMessage(), 0, $e);
    }

    $data = Json::decode((string) $response->getBody());
    if (empty($data) || empty($data['username'])) {
      throw new \RuntimeException("Allocations API returned no profile for '$username'.");
    }
    return $data;
  }

  /**
   * Load the allocations API key from the shared secrets file.
   */
  protected function getApiKey(): ?string {
    $path = $this->fileSystem->realpath('private://') . '/.keys/secrets.json';
    if (!is_file($path)) {
      return NULL;
    }
    $secrets = json_decode(file_get_contents($path), TRUE);
    return $secrets['ramps_api_key'] ?? NULL;
  }

  /**
   * Look up the Drupal access_organization node nid for an allocations-API
   * organizationId. Mirrors AllocationsUsersImport::findAccessOrg.
   */
  protected function findAccessOrgNid(int $organization_id): ?int {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'access_organization')
      ->condition('field_organization_id', $organization_id)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    return $nids ? (int) reset($nids) : NULL;
  }

}
