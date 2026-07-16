<?php

namespace Drupal\access_affinitygroup\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Orchestrates per-user RP account data: read from DB, refresh from APIs.
 */
class RpAccountService {

  private const TABLE = 'access_user_rp_account';
  /** Read-side staleness TTL: rows synced more recently than this are treated as fresh by getAccountsForUser*. */
  public const FRESHNESS_TTL = 86400;
  /** Login-hook dedupe window: opportunistic refresh on login fires at most every LOGIN_REFRESH_INTERVAL seconds. Intentionally smaller than FRESHNESS_TTL — login traffic refreshes data BETWEEN page renders. */
  public const LOGIN_REFRESH_INTERVAL = 3600;
  public const SYNC_MARKER_PREFIX = 'rp_account:user_synced:';
  private const ACCESS_SUFFIX = '@access-ci.org';

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $etm,
    private readonly AllocationsClient $allocations,
    private readonly XdusageClient $xdusage,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Returns active rows + state for the (user, RP) pair.
   *
   * Pure DB read. NEVER blocks on a refresh: if the user's sync marker is
   * stale or missing, a fire-and-forget refresh is scheduled via
   * register_shutdown_function so it runs after the response is flushed.
   *
   * The 'error' state is reserved for callers that explicitly attempt a
   * synchronous refresh (e.g., a controller action) and observe failure;
   * the read path no longer produces it.
   *
   * @return array{
   *   rows: array<int, array<string, mixed>>,
   *   state: 'rows_fresh'|'rows_stale'|'no_rows_fresh'|'no_rows_unknown'|'error'
   * }
   */
  public function getAccountsForUserAndRp(int $uid, int $rp_nid): array {
    $rows = $this->loadActiveRows($uid, $rp_nid);
    $isFresh = $this->isUserSyncFresh($uid);

    // Always non-blocking: schedule a background refresh if stale.
    if (!$isFresh) {
      $this->scheduleRefreshIfStale($uid);
    }

    if ($rows) {
      return ['rows' => $rows, 'state' => $isFresh ? 'rows_fresh' : 'rows_stale'];
    }
    return ['rows' => [], 'state' => $isFresh ? 'no_rows_fresh' : 'no_rows_unknown'];
  }

  /**
   * Returns active rows + state across ALL RPs for the user.
   *
   * Same return shape and state semantics as getAccountsForUserAndRp. Pure
   * DB read with a non-blocking shutdown-phase refresh when stale.
   *
   * @return array{
   *   rows: array<int, array<string, mixed>>,
   *   state: 'rows_fresh'|'rows_stale'|'no_rows_fresh'|'no_rows_unknown'|'error'
   * }
   */
  public function getAccountsForUser(int $uid): array {
    if (!$this->isUserSyncFresh($uid)) {
      $this->scheduleRefreshIfStale($uid);
    }
    $rows = $this->db->select(self::TABLE, 'a')
      ->fields('a')
      ->condition('uid', $uid)
      ->condition('account_state', 'active')
      ->condition('is_expired', 0)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $isFresh = $this->isUserSyncFresh($uid);
    if ($rows) {
      return ['rows' => $rows, 'state' => $isFresh ? 'rows_fresh' : 'rows_stale'];
    }
    return ['rows' => [], 'state' => $isFresh ? 'no_rows_fresh' : 'no_rows_unknown'];
  }

  /**
   * Refresh all rows for a single user. Walks identity API + xdusage.
   *
   * Idempotent. Safe to retry on partial-failure: the cache "synced" marker
   * is set ONLY at the end, after the full pipeline succeeds.
   *
   * Pruning is keyed on identity-API grants, NOT on the projects-map work
   * list. A grant transiently missing from the projects map should NOT
   * cause its row to be deleted; only grants the user no longer has are
   * pruned. If the identity API itself fails (returns null), the entire
   * refresh is aborted with no DB writes and no marker change.
   *
   * Skips users whose Drupal account name does not end in @access-ci.org.
   */
  public function refreshUserRpAccounts(int $uid): void {
    $user = $this->etm->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }
    $accountName = $user->getAccountName();
    if (!str_ends_with($accountName, self::ACCESS_SUFFIX)) {
      return;
    }
    $username = substr($accountName, 0, -strlen(self::ACCESS_SUFFIX));

    // 1. Ensure person_id is on the user.
    $personId = (int) ($user->get('field_xdusage_person_id')->value ?? 0);
    if (!$personId) {
      $person = $this->xdusage->getPersonByPortalUsername($username);
      if (!$person) {
        return;
      }
      $personId = (int) $person['person_id'];
      $user->set('field_xdusage_person_id', $personId);
      $user->set('field_xdusage_person_synced', $this->time->getRequestTime());
      $user->save();
    }

    // 2. List the user's grants (canonical set; used for pruning).
    $grants = $this->allocations->getProjectsForUser($username);
    if ($grants === NULL) {
      // Identity API failure — DO NOT proceed. Skipping the rest preserves
      // existing rows (no pruning) and does NOT mark the user as synced
      // (so subsequent visits will retry the refresh).
      return;
    }
    $identityGrants = [];
    foreach ($grants as $g) {
      if (!empty($g['grant_number'])) {
        $identityGrants[$g['grant_number']] = TRUE;
      }
    }

