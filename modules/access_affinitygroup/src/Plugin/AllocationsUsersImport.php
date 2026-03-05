<?php

namespace Drupal\access_affinitygroup\Plugin;

// Use Drupal\access_misc\Plugin\Util\FindAccessOrg;.
use Drupal\Component\Serialization\Json;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;

/**
 * @file
 * Imports users using allocations api.
 *
 * The purpose is that each user working with an allocation:
 *  1) should have a user account here
 *  2) their email on constant contact
 *  3) get added to affinity groups: access-support and the one
 *    that is associated with the allocation.
 *  4) user details such as citizenship are updated in our
 *    database's user profiles
 * This is done during a daily cron job.  We get all the active users from the
 * allocations api. Once we get all the usernames into arrays,
 * we call the api for each
 * one to get the user's current list of resources (aka allocations aka ciders).
 * The allocations import can also be run manually, referred to
 * as "batch" in this file.
 *
 * This file also contains ancillary cleanup processes Sync and
 * Remove Obsolete Allocations.
 */
/**
 * Manages user imports from the allocations API and Constant Contact sync.
 */
class AllocationsUsersImport {

  // Default if not set: how many users to process in each batch.
  const DEFAULT_SIZE = 5;
  // Stop processing after this amount. total is > 100k,
  // will be 12k when api updated.
  const DEFAULT_IMPORTLIMIT = 100;
  // If true, don't create new constant contact user or attempt
  // to add to cc list. For dev.
  const DEFAULT_NOCC = TRUE;
  // If true, don't save user detail changes. Needed for dev
  // testing because user details
  // such as name and email are encoded in test db.
  const DEFAULT_NOUSERDETSAVE = TRUE;
  const DEFAULT_STARTAT = 0;
  const DEFAULT_VERBOSE = FALSE;

  /**
   * Where to start processing in case we must restart a large operation.
   *
   * These are set in the constant contact form.
   *
   * @var int
   */
  private $sliceSize;

  /**
   * The batch import limit.
   *
   * @var int
   */
  private $batchImportLimit;

  /**
   * If true, skip Constant Contact operations during batch.
   *
   * @var bool
   */
  private $batchNoCC;

  /**
   * If true, skip saving user detail changes during batch.
   *
   * @var bool
   */
  private $batchNoUserDetSave;

  /**
   * The index in the user list to start processing at.
   *
   * @var int
   */
  private $batchStartAt;

  /**
   * If true, log additional detail during processing.
   *
   * @var bool
   */
  private $verboseLogging;

  /**
   * True when running as a cron job (vs batch).
   *
   * @var bool
   */
  private $isCronJob;

  /**
   * The current user number being processed.
   *
   * @var int
   */
  private $currentNumber;

  /**
   * Dev prefix string for emails/usernames; empty string for real names.
   *
   * @var string
   */
  private $cDevUname = '';

  /**
   * The node ID of the ACCESS Support affinity group node.
   *
   * @var int
   */
  private $accessSupportNodeId;

  /**
   * For $this->collectCronLog for dev status email.
   *
   * @var array
   */
  private $logCronErrors = [];

  /**
   * Imports allocations via cron job.
   *
   * Parameters are set and saved on the constant contact admin form.
   */
  public function runCronSlice() {
    $this->isCronJob = TRUE;

    $this->batchStartAt = \Drupal::state()->get('access_affinitygroup.allocCronStartAt');
    if (empty($this->batchStartAt)) {
      $this->batchStartAt = self::DEFAULT_STARTAT;
    }
    $this->sliceSize = \Drupal::state()->get('access_affinitygroup.allocCronSliceSize');
    if (empty($this->sliceSize)) {
      $this->sliceSize = self::DEFAULT_SIZE;
    }
    $this->batchNoCC = \Drupal::state()->get('access_affinitygroup.allocCronNoCC');
    if (!isset($this->batchNoCC)) {
      $this->batchNoCC = self::DEFAULT_NOCC;
    }
    $this->batchNoUserDetSave = \Drupal::state()->get('access_affinitygroup.allocCronNoUserDetSave');
    if (!isset($this->batchNoUserDetSave)) {
      $this->batchNoUserDetSave = self::DEFAULT_NOUSERDETSAVE;
    }
    $this->verboseLogging = \Drupal::state()->get('access_affinitygroup.allocCronVerbose');
    if (!isset($this->verboseLogging)) {
      $this->verboseLogging = self::DEFAULT_VERBOSE;
    }

    $msg1 = "Alloc start:$this->batchStartAt size:$this->sliceSize  noCC:$this->batchNoCC noUserDet:$this->batchNoUserDetSave verbose:$this->verboseLogging";
    $this->collectCronLog($msg1, 'i', TRUE);

    // Get ready to process imports, and retrieve initial list
    // of portal user names.
    $portalUserNames = $this->setupForImports();

    // If we don't get any more names , reset start to 0 for the
    // next round of alloc cron runs.
    if (empty($portalUserNames)) {
      \Drupal::state()->set('access_affinitygroup.allocCronStartAt', 0);
      return;
    }

    try {
      $nameCount = count($portalUserNames);
      $this->collectCronLog("Start to process $nameCount portal names.", 'd', TRUE);
      $this->allocationsImportWork($portalUserNames);
    }
    catch (\Exception $e) {
      $this->collectCronLog("Exception in allocations batch setup: " . $e->getMessage(), 'err');
      \Drupal::state()->set('access_affinitygroup.allocationsRun', FALSE);
    } finally {
      // Reset start to next slice.
      $processedIndex = \Drupal::state()->get('access_affinitygroup.allocCronStartAt') + $this->currentNumber;
      \Drupal::state()->set('access_affinitygroup.allocCronStartAt', $processedIndex);

      $msg1 = "Allocations cron done with $this->currentNumber; next start is $processedIndex";
      $this->collectCronLog($msg1, 'i', TRUE);

      $this->emailDevCronLog($this->logCronErrors);
    }
  }

