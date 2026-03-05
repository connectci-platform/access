<?php

namespace Drupal\access_misc\Services;

use Drupal\node\Entity\Node;

/**
 * Find the access organization entity reference for the given access org id.
 *
 * This is the field that is on the user profile.
 */
class ImportAccessOrgs {

  /**
   * Whether to enable debug mode.
   *
   * @var bool
   */
  private bool $debug = FALSE;

  /**
   * Ingests organizations from the ACCESS API.
   */
  public function ingest($verbose = FALSE) {
    $orgs = [];

    $path = \Drupal::service('file_system')->realpath("private://") . '/.keys/secrets.json'; // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if (!file_exists($path)) {
      \Drupal::logger('access_misc')->error('Unable to find ACCESS API key file'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      return;
    }
    $secrets_json_text = file_get_contents($path);
    $secrets_data = json_decode($secrets_json_text, TRUE);
    if (!isset($secrets_data['ramps_api_key']) || !$secrets_data['ramps_api_key']) {
      \Drupal::logger('access_misc')->error('Unable to find ACCESS API key'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      return;
    }

    try {
      $curl = curl_init();

      curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => 'https://allocations-api.access-ci.org/identity/profiles/v1/organizations',
      ]);
      curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'XA-REQUESTER: MATCH',
        'XA-API-KEY: ' . $secrets_data['ramps_api_key'],
      ]);

      $response = curl_exec($curl);

      if (curl_errno($curl)) {
        $error_msg = curl_error($curl);

        throw new \Exception($error_msg);
      }

      curl_close($curl);

      $orgs = json_decode($response);

      \Drupal::logger('access_misc')->info('Found ' . count($orgs) . ' organizations.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

      if (empty($orgs)) {
        throw new \Exception('No organization results found from source API.');
      }

      /*
      Example API response

      [
      {
      "organization_id": 2735,
      "org_type_id": 4,
      "organization_abbrev": "Exa Corp.",
      "organization_name": "Exa Corporation",
      "organization_url": null,
      "organization_phone": null,
      "nsf_org_code": "T103902",
      "is_reconciled": true,
      "amie_name": null,
      "country_id": 210,
      "state_id": 23,
      "latitude": "42.497508",
      "longitude": "-71.234265",
      "is_msi": null,
      "is_active": true,
      "carnegieCategories":[],
      "state": "Massachusetts",
      "country": "United States",
      "org_type": "Industrial"
      },
      {
      "organization_id": 1231,
      "org_type_id": 6,
      "organization_abbrev": "Quetzal Computationa",
      "organization_name": "Quetzal Computational Associates",
      "organization_url": null,
      "organization_phone": null,
      "nsf_org_code": "6103790",
      "is_reconciled": true,
      "amie_name": null,
      "country_id": null,
      "state_id": null,
      "latitude": "35.124329",
      "longitude": "-106.586556",
      "is_msi": null,
      "is_active": false,
      "carnegieCategories":[],
      "state": null,
      "country": null,
      "org_type": "Other or Unknown"
      }
      ]
       */

      foreach ($orgs as $org) {
        $lat = 0;
        if ($org->latitude >= -90 && $org->latitude <= 90) {
          $lat = $org->latitude;
        }
        else {
          if ($verbose) {
            \Drupal::logger('access_misc')->warning('Latitude out of range for ' . $org->organization_name . ' (' . $org->organization_id . ')'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          }
        }

        $lon = 0;
        if ($org->longitude >= -180 && $org->longitude <= 180) {
          $lon = $org->longitude;
        }
        else {
          if ($verbose) {
            \Drupal::logger('access_misc')->warning('Longitude out of range for ' . $org->organization_name . ' (' . $org->organization_id . ')'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          }
        }

        $query = \Drupal::database() // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          ->select('node__field_organization_id', 'f')
          ->fields('f', ['entity_id']);
        $query->innerJoin('node', 'n', 'n.nid = f.entity_id');
        $query->condition('f.field_organization_id_value', $org->organization_id);
        $query->orderBy('entity_id', 'asc');
        $record = $query->execute()->fetchAll();

        if (!empty($record)) {
          if ($verbose) {
            \Drupal::logger('access_misc')->warning('Record already exists for "' . $org->organization_name . '".'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          }

          $node = Node::load($record[0]->entity_id); // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass

          // Flag to indicate if any changes were made.
          $update = FALSE;

          // Map node fields to API fields.
          $keys = [
            'title' => 'organization_name',
            'field_organization_abbrev' => 'organization_abbrev',
            'field_organization_name' => 'organization_name',
            'field_organization_url' => 'organization_url',
            'field_organization_phone' => 'organization_phone',
            'field_state' => 'state',
            'field_country' => 'country',
            'field_org_type' => 'org_type',
            // 'field_carnegie_code' => 'carnegieCategories',
          ];
          foreach ($keys as $localkey => $foreignkey) {
            // If $localkey is an array, get the first key.
            if (is_array($localkey)) {
              $localkey = array_keys($localkey)[0];
            }
            if ($node->{$localkey}->value != $org->{$foreignkey}) {
              \Drupal::logger('access_misc')->warning($org->organization_name . ' ' . $localkey . ' out of sync. Local value: ' . $node->{$localkey}->value . ', remote value: ' . $org->{$foreignkey}); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
              if (!$this->debug) {
                $node->set($localkey, $org->{$foreignkey});
              }
              $update = TRUE;
            }
          }
          if ($node->field_latitude->value != $lat) {
            \Drupal::logger('access_misc')->warning($org->organization_name . ' latitude out of sync. Local value: ' . $node->field_latitude->value . ', remote value: ' . $lat); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            if (!$this->debug) {
              $node->set('field_latitude', $lat);
            }
            $update = TRUE;
          }
          if ($node->field_longitude->value != $lon) {
            \Drupal::logger('access_misc')->warning($org->organization_name . ' longitude out of sync. Local value: ' . $node->field_longitude->value . ', remote value: ' . $lon); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            if (!$this->debug) {
              $node->set('field_longitude', $lon);
            }
            $update = TRUE;
          }

          // Check if the is_msi value is correct from the carnegie_codes table.
          // Get the is_msi value from the carnegie_codes table.
          if ($node->field_carnegie_code->first()->value != 0) {
            $query = \Drupal::database() // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
              ->select('carnegie_codes', 'cc')
              ->fields('cc', ['MSI'])
              ->condition('cc.UNITID', $node->field_carnegie_code->first()->value, '=');
            $result = $query->execute();
            $is_msi = $result->fetchField();
            if ($is_msi != $node->field_is_msi->first()->value) {
              if ($this->debug) {
                \Drupal::logger('access_misc')->warning($node->title->first()->value . ' is_msi should be ' . $is_msi); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
              }
              $node->set('field_is_msi', $is_msi);
              $update = TRUE;
            }
          }
          // Updating MSI value from carnegie_codes table instead of API.
          // phpcs:disable Drupal.Files.LineLength.TooLong, Drupal.Commenting.InlineComment
          // if (($node->field_is_msi->value && !$org->is_msi) || (!$node->field_is_msi->value && $org->is_msi)) {
          // $this->output()->writeln('<comment>    -> field_is_msi out of sync.</comment>');
          // $this->output()->writeln('<comment>       -> ' . $node->field_is_msi->value . ' != ' . ($org->is_msi ? 1 : 0) . '</comment>');
          // if (!$this->debug) {
          // $node->set('field_is_msi', $org->is_msi ? 1 : 0);
          // }
          // $update = TRUE;
          // }.
          // phpcs:enable Drupal.Files.LineLength.TooLong, Drupal.Commenting.InlineComment
          if (($node->field_is_active->value && !$org->is_active) || (!$node->field_is_active->value && $org->is_active)) {
            \Drupal::logger('access_misc')->warning($org->organization_name . ' -> field_is_active out of sync.</comment>'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            \Drupal::logger('access_misc')->warning($org->organization_name . ' -> ' . $node->field_is_active->value . ' != ' . ($org->is_active ? 1 : 0) . '</comment>'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            if (!$this->debug) {
              $node->set('field_is_active', $org->is_active ? 1 : 0);
            }
            $update = TRUE;
          }

          if ($update && !$this->debug) {
            $result = $node->save();

            if (!$result) {
              \Drupal::logger('access_misc')->error($org->organization_name . ' -> Failed to update record.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            }
            else {
              if ($verbose) {
                \Drupal::logger('access_misc')->info($org->organization_name . ' -> Updated record.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
              }
            }
          }
          else {
            if ($verbose) {
              \Drupal::logger('access_misc')->info($org->organization_name . ' -> No updates needed.'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            }
          }
          continue;
        }

        // Try to find code by institution name.
        $query = \Drupal::database() // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          ->select('carnegie_codes', 'cc')
          ->fields('cc', ['UNITID']);
        $query->condition($query->orConditionGroup()
          ->condition('NAME', $org->organization_name, '=')
          ->condition('NAME', $org->organization_name . '%', 'LIKE')
          );
        $carnegie_code = $query->execute()
          ->fetchField();

        if (!$carnegie_code) {
          if ($verbose) {
            \Drupal::logger('access_misc')->warning('Failed to find Carnegie Code for "' . $org->organization_name . '".'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          }
          $carnegie_code = 0;
        }
        else {
          if ($verbose) {
            \Drupal::logger('access_misc')->info('Assigned Carnegie Code ' . $carnegie_code . ' for "' . $org->organization_name . '".'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          }
        }

        if ($this->debug) {
          \Drupal::logger('access_misc')->info('Found carnegie code ' . $carnegie_code . ' original carnegie code ' . implode(',', $org->carnegieCategories) . '</comment>'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        }
        // Create database entry.
        $node = \Drupal::entityTypeManager() // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          ->getStorage('node')
          ->create([
            'type' => 'access_organization',
            'title' => $org->organization_name,
            'field_organization_id' => $org->organization_id,
            'field_organization_abbrev' => $org->organization_abbrev,
            'field_organization_name' => $org->organization_name,
            'field_organization_url' => $org->organization_url,
            'field_organization_phone' => $org->organization_phone,
            'field_latitude' => $lat,
            'field_longitude' => $lon,
            'field_is_msi' => $org->is_msi ? 1 : 0,
            'field_is_active' => $org->is_active ? 1 : 0,
            'field_carnegie_code' => $carnegie_code,
            'field_state' => $org->state,
            'field_country' => $org->country,
            'field_org_type' => $org->org_type,
          ]);
        $result = $node->save();

        if (!$result) {
          if ($verbose || $this->debug) {
            \Drupal::logger('access_misc')->error('Failed to create record for ' . $org->organization_name . ' (' . $org->organization_id . ')'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          }
          continue;
        }

        if ($verbose || $this->debug) {
          \Drupal::logger('access_misc')->info('Created record for ' . $org->organization_name . ' (' . $org->organization_id . ') with carnegie code ' . $node->$carnegie_code); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        }
      }
    }
    catch (\Exception $e) {
      if ($verbose) {
        \Drupal::logger('access_misc')->error($e->getMessage()); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      }
      return;
    }

  }

}