    // 2b. Build [info_resource_id => billable_unit_type] from the identity
    //     API's per-user resources list. This is the only endpoint that
    //     includes billable_unit_type. Failure here is non-fatal — we
    //     proceed without unit data, and the upsert leaves billable_unit
    //     as NULL on rows we can't enrich.
    $resourceUnits = [];
    $resources = $this->allocations->getResourcesForUser($username);
    if (is_array($resources)) {
      foreach ($resources as $r) {
        $iri = $r['cider_resource_id'] ?? NULL;
        $unit = $r['billable_unit_type'] ?? NULL;
        if ($iri && $unit) {
          $resourceUnits[$iri] = $unit;
        }
      }
    }

    // 3. Cached projects map keyed by [grant_number][info_resource_id].
    $projectsMap = $this->xdusage->getProjectsMap();

    // 4. Build resolution table (info_resource_id -> rp_nid). Filter to the
    // access_active_resources_from_cid bundle to avoid mapping rows from
    // any other bundle that happens to use this field.
    $infoToRpNid = $this->buildInfoResourceIdToRpNidMap();

    // 5. Build the per-tuple work list.
    $work = [];
    foreach ($grants as $g) {
      $gn = $g['grant_number'] ?? NULL;
      if (!$gn) {
        continue;
      }
      $byResource = $projectsMap[$gn] ?? NULL;
      if (!$byResource) {
        // Grant not in xdusage map yet — skip without marking failure.
        continue;
      }
      foreach ($byResource as $iri => $tuple) {
        $rp_nid = $infoToRpNid[$iri] ?? NULL;
        if (!$rp_nid) {
          continue;
        }
        $work[] = [
          'gn' => $gn,
          'rp_nid' => (int) $rp_nid,
          'pid' => (int) $tuple['project_id'],
          'rid' => (int) $tuple['resource_id'],
          'iri' => $iri,
          'tuple' => $tuple,
          'title' => $g['title'] ?? '',
        ];
      }
    }

    // 6. Fetch per-user account data + upsert.
    $now = $this->time->getRequestTime();
    foreach ($work as $w) {
      $acct = $this->xdusage->getAccountForUser($w['pid'], $w['rid'], $personId);
      $rpUsername = $acct['portal_username'] ?? NULL;
      $accountState = $acct['account_state'] ?? NULL;
      $spState = $acct['sp_state'] ?? NULL;

      $this->db->merge(self::TABLE)
        ->keys([
          'uid' => $uid, 'rp_nid' => $w['rp_nid'], 'grant_number' => $w['gn'],
        ])
        ->fields([
          'project_id' => $w['pid'],
          'resource_id' => $w['rid'],
          'grant_title' => $w['title'] !== '' ? mb_substr($w['title'], 0, 255) : NULL,
          'rp_username' => $rpUsername,
          'account_state' => $accountState,
          'sp_state' => $spState,
          'project_balance' => $w['tuple']['project_balance'] ?? NULL,
          'project_end' => $w['tuple']['project_end'] ?? NULL,
          'project_state' => $w['tuple']['project_state'] ?? NULL,
          'is_expired' => !empty($w['tuple']['is_expired']) ? 1 : 0,
          'billable_unit' => $resourceUnits[$w['iri']] ?? ($w['tuple']['billable_unit'] ?? NULL),
          'synced_at' => $now,
        ])
        ->execute();
    }

    // 7. Prune rows whose grant_number is NOT in the user's identity grants.
    if ($identityGrants) {
      $this->db->delete(self::TABLE)
        ->condition('uid', $uid)
        ->condition('grant_number', array_keys($identityGrants), 'NOT IN')
        ->execute();
    }
    else {
      // User genuinely has no grants per a successful identity API call —
      // delete all their rows. (Failure paths early-returned above before
      // reaching here, so $identityGrants empty here always means
      // empty-on-success, not empty-on-failure.)
      $this->db->delete(self::TABLE)
        ->condition('uid', $uid)
        ->execute();
    }

