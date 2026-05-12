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

    $effectiveUid = (int) $request->attributes->get('rp_account_effective_uid');
    $result = $this->service->getAccountsForUserAndRp($effectiveUid, $rp_nid);
    $rows = $result['rows'];
    $state = $result['state'];

    $response = [
      'rp_nid' => $rp_nid,
      'rp_display_name' => $node->getTitle(),
      'state' => $state,
      'manage_url' => 'https://allocations.access-ci.org/',
      'synced_at' => $rows ? max(array_column($rows, 'synced_at')) : NULL,
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

  private function formatGrant(array $row): array {
    return [
      'grant_number' => $row['grant_number'],
      'title' => $row['grant_title'] ?? NULL,
      'project_end' => $row['project_end'],
      'project_balance' => $row['project_balance'],
      'billable_unit' => $row['billable_unit'],
      'account_state' => $row['account_state'],
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

  private function overlayLiveBalance(array $formattedGrants, array $rows, ?int $person_id): array {
    $live = $this->xdusage->getLiveBalanceBatch($rows, $person_id, 4, 4.0, 8.0);
    foreach ($rows as $i => $row) {
      $key = $row['project_id'] . ':' . $row['resource_id'];
      $r = $live[$key] ?? NULL;
      if ($r) {
        if (isset($r['project_balance'])) {
          $formattedGrants[$i]['project_balance'] = $r['project_balance'];
        }
        if ($person_id && isset($r['account_charges'])) {
          $formattedGrants[$i]['account_charges'] = $r['account_charges'];
        }
      }
      else {
        $formattedGrants[$i]['live_balance_error'] = TRUE;
      }
    }
    return $formattedGrants;
  }

}
