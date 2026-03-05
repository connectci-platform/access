<?php

namespace Drupal\access\Controller;

use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;

/**
 * Controller for JSON API access endpoints.
 */
class JsonApiAccessController {

  /**
   * API endpoint for organization data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function handleAutocomplete(Request $request) {
    // Sorting options.
    $sort = $request->query->get('sort');

    $allowed_sorts = [
      'field_organization_id',
      'field_organization_abbrev',
      'field_organization_name',
      'field_organization_phone',
      'field_country',
      'field_carnegie_code',
      'field_state',
      'field_org_type',
      'field_is_active',
      'field_is_msi',
    ];
    if (!in_array($sort, $allowed_sorts)) {
      $sort = 'field_organization_name';
    }

    // Range options.
    $start = $request->query->get('start');
    $start = $start ? $start : 0;

    $limit = $request->query->get('limit');
    $limit = $limit ? $limit : 10;

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'access_organization')
      ->accessCheck(FALSE);

    // Search.
    $search = $request->query->get('q');
    $search = $search ? Xss::filter($search) : NULL;

    if ($search) {
      $query->condition($query->orConditionGroup()
        ->condition('field_organizaition_abbrev', $search, '=')
        ->condition('field_organizaition_name', $search . '%', 'LIKE')
        );
    }

    // Filter by type.
    $type = $request->query->get('type');
    $type = $type ? Xss::filter($type) : NULL;

    if ($type) {
      $query->condition('field_org_type', $type, '=');
    }

    // Filter by country.
    $country = $request->query->get('country');
    $country = $country ? Xss::filter($country) : NULL;

    if ($country) {
      $query->condition('field_country', $country, '=');
    }

    // Filter by state.
    $state = $request->query->get('state');
    $state = $state ? Xss::filter($state) : NULL;

    if ($state) {
      $query->condition('field_state', $state, '=');
    }

    // Filter by active.
    $is_active = $request->query->get('active');

    if (!is_null($is_active)) {
      $is_active = $is_active ? 1 : 0;

      $query->condition('field_is_active', $is_active, '=');
    }

    // Filter by IS MSI.
    $is_msi = $request->query->get('msi');

    if (!is_null($is_msi)) {
      $is_msi = $is_msi ? 1 : 0;

      $query->condition('field_is_msi', $is_msi, '=');
    }

    // Get records.
    $nids = $query->sort($sort)
      ->range($start, $limit)
      ->execute();

    $rows = Node::loadMultiple($nids);

    $results = [];
    foreach ($rows as $org) {
      $results[] = [
        'nid' => $org->nid->value,
        'organization_id' => $org->field_organization_id->value,
        'organization_abbrev' => $org->field_organization_abbrev->value,
        'organization_name' => $org->field_organization_name->value,
        'organization_url' => $org->field_organization_url ? $org->field_organization_url->value : NULL,
        'organization_phone' => $org->field_organization_phone ? $org->field_organization_phone->value : NULL,
        'latitude' => $org->field_latitude ? $org->field_latitude->value : NULL,
        'longitude' => $org->field_longitude ? $org->field_longitude->value : NULL,
        'is_msi' => $org->field_is_msi ? (bool) $org->field_is_msi->value : NULL,
        'is_active' => $org->field_is_active ? (bool) $org->field_is_active->value : NULL,
        'carnegie_code' => $org->field_carnegie_code ? $org->field_carnegie_code->value : 0,
        'state' => $org->field_state ? $org->field_state->value : NULL,
        'country' => $org->field_country ? $org->field_country->value : NULL,
        'org_type' => $org->field_org_type ? $org->field_org_type->value : NULL,
      ];
    }

    return new JsonResponse($results);
  }

}