    // 8. Mark user as synced (only after the full pipeline above succeeds).
    $this->cache->set(
      self::SYNC_MARKER_PREFIX . $uid,
      $now,
      $now + self::FRESHNESS_TTL
    );
  }

  /**
   * Wraps XdusageClient::getLiveBalance for one row, scoped to a specific user.
   *
   * @param array $row
   *   A row from access_user_rp_account (must contain project_id, resource_id).
   * @param int $person_id
   *   The xdusage person_id to scope the balance lookup to. Passing this
   *   ensures the API filter returns only that user's row; without it, the
   *   API would return arbitrary first-row data which could leak another
   *   user's balance.
   *
   * @return array{project_balance: ?string, account_charges: ?string, billable_unit: ?string}|null
   */
  public function getLiveBalanceForRow(array $row, int $person_id): ?array {
    return $this->xdusage->getLiveBalance(
      (int) $row['project_id'],
      (int) $row['resource_id'],
      $person_id
    );
  }

  /**
   * Resolve an ACCESS Global Resource ID to its RP node id.
   *
   * @return int|null
   *   The rp_nid, or NULL if the id maps to no published
   *   access_active_resources_from_cid node.
   */
  public function resolveGlobalResourceIdToNid(string $global_resource_id): ?int {
    $map = $this->buildInfoResourceIdToRpNidMap();
    return $map[$global_resource_id] ?? NULL;
  }

  private function loadActiveRows(int $uid, int $rp_nid): array {
    return $this->db->select(self::TABLE, 'a')
      ->fields('a')
      ->condition('uid', $uid)
      ->condition('rp_nid', $rp_nid)
      ->condition('account_state', 'active')
      ->condition('is_expired', 0)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  private function isUserSyncFresh(int $uid): bool {
    $marker = $this->cache->get(self::SYNC_MARKER_PREFIX . $uid);
    if (!$marker) {
      return FALSE;
    }
    return ($marker->data + self::FRESHNESS_TTL) > $this->time->getRequestTime();
  }

  /**
   * Schedule a fire-and-forget refresh for $uid, deduped per-request.
   *
   * Uses register_shutdown_function so the actual API work runs after the
   * response has been flushed to the user. Keeps a static map of uids
   * already scheduled in this request to avoid double-scheduling when
   * multiple read methods are called for the same user (e.g., a page that
   * calls getAccountsForUserAndRp twice for two RPs).
   */
  private function scheduleRefreshIfStale(int $uid): void {
    static $scheduled = [];
    if (isset($scheduled[$uid])) {
      return;
    }
    $scheduled[$uid] = TRUE;

    register_shutdown_function(function () use ($uid) {
      try {
        $this->runGuardedRefresh($uid);
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('access_affinitygroup')
          ->error('Background refresh failed for uid @u: @m', [
            '@u' => $uid, '@m' => $e->getMessage(),
          ]);
      }
    });
  }

  /**
   * Run refreshUserRpAccounts under a per-uid lock. If the lock is already
   * held (a refresh is in flight for this uid), skip — no double refresh.
   *
   * Public so it is directly testable; the lock lifecycle lives here, inside
   * the shutdown-fn scope, because Drupal locks are request-scoped and would
   * auto-release if acquired in the request body before the shutdown refresh.
   */
  public function runGuardedRefresh(int $uid): void {
    $lockId = 'rp_account_refresh:' . $uid;
    if (!$this->lock->acquire($lockId)) {
      return;
    }
    try {
      $this->refreshUserRpAccounts($uid);
    }
    finally {
      $this->lock->release($lockId);
    }
  }

  /**
   * Returns [info_resource_id => rp_nid] for the access_active_resources_from_cid bundle.
   *
   * The query filters to type=access_active_resources_from_cid and status=1
   * to avoid mapping nids from any other bundle that may also use the field.
   *
   * Strict info_resource_id equality. Per design doc (2026-05-09 §risks), if
   * QA surfaces real-world .access-ci.org ↔ .xsede.org mismatches between
   * the xdusage projects feed and the CiDeR-synced node field, add a
   * suffix-swap fallback here. Symptom: user has allocation but panel
   * never appears for an expected RP.
   */
  /**
   * For a set of rp_nids, return [nid => ['resource_id' => <dotted id>, 'rp_display_name' => <title>]].
   * Any access_active_resources_from_cid node (published or not, matching get()'s behavior) with a
   * global resource id is included; unknown nids are simply absent from the result.
   *
   * @param int[] $rpNids
   * @return array<int, array{resource_id: string, rp_display_name: string}>
   */
  public function resolveRpNidsToResourceInfo(array $rpNids): array {
    if (!$rpNids) {
      return [];
    }
    $query = $this->db->select('node__field_access_global_resource_id', 'f');
    $query->innerJoin('node_field_data', 'n', 'n.nid = f.entity_id');
    $query->fields('f', ['entity_id', 'field_access_global_resource_id_value'])
      ->fields('n', ['title'])
      ->condition('n.type', 'access_active_resources_from_cid')
      ->condition('f.entity_id', $rpNids, 'IN');
    $out = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $r) {
      $out[(int) $r['entity_id']] = [
        'resource_id' => $r['field_access_global_resource_id_value'],
        'rp_display_name' => $r['title'],
      ];
    }
    return $out;
  }

  private function buildInfoResourceIdToRpNidMap(): array {
    $query = $this->db->select('node__field_access_global_resource_id', 'f');
    $query->innerJoin('node_field_data', 'n', 'n.nid = f.entity_id');
    $query->fields('f', ['entity_id', 'field_access_global_resource_id_value'])
      ->condition('n.type', 'access_active_resources_from_cid')
      ->condition('n.status', 1);
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
      $map[$r['field_access_global_resource_id_value']] = (int) $r['entity_id'];
    }
    return $map;
  }
}