  /**
   * Imports allocations using batch api, meant to run manually from UI.
   *
   * Parameters are set for this one run the constant contact admin form.
   * Must run as user 1.
   */
  public function startBatch($batchsize, $importlimit, $nocc, $nouserdetsave, $startat, $verbose = FALSE) {

    if (\Drupal::currentUser()->id() !== '1') {
      \Drupal::messenger()->addMessage("Allocations manual batch can only be run as User 1.");
      return;
    }

    $this->isCronJob = FALSE;
    $this->verboseLogging = $verbose;
    $this->sliceSize = $batchsize;
    if (empty($this->sliceSize)) {
      $this->sliceSize = self::DEFAULT_SIZE;
    }
    $this->batchImportLimit = $importlimit;
    if (empty($this->batchImportLimit)) {
      $this->batchImportLimit = self::DEFAULT_IMPORTLIMIT;
    }
    $this->batchStartAt = $startat;
    if (empty($this->batchStartAt)) {
      $this->batchStartAt = self::DEFAULT_STARTAT;
    }
    $this->batchNoCC = $nocc;
    if (!isset($this->batchNoCC)) {
      $this->batchNoCC = self::DEFAULT_NOCC;
    }
    $this->batchNoUserDetSave = $nouserdetsave;
    if (!isset($this->batchNoUserDetSave)) {
      $this->batchNoUserDetSave = self::DEFAULT_NOUSERDETSAVE;
    }
    $msg1 = "Import batch start: $this->batchStartAt size: $this->sliceSize limit: $this->batchImportLimit noCC: $this->batchNoCC verbose: $this->verboseLogging";
    \Drupal::messenger()->addMessage($msg1);
    $this->collectCronLog($msg1, 'i', TRUE);
    $portalUserNames = $this->setupForImports();
    if (empty($portalUserNames)) {
      return;
    }

    try {
      $nameCount = count($portalUserNames);
      $batchBuilder = (new Batchbuilder())
        ->setTitle('Importing Allocations')
        ->setInitMessage('Batch is starting for ' . $nameCount)
        ->setProgressMessage('Estimated remaining time: @estimate. Elapsed time : @elapsed.')
        ->setErrorMessage('Batch error.')
        ->setFinishCallback([$this, 'importFinished']);
      $batchBuilder->addOperation([$this, 'processChunk'], [$portalUserNames]);

      batch_set($batchBuilder->toArray());
      if (PHP_SAPI == 'cli') {
        drush_backend_batch_process();
      }
    }
    catch (\Exception $e) {
      $this->collectCronLog("Exception in allocations batch setup: " . $e->getMessage(), 'err');
      \Drupal::state()->set('access_affinitygroup.allocationsRun', FALSE);
    }
  }

  /**
   * Function that gets called when batch processing finished (not the cron)
   */
  public function importFinished($success, $results, $operations) {

    \Drupal::state()->set('access_affinitygroup.allocationsRun', FALSE);

    if (empty($results)) {
      $msg1 = "Import finished irregularly; results empty.";
      \Drupal::messenger()->addMessage($msg1);
      $this->collectCronLog("Batch: " . $msg1, 'err', TRUE);
    }
    else {
      $msg1 = 'Processed ' . $results['totalProcessed'] . ' out of ' . $results['totalExamined'] . ' examined.';
      $msg2 = "New users: " . $results['newUsers'] . " New Constant Contact: " . $results['newCCIds'];

      \Drupal::messenger()->addMessage($msg1);
      $this->collectCronLog("Batch: " . $msg1, 'i', TRUE);

      \Drupal::messenger()->addMessage($msg2);
      $this->collectCronLog("Batch: " . $msg2, 'i', TRUE);
    }
    if (!$success) {
      $this->collectCronLog('Batch: Import Allocations problem', 'err');
      \Drupal::messenger()->addMessage('Batch: Import Allocations problem');
    }

    $this->emailDevCronLog($results['logCronErrors']);
  }

