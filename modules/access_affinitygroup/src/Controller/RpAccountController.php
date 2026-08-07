<?php

namespace Drupal\access_affinitygroup\Controller;

use Drupal\access_affinitygroup\Service\RpAccountService;
use Drupal\access_affinitygroup\Service\XdusageClient;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/1.0/rp-account/{rp_nid}.
 */
class RpAccountController extends ControllerBase {

  public function __construct(
    private readonly RpAccountService $service,
    private readonly XdusageClient $xdusage,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('access_affinitygroup.rp_account'),
      $container->get('access_affinitygroup.xdusage_client'),
    );
  }

  public function get(int $rp_nid, Request $request): JsonResponse {
    $node = $this->entityTypeManager()->getStorage('node')->load($rp_nid);
    if (!$node instanceof NodeInterface
        || $node->bundle() !== 'access_active_resources_from_cid') {
      throw new NotFoundHttpException();
    }

    $effectiveUid = (int) $request->attributes->get('acting_user_uid');
    $result = $this->service->getAccountsForUserAndRp($effectiveUid, $rp_nid);
    $rows = $result['rows'];
    $state = $result['state'];

    $response = [
      'rp_nid' => $rp_nid,
      'rp_display_name' => $node->getTitle(),
      'state' => $state,
      'manage_url' => 'https://allocations.access-ci.org/',
      'account_setup' => $this->accountSetup($node),
      'synced_at' => $rows ? gmdate('c', (int) max(array_column($rows, 'synced_at'))) : NULL,
    ];

    if (!$rows) {
      // Distinguish "we know they have nothing" (no_rows_fresh) from
      // "we don't yet know" (no_rows_unknown / error).
      $response['has_account'] = $state === 'no_rows_fresh' ? FALSE : NULL;
      $response['stale'] = in_array($state, ['rows_stale', 'no_rows_unknown', 'error'], TRUE);
      return (new JsonResponse($response))
        ->setPrivate()
        ->setMaxAge(0);
    }

    $response['has_account'] = TRUE;
    $response['stale'] = $state === 'rows_stale';

    $rpUsernames = array_unique(array_filter(array_column($rows, 'rp_username')));
    $response['rp_username'] = count($rpUsernames) === 1 ? reset($rpUsernames) : NULL;
    $response['grants'] = array_map(fn($r) => $this->formatGrant($r), $rows);

    if ($request->query->get('live') === '1') {
      $person_id = $this->resolvePersonId($effectiveUid);
      if ($person_id !== NULL) {
        $response['grants'] = $this->overlayLiveBalance($response['grants'], $rows, $person_id);
      }
      else {
        // Live overlay requires a known person_id; without it we cannot
        // safely scope the API call to this user. Mark every grant as
        // unavailable so the client can show a "balance unavailable"
        // hint instead of a possibly-foreign value.
        foreach ($response['grants'] as $i => $_) {
          $response['grants'][$i]['live_balance_error'] = TRUE;
          $response['grants'][$i]['live_unavailable_reason'] = 'no_person_id';
        }
      }
    }

    return (new JsonResponse($response))
      ->setPrivate()
      ->setMaxAge(0);
  }

  /**
   * GET /api/1.0/rp-account/by-resource/{global_resource_id}.
   *
   * Resolves the global resource id to an RP nid, then delegates to get().
   */
  public function getByResourceId(string $global_resource_id, Request $request): JsonResponse {
    $rpNid = $this->service->resolveGlobalResourceIdToNid($global_resource_id);
    if ($rpNid === NULL) {
      throw new NotFoundHttpException();
    }
    return $this->get($rpNid, $request);
  }

  /**
   * GET /api/1.0/rp-accounts — the acting user's RP accounts, grouped by RP.
   */
  public function listAccounts(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    $result = $this->service->getAccountsForUser($uid);
    $rows = $result['rows'];

    $rpNids = array_values(array_unique(array_map(fn($r) => (int) $r['rp_nid'], $rows)));
    $info = $this->service->resolveRpNidsToResourceInfo($rpNids);

    $byRp = [];
    foreach ($rows as $row) {
      $byRp[(int) $row['rp_nid']][] = $row;
    }

    $syncedAt = $rows ? max(array_column($rows, 'synced_at')) : NULL;

    $accounts = [];
    foreach ($byRp as $rpNid => $rpRows) {
      if (!isset($info[$rpNid])) {
        continue;
      }
      $usernames = array_unique(array_filter(array_column($rpRows, 'rp_username')));
      $accounts[] = [
        'resource_id' => $info[$rpNid]['resource_id'],
        'rp_display_name' => $info[$rpNid]['rp_display_name'],
        'rp_username' => count($usernames) === 1 ? reset($usernames) : NULL,
        // All rows are account_state=active (getAccountsForUser filters to active),
        // so row[0] is representative for the RP-level state.
        'account_state' => $rpRows[0]['account_state'],
        'grants' => array_map(fn($r) => $this->formatGrant($r), $rpRows),
      ];
    }

    return (new JsonResponse([
      'accounts' => $accounts,
      'state' => $this->translateState($result['state']),
      'synced_at' => $syncedAt ? gmdate('c', (int) $syncedAt) : NULL,
    ]))->setPrivate()->setMaxAge(0);
  }

  /**
   * Maps service state → API state.
   *
   * no_rows_unknown (cold) → syncing; others pass through.
   */
  private function translateState(string $serviceState): string {
    return $serviceState === 'no_rows_unknown' ? 'syncing' : $serviceState;
  }

  private function formatGrant(array $row): array {
    return [
      'grant_number' => $row['grant_number'],
      'title' => $row['grant_title'] ?? NULL,
      'project_end' => $row['project_end'],
      'project_balance' => $row['project_balance'],
      // Present in every grant for a stable schema; only the live overlay
      // populates it (snapshot rows don't carry account_charges).
      'account_charges' => NULL,
      'billable_unit' => $row['billable_unit'],
      'account_state' => $row['account_state'],
      'sp_state' => $row['sp_state'] ?? NULL,
    ];
  }

  private function resolvePersonId(int $uid): ?int {
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user) {
      return NULL;
    }
    $pid = (int) ($user->get('field_xdusage_person_id')->value ?? 0);
    return $pid ?: NULL;
  }

  /**
   * Returns the account-setup link for the resource, with inheritance applied.
   *
   * Applies Resource Group inheritance in memory (guarded: the service may be
   * absent, e.g. in kernel tests). Returns NULL if neither the resource nor
   * its group defines the field.
   *
   * @return array|null
   *   An array with 'uri' and 'title' keys, or NULL.
   */
  private function accountSetup(NodeInterface $node): ?array {
    // Apply group inheritance in memory, mirroring the theme preprocess.
    // Guard the service: it may be absent (e.g. in kernel tests).
    if (\Drupal::hasService('operations_cider.resource_group_inheritance')) {
      $node = clone $node;
      \Drupal::service('operations_cider.resource_group_inheritance')
        ->applyInheritance($node);
    }
    if (!$node->hasField('field_rp_account_setup_url')
        || $node->get('field_rp_account_setup_url')->isEmpty()) {
      return NULL;
    }
    $item = $node->get('field_rp_account_setup_url')->first();
    return [
      'uri' => str_replace('internal:', '', (string) $item->uri),
      'title' => $item->title !== '' ? $item->title : NULL,
    ];
  }

  private function overlayLiveBalance(array $formattedGrants, array $rows, ?int $person_id): array {
    $live = $this->xdusage->getLiveBalanceBatch($rows, $person_id, 4, 4.0, 8.0);
    foreach ($rows as $i => $row) {
      $key = $row['project_id'] . ':' . $row['resource_id'];
      $r = $live[$key] ?? NULL;
      if ($r) {
        if (isset($r['project_balance'])) {
          // Round to the same scale (4) the stored snapshot uses, so live vs
          // snapshot differ in freshness only — not precision.
          $formattedGrants[$i]['project_balance'] = number_format((float) $r['project_balance'], 4, '.', '');
        }
        if ($person_id && isset($r['account_charges'])) {
          $formattedGrants[$i]['account_charges'] = number_format((float) $r['account_charges'], 4, '.', '');
        }
      }
      else {
        $formattedGrants[$i]['live_balance_error'] = TRUE;
      }
    }
    return $formattedGrants;
  }

}