  /**
   * Common setup for batch + cron allocations import.
   *
   * Return list of portal user names; null if not ready to go.
   */
  private function setupForImports() {
    $portalUserNames = NULL;
    $this->currentNumber = 0;
    try {
      $cca = new ConstantContactApi('support');
      if (!$cca->getConnectionStatus()) {
        \Drupal::logger('cron_affinitygroup')->error('Allocations imports: Contant Contact not connected.');
        return NULL;
      }
      // Get ACCESS Support affinity group node for later.
      $nArray = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'affinity_group')
        ->condition('title', 'ACCESS Support')
        ->execute();
      $this->accessSupportNodeId = empty($nArray) ? 0 : array_values($nArray)[0];

      if ($this->isCronJob) {
        $portalUserNames = $this->getApiPortalUserNamesForCron($this->batchStartAt, $this->sliceSize);
      }
      else {
        $portalUserNames = $this->getApiPortalUserNamesForBatch($this->batchStartAt, $this->batchImportLimit);
      }
      if (empty($portalUserNames)) {
        return NULL;
      }

      $msg1 = "Alloc fetch done: number to process: " . count($portalUserNames) . "; start processing at: $this->batchStartAt";
      $this->collectCronLog($msg1, 'd');

      \Drupal::state()->set('access_affinitygroup.allocationsRun', TRUE);
      return $portalUserNames;
    }
    catch (\Exception $e) {
      $this->collectCronLog("Exception in allocations batch setup: " . $e->getMessage(), 'err');
      \Drupal::state()->set('access_affinitygroup.allocationsRun', FALSE);
      return NULL;
    }
  }

  /**
   * Returns the API key for the allocations API.
   */
  private function getApiKey() {
    $path = \Drupal::service('file_system')->realpath("private://") . '/.keys/secrets.json';
    if (!file_exists($path)) {

      $this->collectCronLog("Unable to get allocations api key.", 'err');
      return NULL;
    }
    $secretsData = json_decode(file_get_contents($path), TRUE);
    return ($secretsData['ramps_api_key']);
  }

  /**
   * Calls the allocations api to get a list of all the users in the system.
   *
   * This gets just a list of the names. Later, we get details of each user
   * as-needed.
   */
  private function getApiPortalUserNamesJson() {

    try {
      $requestUrl = 'https://allocations-api.access-ci.org/identity/profiles/v1/people?with_allocations=1';
      $apiKey = $this->getApiKey();
      $requestOpts = [
        'headers' => [
          'XA-API-KEY' => $apiKey,
          'XA-REQUESTER' => 'MATCH',
          'Content-Type' => 'application/json',
        ],
        'curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1],
      ];

      $client = new Client();
      $response = $client->request('GET', $requestUrl, $requestOpts);
      $responseJson = Json::decode((string) $response->getBody());
    }
    catch (\Exception $e) {
      $this->collectCronLog('Allocations api import: ' . $e->getMessage(), 'err');
      return FALSE;
    }

    $this->collectCronLog("Initial API import: total received: " . count($responseJson), 'i', TRUE);
    return $responseJson;
  }

  /**
   * For cron job only, makes array of slice of portal names.
   *
   * Number requested. Sort list returned from api becasue their list
   * changes order. 0-based startIndex.
   */
  private function getApiPortalUserNamesForCron($startIndex, $sliceSize) {

    $portalUserNames = [];
    $responseJson = $this->getApiPortalUserNamesJson();
    $incomingCount = 0;
    $processedCount = 0;

    try {
      foreach ($responseJson as $aUser) {
        $incomingCount++;
        if ($aUser['isSuspended'] || $aUser['isArchived']) {
          continue;
        }
        $processedCount++;
        $portalUserNames[] = $aUser['username'];
      }

      sort($portalUserNames);
      $this->collectCronLog("Portal names incoming $incomingCount pr $processedCount puncount " . count($portalUserNames), 'd');
    }
    catch (\Exception $e) {
      $this->collectCronLog('Allocations portal names: ' . $e->getMessage(), 'err');
      return [];
    }

    return array_slice($portalUserNames, $startIndex, $sliceSize);
  }

  /**
   * For manual batch only, makes array of portal names requested.
   */
  private function getApiPortalUserNamesForBatch($startIndex = 0, $processLimit = NULL) {

    $portalUserNames = [];
    $responseJson = $this->getApiPortalUserNamesJson();
    $incomingCount = 0;
    $processedCount = 0;

    try {
      foreach ($responseJson as $aUser) {
        $incomingCount++;
        if ($incomingCount < $startIndex) {
          continue;
        }

        if ($aUser['isSuspended'] || $aUser['isArchived']) {
          continue;
        }
        $processedCount++;
        if ($processLimit != NULL && $processedCount > $processLimit) {
          break;
        }
        $portalUserNames[] = $aUser['username'];
      }
    }
    catch (\Exception $e) {
      $this->collectCronLog('Allocations portal names: ' . $e->getMessage(), 'err');
      return FALSE;
    }
    return $portalUserNames;
  }

  /**
   * This gets called repeatedly by the batch processing.
   */
  public function processChunk($userNames, &$context) {
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = [];
      $context['results']['newUsers'] = 0;
      $context['results']['newCCIds'] = 0;
      $context['results']['totalProcessed'] = 0;
      $context['results']['totalExamined'] = 0;
      $context['results']['logCronErrors'] = [];
    }

    if (!$userNames) {
      return;
    }

    $sandbox = &$context['sandbox'];
    if (!$sandbox) {
      $sandbox['progress'] = 0;
      $sandbox['max'] = count($userNames);
      $sandbox['userNames'] = $userNames;
      $sandbox['batchNum'] = 0;
    }

    $sandbox['batchNum']++;
    $context['message'] = "Processing in batch " . $sandbox['batchNum'];
    $userNameArray = array_splice($sandbox['userNames'], 0, $this->sliceSize);

    $this->allocationsImportWork($userNameArray, $context, $sandbox);

    $context['results']['logCronErrors'] = array_merge($context['results']['logCronErrors'], $this->logCronErrors);

    // Batch stops when finished is < 1.
    $context['finished'] = $sandbox['progress'] / $sandbox['max'];
    return TRUE;
  }

  /**
   * Performs the allocations import work for a batch of users.
   */
  private function allocationsImportWork($userNameArray, &$context = NULL, &$sandbox = NULL) {

    $apiKey = $this->getApiKey();
    $userCount = 0;
    $newUsers = 0;
    $newCCIds = 0;
    $bnum = $this->isCronJob ? "" : $sandbox['batchNum'];
    try {
      // Process each user in the chunk
      // call api to get resources, get user, (add user + add to cc if needed)
      // update user's resources if needed, check if they are members
      // of associated ags.
      $this->collectCronLog("Slice " . $bnum . " start", 'i', TRUE);

      foreach ($userNameArray as $userName) {

        if (!$this->isCronJob) {
          $context['results']['totalExamined']++;
          $sandbox['progress']++;
        }

        $userCount++;
        $this->currentNumber = $userCount;
        try {
          $aUser = $this->allocApiGetResources($userName, $apiKey);

          if (empty($aUser) || count($aUser['resources']) == 0) {
            $this->collectCronLog("!!! " . $bnum . " batch at $userCount NO RESOURCES for $userName", 'd');
            // Once the api is updated, this should be rare.
            continue;
          }
          $context['message'] = "Processing in batch " . $bnum . " $userName";
          $context['results']['totalProcessed']++;

          $this->collectCronLog($bnum . " batch at $userCount PROCESSING $userName", 'd');
          $accessUserName = $userName . '@access-ci.org';
          $userDetails = user_load_by_name($accessUserName);

          // If we already have user, make sure they are all set with the cc id.
          // Check for diffs and update user profile with email, names,
          // citizenship, email, institution.
          $needCCId = TRUE;
          if ($userDetails) {
            $this->userDetailUpdates($userDetails, $aUser);
            $needCCId = needsCCId($userDetails, 'support');
          }

          // Did not have the user, so create it.
          if ($userDetails === FALSE) {
            $userDetails = $this->createAllocUser($accessUserName, $aUser);
            $this->collectCronLog("...creating $userCount: $userName", 'd');
            if ($userDetails) {
              $newUsers++;
              $context['results']['newUsers']++;
            }
          }

          if ($needCCId && $userDetails) {
            // Either new user just created, or existing user missing
            // constant contact id.
            $this->collectCronLog("...need CC id: $userName", 'd');

            if (!$this->batchNoCC) {
              // Add delay before CC API call to help avoid rate limiting.
              // CC allows ~4 requests/second; 300ms gives us headroom.
              usleep(300000);
              if ($this->cronAddToConstantContact($userDetails, $aUser['email'], $aUser['firstName'], $aUser['lastName'])) {
                $newCCIds++;
                $context['results']['newCCIds']++;
              }
            }
          }

          // Find our internal storage ids (node ids) for each of the
          // incoming list of cider names.
          $newCiderRefnums = [];
          foreach ($aUser['resources'] as $cider) {

            // special:  there is not a cider resource for this even though
            // it can be listed on users' allocations list.
            if ($cider['cider_resource_id'] == 'credits.allocations.access-ci.org') {
              continue;
            }

            $query = \Drupal::entityQuery('node');
            $refnum = $query
              ->condition('type', 'access_active_resources_from_cid')
              ->condition('field_access_global_resource_id', $cider['cider_resource_id'])
              ->accessCheck(FALSE)
              ->execute();

            if (empty($refnum)) {
              $this->collectCronLog("Not found in CiDeR active res:  " . $cider['cider_resource_id'], 'd');
            }
            else {
              $newCiderRefnums[] = reset($refnum);
            }
          }

          // Now we have a loaded userDetails whether existing or newly
          // created. Check the user's Resources to see if they still match.
          // now from list of resource_id for this user, check each to see
          // which AG they are associated with. build list of AGs for this user.
          $userCiderRefnums = [];
          $userCiderArray = $userDetails->get('field_cider_resources')->getValue();
          foreach ($userCiderArray as $userCider) {
            $userCiderRefnums[] = $userCider['target_id'];
          }

          // Is incoming list different from what user already has?
          // if so, reset the user list.
          $intersect = array_intersect(array_values($newCiderRefnums), array_values($userCiderRefnums));
          if (count($intersect) !== count($newCiderRefnums) || count($intersect) !== count($userCiderRefnums)) {

            $this->collectCronLog('---updating user ciders; count new: ' . count($newCiderRefnums) . ' was ' . count($userCiderRefnums), 'd');
            $this->updateUserCiderList($userDetails, $newCiderRefnums);
          }
          // Finally, check for AG membership in each AG corresponding
          // to the ciderRefnum.
          // Gather a list of associated affinity groups (unique)
          // The Cider Refs might be associated with multiples AGs;
          // an AG has 0 to many Cider Refs.
          $agNodes = [];
          foreach ($newCiderRefnums as $refnum) {

            $query = \Drupal::entityQuery('node');
            $agNodeIds = $query
              ->condition('type', 'affinity_group')
              ->condition('field_cider_resources', $refnum)
              ->accessCheck(FALSE)
              ->execute();

            foreach ($agNodeIds as $id) {
              if (!in_array($id, $agNodes)) {
                $agNodes[] = $id;
              }
            }
          }
          // and, we add every user to ACCESS Support AG.
          $agNodes[] = $this->accessSupportNodeId;

          // Now we have agNodes, which is a list of all affinity groups
          // having to do with the user's allocations.
          // set membership will add the user to the group unless they
          // previously blocked automembership to the ag by leaving.
          $userBlockedArray = $userDetails->get('field_blocked_ag_tax')->getValue();
          $userBlockedAgTids = [];
          foreach ($userBlockedArray as $userBlock) {
            $userBlockedAgTids[] = $userBlock['target_id'];
          }
          foreach ($agNodes as $agNid) {
            $this->setUserMembership($agNid, $userDetails, $userBlockedAgTids, TRUE);
          }
        }
        catch (\Exception $e) {
          $msg = "Exception for incoming user $userName  " . $e->getMessage();
          if ($agNodes) {
            $msg .= "  AGs: " . implode(',', $agNodes);
          }
          $this->collectCronLog($msg, 'err');
        }
      } // end foreach userName
    }
    catch (\Exception $e) {
      $this->collectCronLog("Exception while processing api results at $userCount " . $e->getMessage(), 'err');
    }

    $this->collectCronLog($context['sandbox']['batchNum'] . ". $newUsers new users;  $newCCIds CC added; $userCount processed.", 'i', TRUE);
  }

  /**
   * Checks userdetail for absent CC id and attempts to add if not there.
   *
   * Updates the user entity in place so the caller has the updated CC ID.
   * Return boolean success.
   */
  private function cronAddToConstantContact(&$u, $uEmail, $firstName, $lastName) {
    $ccId = addUserToConstantContact($uEmail, $firstName, $lastName, TRUE);
    if (empty($ccId)) {
      $this->collectCronLog("Could not add user to Constant Contact:  $uEmail");
      return FALSE;
    }
    else {
      $user_cc_id = [
        'test' => '',
        'openondemand' => '',
        'support' => $ccId,
      ];
      $user_cc_id = json_encode($user_cc_id);
      $u->set('field_constant_contact_id', $user_cc_id);
      $u->save();
      // Reload the user entity so the caller has the updated CC ID.
      // This is critical for the subsequent allocSubscribeToCcList() calls.
      $u = User::load($u->id());
      $this->collectCronLog("Id from Constant Contact:  $uEmail", 'd');
      return TRUE;
    }
  }

  /**
   * Collects severe problems to send as a developer alert email.
   *
   * $logType: err, i, d.  Determines logging category.
   * Category d is not logged unless verboseLogging is true.
   */
  private function collectCronLog($msg, $logType = 'err', $showTime = FALSE) {

    if (!$this->isCronJob) {
      $msg = " B " . $msg;
    }

    if ($showTime) {
      $currentTime = \Drupal::time()->getCurrentTime();
      $msg = date("H:i:s", $currentTime) . ' ' . $msg;
    }
    if ($logType === 'err') {
      $this->logCronErrors[] = $msg;
      \Drupal::logger('cron_affinitygroup')->error($msg);
    }
    elseif ($logType === 'i') {
      \Drupal::logger('cron_affinitygroup')->notice($msg);
    }
    else {
      if ($this->verboseLogging) {
        \Drupal::logger('cron_affinitygroup')->debug($msg);
      }
    }
  }

  /**
   * Sends an email with collected cron errors to site_developer users.
   *
   * $errorList is an array of strings with the collected errors.
   */
  private function emailDevCronLog($errorList) {

    if (empty($errorList) || count($errorList) == 0) {
      return;
    }

    $body = 'ERRORS: ' . implode(' \r\n', $errorList);

    // Truncate to something reasonable to email in case of large error set.
    if (strlen($body) > 3000) {
      $body = substr($body, 0, 3000) . "\r\n...see logs for more.";
    }

    // Send email to Site Developer.
    // allocation_error.
    $policy = 'affinitygroup';
    $policy_subtype = 'allocation_error';
    $role = 'site_developer';
    $site_dev_emails = \Drupal::service('access_misc.usertools')->getEmails([$role], []);
    if (!empty($site_dev_emails)) {
      $variables = [
        'body' => $body,
      ];

      \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $site_dev_emails, $variables);
    }
  }

  /**
   * For import allocations.
   */
  private function createAllocUser($accessUserName, $aUser) {
    try {
      $u = User::create();
      $u->set('status', 1);
      $u->setUsername($accessUserName);
      $u->setEmail($aUser['email']);

      $u->set('field_user_first_name', $aUser['firstName']);
      $u->set('field_user_last_name', $aUser['lastName']);
      $u->set('field_institution', $aUser['organizationName']);
      $citzenships = $this->formatCitizenships($aUser['citizenships']);
      $u->set('field_citizenships', $citzenships);

      // $findAccessOrg = new FindAccessOrg();
      // $accessOrg = $findAccessOrg->get($aUser['organizationId']);
      $accessOrg = $this->findAccessOrg($aUser['organizationId']);
      $u->set('field_access_organization', $accessOrg);

      $u->save();
      $y = $u->id();
      $this->collectCronLog("User created: $accessUserName - " . $aUser['email'] . " ($y)", 'd');
    }
    catch (\Exception $e) {
      $this->collectCronLog("Exception createUser $accessUserName: " . $e->getMessage());
      $u = FALSE;
    }
    return $u;
  }

  /**
   * Compares incoming details and writes changes to user profile.
   *
   * If email or name changed, update in constant contact, if user
   * already has a CC Id.
   * For import allocations.
   */
  private function userDetailUpdates($u, $a) {
    try {
      $needProfileUpdate = FALSE;
      $needCCUpdate = FALSE;
      $log = '';

      if ($a['email'] !== $u->getEmail()) {
        $log .= 'email: ' . $u->getEmail() . ' to ' . $a['email'];
        $u->setEmail($a['email']);
        $needProfileUpdate = TRUE;
        $needCCUpdate = TRUE;
      }

      if ($a['firstName'] !== $u->get('field_user_first_name')->getString()) {
        $log .= ' first: ' . $u->get('field_user_first_name')->getString() . ' to ' . $a['firstName'];
        $u->set('field_user_first_name', $a['firstName']);
        $needProfileUpdate = TRUE;
        $needCCUpdate = TRUE;
      }

      if ($a['lastName'] !== $u->get('field_user_last_name')->getString()) {
        $log .= ' last: ' . $u->get('field_user_last_name')->getString() . ' to ' . $a['lastName'];
        $u->set('field_user_last_name', $a['lastName']);
        $needProfileUpdate = TRUE;
        $needCCUpdate = TRUE;
      }

      // This field will go away once we have the new
      // field_access_organization in place.
      if ($a['organizationName'] !== $u->get('field_institution')->getString()) {
        $log .= ' org: ' . $u->get('field_institution')->getString() . ' to ' . $a['organizationName'];
        $u->set('field_institution', $a['organizationName']);
        $needProfileUpdate = TRUE;
      }

      $citizenships = $this->formatCitizenships($a['citizenships']);
      if ($citizenships !== $u->get('field_citizenships')->getString()) {
        $log .= ' cit: ' . $u->get('field_citizenships')->getString() . ' to ' . $citizenships;
        $u->set('field_citizenships', $citizenships);
        $needProfileUpdate = TRUE;
      }

      // If organization id changed, update the organzation entity
      // refernce field.
      $nid = $u->get('field_access_organization')->getValue();
      if ($nid != NULL) {

        $accessOrg = \Drupal::entityTypeManager()->getStorage('node')->load($nid[0]['target_id']);
        if ($accessOrg != NULL) {
          $accessOrgId = $accessOrg->get('field_organization_id')->getValue()[0]['value'];
        }
      }
      if (!isset($accessOrgId) || $accessOrgId != $a['organizationId']) {
        $accessOrgRef = $this->findAccessOrg($a['organizationId']);
        $u->set('field_access_organization', $accessOrgRef);
        $needProfileUpdate = TRUE;
      }

      if ($needProfileUpdate) {
        if (!$this->batchNoUserDetSave) {
          $u->save();
        }
        $this->collectCronLog('Updating user ' . $a['username'] . ': ' . $log, 'd');
      }
      // If name or email changed, and user already has constant
      // contact account, update there.
      if ($needCCUpdate) {
        $ccIdField = $u->get('field_constant_contact_id')->getValue();
        if (!empty($ccIdField)) {
          $ccIdRaw = $ccIdField[0]['value'];
          if (!empty($ccIdRaw)) {
            $ccIdArray = json_decode($ccIdRaw, TRUE);
            // Log corrupted data where 'support' is not a string.
            if (is_array($ccIdArray) && isset($ccIdArray['support']) && !is_string($ccIdArray['support'])) {
              $this->collectCronLog("Corrupted CC data for user " . $a['username'] . " (uid " . $u->id() . "): 'support' is not a string. Raw value: " . $ccIdRaw, 'err', TRUE);
            }
            $ccId = (is_array($ccIdArray) && isset($ccIdArray['support']) && is_string($ccIdArray['support'])) ? $ccIdArray['support'] : NULL;
            if ($ccId && !$this->batchNoCC && !$this->batchNoUserDetSave) {
              $cca = new ConstantContactApi('support');
              $cca->setSupressErrDisplay(TRUE);
              $cca->updateContact($ccId, $a['firstName'], $a['lastName'], $a['email']);
            }
            $this->collectCronLog('CC Account Update for ' . $a['username'] . ' ' . $a['email'] . ' ' . $ccId, 'd');
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->collectCronLog('Exception in UserDetailUpdates for ' . $a['username'] . ': ' . $e->getMessage(), 'err');
    }
  }

  /**
   * Formats citizenships as a stored on user as single display string.
   *
   * $citJson: citzenships json section from api.
   */
  private function formatCitizenships($citJson) {

    $citizenships = '';
    $initial = TRUE;
    try {
      foreach ($citJson as $x) {
        if (!$initial) {
          $citizenships .= ', ';
        }
        $citizenships .= $x['countryName'];
        $initial = FALSE;
      }
    }
    catch (\Exception $e) {
    }
    return $citizenships;
  }

  /**
   * Find the access organization entity reference for the given access org id.
   *
   * This is the field that is on the user profile.
   */
  private function findAccessOrg($accessOrgId) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'access_organization')
      ->condition('field_organization_id', $accessOrgId)
      ->accessCheck(FALSE)
      ->execute();

    return $query;
  }

  /**
   * Sets user membership for an affinity group via per-user flag.
   *
   * $agNid - AG node id
   * $blocklist - array of taxonomy ids for blocked ags.
   */
  private function setUserMembership($agNid, $userDetails, $blockList, $isJoin) {
    $ag = Node::load($agNid);

    // Load the taxonomy term from the entity reference field "Affinity Group".
    $agTax = $ag->get('field_affinity_group')->entity;

    // If taxonomy not on some new affinity group, fail gracefully.
    if (empty($agTax)) {
      $this->collectCronLog('MISSING taxonomy for affinity group ' . $ag->get('title')->value, 'err');
      return;
    }

    // For joining only, check if ag is on block list.
    if ($isJoin && in_array($agTax->id(), $blockList)) {
      $this->collectCronLog('...user blocked ' . $agTax->id() . ' ' . $ag->get('title')->value, 'd');
      return;
    }

    $flagService = \Drupal::service('flag');
    // Replace by flag machine name.
    $flag = $flagService->getFlagById('affinity_group');

    // Check if already flagged. If not, set the join flag, and
    // call constant contact api to add to corresponding email group.
    $flagStatus = $flagService->getFlagging($flag, $agTax, $userDetails);
    if (!$flagStatus) {

      $flagService->flag($flag, $agTax, $userDetails);

      if (!$this->batchNoCC) {
        $this->allocSubscribeToCcList($agTax->id(), $userDetails);
      }
      $this->collectCronLog("...add member: " . $ag->get('title')->value . ': ' . $userDetails->get('field_user_last_name')->getString(), 'd');
    }
    else {
      // $this->collectCronLog("...already  member: "
      // . $ag->get('title')->value, 'd');
    }
  }

  /**
   * Add user to Constant Contact list for an affinity group.
   */
  private function allocSubscribeToCcList($taxonomyId, $userDetails) {
    $postJSON = makeListMembershipJSON($taxonomyId, $userDetails);
    if (empty($postJSON)) {
      // Only log as debug since the parent function already logged
      // the CC add failure.
      $this->collectCronLog("...skipping CC list add for " . $userDetails->getEmail() . " (no CC ID)", 'd');
    }
    else {
      $cca = new ConstantContactApi('support');
      $cca->setSupressErrDisplay(TRUE);
      $cca->apiCall('/activities/add_list_memberships', $postJSON, 'POST');
    }
  }

  /**
   * Reset user's cider list.
   */
  private function updateUserCiderList($userDetails, $ciderRefnums) {
    $userDetails->set('field_cider_resources', NULL);
    foreach ($ciderRefnums as $refnum) {
      $userDetails->get('field_cider_resources')->appendItem($refnum);
    }
    $userDetails->save();
  }

  /**
   * Retrieve detailed info for one allocations user.
   */
  private function allocApiGetResources($userName, $apiKey) {
    $responseJson = [];
    try {
      $reqUrl = "https://allocations-api.access-ci.org/identity/profiles/v1/people/$userName?resources=1";
      $requestOpts = [
        'headers' => [
          'XA-API-KEY' => $apiKey,
          'XA-REQUESTER' => 'MATCH',
          'Content-Type' => 'application/json',
        ],
        'curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1],
      ];

      $client = new Client();
      $response = $client->request('GET', $reqUrl, $requestOpts);
      $responseJson = Json::decode((string) $response->getBody());
    }
    catch (\Exception $e) {
      $this->collectCronLog("Alloc get resources exeception at $userName:" . $e->getMessage(), 'err');
    }
    return $responseJson;
  }

  /**
   * SYNC Affinity Group members with constant contact lists.
   *
   * Normally, the user joining an affinity group triggers a call to
   * Constant Contact to add the user to the corresponsing CC email list.
   * If CC was not hooked up at the time the user joins the AG, they will
   * not be on the CC list. This function syncs the lists.
   * This can be run on demand from the CC admin form, and it is
   * also run on cron.
   */
  public function syncAgAndCc($agBegin = 0, $agEnd = 1000, $verbose = FALSE) {
    // Get all the Affinity Groups.
    $this->verboseLogging = $verbose;
    $agCount = 0;
    $usersAddedAmt = 0;
    $addAttemptAmt = 0;
    $this->collectCronLog("Sync aff. groups w/ Constant Contact: index $agBegin to $agEnd ", 'i', TRUE);
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'affinity_group')
      ->accessCheck(FALSE)
      ->execute();
    $nodes = Node::loadMultiple($nids);
    $cca = new ConstantContactApi('support');

    foreach ($nodes as $node) {
      $agCount += 1;
      if ($agCount < $agBegin || $agCount > $agEnd) {
        continue;
      }
      $ccContacts = [];
      $agContacts = [];
      $agContactsNoCCid = [];

      $agTitle = $node->getTitle();
      try {
        $this->collectCronLog("Sync $agCount: $agTitle", 'd', TRUE);

        // Get constant contact list id.
        $listId = $node->get('field_list_id')->value;
        if (!$listId || strlen($listId) < 1) {
          $this->collectCronLog("!! No list id for $agTitle", 'd');
          continue;
        }

        // Assemble users belonging to this group (each stored on flag
        // on the associated term).
        $termField = $node->get('field_affinity_group');
        if ($termField->isEmpty() || !$termField->entity) {
          $this->collectCronLog("!! No taxonomy term for $agTitle", 'err');
          continue;
        }
        $userIds = $this->getUserIdsFromFlags($termField->entity);

        // Use direct database query for CC IDs instead of loading
        // each user entity.
        // Process in chunks to avoid database IN clause limits
        // for large groups.
        if (!empty($userIds)) {
          $connection = \Drupal::database();
          $ccResults = [];
          $userIdChunks = array_chunk($userIds, 1000);
          foreach ($userIdChunks as $chunk) {
            $query = $connection->select('user__field_constant_contact_id', 'cc')
              ->fields('cc', ['entity_id', 'field_constant_contact_id_value'])
              ->condition('cc.entity_id', $chunk, 'IN');
            $ccResults += $query->execute()->fetchAllKeyed();
          }

          foreach ($userIds as $uid) {
            if (isset($ccResults[$uid]) && !empty($ccResults[$uid])) {
              $ccIdRaw = $ccResults[$uid];
              $ccIdArray = json_decode($ccIdRaw, TRUE);
              // Log corrupted data where 'support' is not a string.
              if (is_array($ccIdArray) && isset($ccIdArray['support']) && !is_string($ccIdArray['support'])) {
                $this->collectCronLog("Corrupted CC data for user $uid: 'support' is not a string. Raw value: " . $ccIdRaw, 'err', TRUE);
              }
              $ccId = (is_array($ccIdArray) && isset($ccIdArray['support']) && is_string($ccIdArray['support'])) ? $ccIdArray['support'] : NULL;
              // Check to see if it's a good CC Id.
              // preventing attempts to work with an obfuscated CC Id.
              if ($ccId !== NULL && strlen($ccId) == 36) {
                $agContacts[] = $ccId;
              }
              else {
                $agContactsNoCCid[] = $uid;
              }
            }
            else {
              // Users without cc id. might not do anything with this
              // here, not sure yet.
              $agContactsNoCCid[] = $uid;
            }
          }
        }

        $this->collectCronLog("Sync $agCount: users in this AG with no CC id- count : " . count($agContactsNoCCid), 'd', TRUE);

        // Assemble list of users on the cc list. CC API supports
        // up to 500 per page.
        // Delay before first API call to respect rate limits across groups.
        usleep(300000);
        $resp = $cca->apiCall("/contacts?lists=$listId&limit=500&include_count=true");
        if (empty($resp) || empty($resp->contacts)) {
          $this->collectCronLog("Sync: $agTitle CC list response: empty or no contacts.", 'i', TRUE);
          // Not necessarily an error - could be an empty list.
          // Continue to add users.
        }
        else {
          foreach ($resp->contacts as $contact) {
            $ccContacts[] = $contact->contact_id;
          }

          // Loop through any paginated results.
          do {
            try {
              $nextHref = NULL;

              if (!empty($resp->_links->next)) {
                $nextHref = $resp->_links->next->href;
                // Remove extra leading '/v3'.
                $nextHref = substr($nextHref, 3);
              }

              if (!empty($nextHref)) {
                // Delay for API rate limit (300ms = max 3.33 requests/second).
                usleep(300000);
                $resp = $cca->apiCall($nextHref);
                if (!empty($resp->contacts)) {
                  foreach ($resp->contacts as $contact) {
                    $ccContacts[] = $contact->contact_id;
                  }
                }
              }
            }
            catch (\Exception $e) {
              $this->collectCronLog("Sync $agCount: error in links loop " . $e->getMessage(), 'err', TRUE);
            }
          } while (!empty($nextHref));
        }
        $notInCC = array_diff($agContacts, $ccContacts);
        $addAttemptAmt = count($notInCC);
        $this->collectCronLog("Sync $agCount: add " . $addAttemptAmt . ' (' . count($ccContacts) . ' cc; ' . count($agContacts) . " ag) $agTitle", 'i', TRUE);

        // To add users to cc lists, call cc api with chunks.
        $chunkSize = 40;
        if (count($notInCC)) {

          $chunked = array_chunk($notInCC, $chunkSize);
          $chunked = array_values($chunked);

          foreach ($chunked as $oneChunk) {
            $postData = [
              'source' => [
                'contact_ids' => $oneChunk,
              ],
              'list_ids' => [$listId],
            ];

            $postData = json_encode($postData);
            $cca->apiCall('/activities/add_list_memberships', $postData, 'POST');
            $usersAddedAmt += count($oneChunk);
            $this->collectCronLog("Sync $agCount: at $usersAddedAmt", 'd', TRUE);
            // Delay for API rate limit (300ms = max 3.33 requests/second).
            usleep(300000);
          }
        }
      }
      catch (\Exception $e) {
        $this->collectCronLog("Sync $agCount: at $usersAddedAmt while attempting $addAttemptAmt" . $e->getMessage(), 'err', TRUE);
      }
      $this->collectCronLog("Sync $agCount: Completed at $usersAddedAmt", 'd', TRUE);
    } // end for each affinity group node
  }

  /**
   * Sync a single affinity group with Constant Contact.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The affinity group node to sync.
   * @param bool $verbose
   *   Whether to enable verbose logging.
   */
  public function syncSingleAffinityGroup($node, $verbose = FALSE) {
    $this->verboseLogging = $verbose;
    $cca = new ConstantContactApi('support');

    $ccContacts = [];
    $agContacts = [];
    $agContactsNoCCid = [];
    $usersAddedAmt = 0;

    $agTitle = $node->getTitle();
    $this->collectCronLog("Sync: Starting $agTitle", 'i', TRUE);

    // Get constant contact list id.
    $listId = $node->get('field_list_id')->value;
    if (!$listId || strlen($listId) < 1) {
      $this->collectCronLog("!! No list id for $agTitle", 'err', TRUE);
      throw new \Exception("No Constant Contact list ID configured for $agTitle");
    }

    // Assemble users belonging to this group (each stored on flag
    // on the associated term).
    $termField = $node->get('field_affinity_group');
    if ($termField->isEmpty() || !$termField->entity) {
      throw new \Exception("No affinity group taxonomy term found for $agTitle");
    }
    $userIds = $this->getUserIdsFromFlags($termField->entity);
    $this->collectCronLog("Sync: $agTitle has " . count($userIds) . " members", 'i', TRUE);

    // Use direct database query for CC IDs instead of loading each user entity.
    // Process in chunks to avoid database IN clause limits for large groups.
    if (!empty($userIds)) {
      $connection = \Drupal::database();
      $ccResults = [];
      $userIdChunks = array_chunk($userIds, 1000);
      foreach ($userIdChunks as $chunk) {
        $query = $connection->select('user__field_constant_contact_id', 'cc')
          ->fields('cc', ['entity_id', 'field_constant_contact_id_value'])
          ->condition('cc.entity_id', $chunk, 'IN');
        $ccResults += $query->execute()->fetchAllKeyed();
      }

      foreach ($userIds as $uid) {
        if (isset($ccResults[$uid]) && !empty($ccResults[$uid])) {
          $ccIdRaw = $ccResults[$uid];
          $ccIdArray = json_decode($ccIdRaw, TRUE);
          // Log corrupted data where 'support' is not a string.
          if (is_array($ccIdArray) && isset($ccIdArray['support']) && !is_string($ccIdArray['support'])) {
            $this->collectCronLog("Corrupted CC data for user $uid: 'support' is not a string. Raw value: " . $ccIdRaw, 'err', TRUE);
          }
          $ccId = (is_array($ccIdArray) && isset($ccIdArray['support']) && is_string($ccIdArray['support'])) ? $ccIdArray['support'] : NULL;
          // Check to see if it's a good CC Id.
          // preventing attempts to work with an obfuscated CC Id.
          if ($ccId !== NULL && strlen($ccId) == 36) {
            $agContacts[] = $ccId;
          }
          else {
            $agContactsNoCCid[] = $uid;
          }
        }
        else {
          // Users without cc id. might not do anything with this
          // here, not sure yet.
          $agContactsNoCCid[] = $uid;
        }
      }
    }

    $this->collectCronLog("Sync: $agTitle - users with no CC id: " . count($agContactsNoCCid), 'd', TRUE);

    // Assemble list of users on the cc list. CC API supports
    // up to 500 per page.
    // Delay before first API call to respect rate limits.
    usleep(300000);
    $resp = $cca->apiCall("/contacts?lists=$listId&limit=500&include_count=true");
    if (empty($resp) || empty($resp->contacts)) {
      $this->collectCronLog("Sync: $agTitle CC list response: empty or no contacts.", 'i', TRUE);
      // Not necessarily an error - could be an empty list.
    }
    else {
      foreach ($resp->contacts as $contact) {
        $ccContacts[] = $contact->contact_id;
      }

      // Loop through any paginated results.
      do {
        $nextHref = NULL;

        if (!empty($resp->_links->next)) {
          $nextHref = $resp->_links->next->href;
          // Remove extra leading '/v3'.
          $nextHref = substr($nextHref, 3);
        }

        if (!empty($nextHref)) {
          // Delay for API rate limit (300ms = max 3.33 requests/second).
          usleep(300000);
          $resp = $cca->apiCall($nextHref);
          if (!empty($resp->contacts)) {
            foreach ($resp->contacts as $contact) {
              $ccContacts[] = $contact->contact_id;
            }
          }
        }
      } while (!empty($nextHref));
    }

    $this->collectCronLog("Sync: $agTitle - CC list has " . count($ccContacts) . " contacts", 'i', TRUE);

    $notInCC = array_diff($agContacts, $ccContacts);
    $addAttemptAmt = count($notInCC);
    $this->collectCronLog("Sync: $agTitle - adding $addAttemptAmt users to CC list", 'i', TRUE);

    // To add users to cc lists, call cc api with chunks.
    $chunkSize = 40;
    if (count($notInCC)) {
      $chunked = array_chunk($notInCC, $chunkSize);
      $chunked = array_values($chunked);

      foreach ($chunked as $oneChunk) {
        $postData = [
          'source' => [
            'contact_ids' => $oneChunk,
          ],
          'list_ids' => [$listId],
        ];

        $postData = json_encode($postData);
        $cca->apiCall('/activities/add_list_memberships', $postData, 'POST');
        $usersAddedAmt += count($oneChunk);
        $this->collectCronLog("Sync: $agTitle - added $usersAddedAmt of $addAttemptAmt", 'd', TRUE);
        // Delay for API rate limit (300ms = max 3.33 requests/second).
        usleep(300000);
      }
    }

    $this->collectCronLog("Sync: $agTitle - Completed. Added $usersAddedAmt users.", 'i', TRUE);
  }

  /**
   * Returns the user ids that have flagged an affinity group.
   *
   * Term: taxonomy term entity for the affinity group.
   */
  public function getUserIdsFromFlags(EntityInterface $term) {
    // Use direct database query for better performance with large datasets.
    $connection = \Drupal::database();
    $query = $connection->select('flagging', 'f')
      ->fields('f', ['uid'])
      ->condition('f.entity_type', $term->getEntityTypeId())
      ->condition('f.entity_id', $term->id());

    $userIds = $query->execute()->fetchCol();
    return $userIds;
  }

  /**
   * CLEAN OBSOLETE allocations.
   *
   * We need to periodically clean of lists of allocations from users
   * who no longer have any. When we import users and update their allocations,
   * we only get information about active users, which are the users who do have
   * allocations.  We do not want to check for the absense of a user every time
   * we run the import, so we have this utility function which will run less
   * often.
   * Here, we get the active users list, and find users in our database who are
   * not on this list but who do have have lingering allocations
   * on their profile.
   *
   * start/stop - integer index in list of users where to start
   * and stop processing
   */
  public function cleanObsoleteAllocations($indexStart, $indexStop, $verbose = FALSE) {
    $this->verboseLogging = $verbose;
    $this->collectCronLog("Clean Obs: user count $indexStart, $indexStop ", 'i');
    $obsoleteCount = 0;
    try {
      $portalNames = $this->getApiPortalUserNamesForBatch();
      $this->collectCronLog("Clean Obs: portal count " . count($portalNames), 'd');
      if ($portalNames) {

        // Make destination list of emails of users with administrator role.
        $userIds = \Drupal::entityQuery('user')
          ->condition('status', 1)
          ->accessCheck(FALSE)
          ->execute();
        $userCount = count($userIds);
        $this->collectCronLog("Clean Obs: user count $userCount ", 'd');

        $index = 0;
        foreach ($userIds as $userId) {

          $index++;
          if ($index < $indexStart) {
            continue;
          }
          if ($index > $indexStop) {
            break;
          }

          $user = User::load($userId);

          $userCiderArray = $user->get('field_cider_resources')->getValue();

          // If ciders are listed on user's account, see if they are
          // on the allocations api list
          // of active users.
          if (count($userCiderArray) > 0) {

            // Username without @access-ci.org is the portal name.
            $accountSub = str_replace("@access-ci.org", "", $user->getAccountName());
            if (strlen($accountSub) < strlen($user->getAccountName())) {
              $found = array_search($accountSub, $portalNames);
              if (empty($found)) {
                $this->updateUserCiderList($user, []);
                $this->collectCronLog("Reset obsolete allocation list on " . $user->getAccountName() . " id $userId (at $index)", 'd');
                $obsoleteCount++;
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->collectCronLog("Clean Obs: " . $e->getMessage(), 'err');
    }
    $this->collectCronLog("Finished Cleaning Obsolete: reset allocations on $obsoleteCount users", 'i');
  }

}
