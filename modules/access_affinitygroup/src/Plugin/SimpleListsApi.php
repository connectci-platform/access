<?php

namespace Drupal\access_affinitygroup\Plugin;

/**
 * Simplelists API wrapper for affinity group mailing list management.
 */
class SimpleListsApi {

  /**
   * The Simplelists domain.
   *
   * @var string
   */
  private $domain;

  /**
   * The Simplelists API key.
   *
   * @var string
   */
  private $apiKey;
  const  MAX_ADDRESS_LEN = 60;

  /**
   * Constructs the SimpleListsApi object and initializes the API key.
   */
  public function __construct() {
    try {
      $errmsg = NULL;
      $this->apiKey = '';

      $this->domain = 'lists.connectci.org';
      $this->apiKey = \Drupal::service('key.repository')->getKey('simplelists')->getKeyValue();

      if (empty($this->apiKey)) {
        $errmsg = 'Simplelists key missing.';
      }
      $this->apiKey .= ':';
    }
    catch (\Exception $e) {
      $errmsg = 'Simplelists error: ' . $e->getMessage();
    }
    if ($errmsg <> NULL) {
      \Drupal::logger('access_affinitygroup')->error($errmsg);

      // Send email to Site Developer.
      // simplelist_error.
      $policy = 'affinitygroup';
      $policy_subtype = 'simplelist_error';
      $role = 'site_developer';
      $site_dev_emails = \Drupal::service('access_misc.usertools')->getEmails([$role], []);
      $variables = [
        'errmsg' => $errmsg,
      ];

      \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $site_dev_emails, $variables);

      \Drupal::messenger()->addMessage($errmsg);
    }
  }

  /**
   * Returns the Simplelists domain.
   */
  public function getDomain() {
    return $this->domain;
  }

  /**
   * Returns curl obj for the given operation.
   *
   * $op: POST/GET/PUT/DELETE.
   */
  private function makeCurl($op, $urlsub, $params = '') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.simplelists.com/api/2/' . $urlsub);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $op);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    return $ch;
  }

  /**
   * Creates the list slug@domain.
   *
   * AgTitle is used in the group email footer with the unsubscribe link.
   * Return boolean status. Fills $msg.
   */
  public function createList($listSlug, $agTitle, &$msg) {
    try {
      // moderate=6: only allow users of list restrict_post_lists to post.
      $params = "name=$listSlug&subject_prefix=$listSlug:&moderate=6&archive_enabled=true&restrict_post_lists=$listSlug";
      $params .= "&message_footer_html=<p>_ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _<br><br>You are receiving this message through the $agTitle Affinity Group. To unsubscribe from this email list, <a href=\$UNSUBSCRIBE>click here</a>. To manage your Affinity Group memberships, please use the ACCESS Support Portal or your Connect CI portal.</p>";

      $ch = $this->makeCurl('POST', 'lists/', $params);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse["is_error"]) {
        $msg = $deResponse["message"];
        return FALSE;
      }

      $msg = "Mailing list created successfully.";
      return TRUE;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Check if user is subscribed to list and if so get their type of digest.
   *
   * @return string|bool
   *   Returns 'none' if not subscribed, 'daily' for digest mode,
   *   'full' for all emails.
   */
  public function getUserListStatus($listName, $userEmail, &$msg) {
    // Gets list info.
    try {
      $ch = $this->makeCurl('GET', "lists/$listName/");
      $list_info = curl_exec($ch);
      curl_close($ch);
      $list_info = json_decode($list_info, TRUE);
      $subscribers = $list_info['contacts'] ?? [];
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $subscribers = NULL;
    }

    // Get user simplelist id.
    $userId = $this->getUserIdFromEmail($userEmail, $msg);

    // Check if user is a member of this list.
    $isMember = FALSE;
    $user_subscribed = 'none';

    if ($subscribers != NULL) {
      foreach ($subscribers as $sub) {
        if ($sub == $userId) {
          // Find user simplelist id in list.
          $isMember = TRUE;
          break;
        }
      }
    }

    // If user is a member, get their email digest status
    // Note: We use email-level digest (applies to ALL lists) due
    // to SimpleLists API limitations.
    if ($isMember) {
      try {
        $ch = $this->makeCurl('GET', 'emails/' . urlencode($userEmail) . '/');
        $response = curl_exec($ch);
        curl_close($ch);
        $emailData = json_decode($response, TRUE);

        if (isset($emailData['is_error']) && $emailData['is_error']) {
          $msg = $emailData['message'] ?? 'Error getting email data.';
        }
        else {
          // Email digest setting applies to ALL lists for this email.
          $digest = $emailData['digest'] ?? FALSE;
          $user_subscribed = $digest ? 'daily' : 'full';
        }
      }
      catch (\Exception $e) {
        $msg = $e->getMessage();
        \Drupal::logger('access_affinitygroup')->error("getUserListStatus: Exception: $msg");
      }
    }

    return $user_subscribed;
  }

  /**
   * Check if user is subscribed to list and if so get their type of digest.
   *
   * Note: Due to SimpleLists API limitations, this sets digest
   * preference at the
   * email level (applies to ALL lists), not per-list level. SimpleLists API v2
   * does not provide a working way to update per-list digest settings.
   *
   * @param string $listName
   *   The list name/slug (used to verify membership).
   * @param string $userEmail
   *   The user's email address.
   * @param int $digest
   *   The digest setting (0 = full, 1 = daily).
   * @param string &$msg
   *   Reference to a message string for error reporting.
   * @param int|null $membershipId
   *   Unused - kept for backwards compatibility.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function setUserDigest($listName, $userEmail, $digest, &$msg, $membershipId = NULL) {
    try {
      // Get email ID for this user
      // Note: We don't verify list subscription since digest is set
      // at email level (applies to all lists)
      $ch = $this->makeCurl('GET', 'emails/' . urlencode($userEmail) . '/');
      $response = curl_exec($ch);
      curl_close($ch);

      $emailData = json_decode($response, TRUE);
      if (isset($emailData['is_error']) && $emailData['is_error']) {
        $msg = $emailData['message'] ?? 'Error getting email information.';
        return FALSE;
      }

      $emailId = $emailData['id'] ?? NULL;
      if ($emailId === NULL) {
        $msg = 'Unable to find email ID.';
        return FALSE;
      }

      // Update digest setting at email level (applies to all lists)
      // SimpleLists expects integer: 1 for digest, 0 for full.
      $digestValue = $digest == 1 ? 1 : 0;
      $params = "digest=$digestValue";
      $ch = $this->makeCurl('PUT', "emails/$emailId/", $params);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse['is_error']) {
        $msg = $deResponse['message'] ?? 'Error updating digest setting.';
        return FALSE;
      }

      $msg = 'Your email delivery preference has been updated. Note: This setting applies to all your mailing lists.';
      return TRUE;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Adds user, and adds to listName if not null, returns new id or null.
   *
   * $listName is slug.
   */
  public function addUser($uid, $userEmail, $firstName, $lastName, $listName, &$msg) {

    try {
      $userEmail = urlencode($userEmail);
      $firstName = urlencode(substr($firstName, 0, 30));
      $lastName = urlencode(substr($lastName, 0, 30));

      $params = "emails=$userEmail&firstname=$firstName&surname=$lastName&notes=uid$uid";
      if (!empty($listName)) {
        $listName = urlencode($listName);
        $params .= "&lists=$listName";
        \Drupal::logger('access_affinitygroup')->notice("addUser: Adding user with email to list: $listName");
      }
      $ch = $this->makeCurl('POST', 'contacts/', $params);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse['is_error']) {
        $msg = $deResponse['message'];
        \Drupal::logger('access_affinitygroup')->error("addUser: SimpleLists API error: $msg");
        return NULL;
      }

      $contactId = $deResponse['id'];
      \Drupal::logger('access_affinitygroup')->notice("addUser: Contact created with ID: $contactId");

      // Check if user was actually added to the list.
      if (!empty($listName)) {
        $checkMsg = '';
        $listMemberId = $this->getListMemberId(urldecode($userEmail), urldecode($listName), $checkMsg);
        if ($listMemberId === NULL) {
          \Drupal::logger('access_affinitygroup')->warning("addUser: Contact created but not added to list. Attempting to add via membership endpoint.");
          // Contact was created but not added to list, try adding
          // via membership endpoint.
          if (!$this->updateUserToList($contactId, urldecode($listName), $msg)) {
            $msg = "Contact created but could not be added to list: $msg";
            \Drupal::logger('access_affinitygroup')->error("addUser: Failed to add contact to list via membership endpoint: $msg");
            return NULL;
          }
        }
      }

      $msg = 'You have been successfully subscribed to the mailing list.';
      return $contactId;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      \Drupal::logger('access_affinitygroup')->error("addUser: Exception: $msg");
      return NULL;
    }
  }

  /**
   * Returns the SimpleLists contact ID for a given email, or NULL if not found.
   *
   * @param string $userEmail
   *   The user's email address.
   * @param string &$msg
   *   Reference to a message string for error reporting.
   *
   * @return string|null
   *   The contact ID, or NULL if not found or on error.
   */
  public function getUserIdFromEmail($userEmail, &$msg) {
    if (empty($userEmail)) {
      $msg = 'No user email provided to getUserIdFromEmail.';
      \Drupal::logger('access_affinitygroup')->error($msg);
      return NULL;
    }

    try {
      $urlEnd = 'emails/' . urlencode($userEmail) . '/';
      $ch = $this->makeCurl('GET', $urlEnd);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if ($deResponse === NULL) {
        $msg = 'SimpleLists API returned invalid JSON or empty response: ' . var_export($response, TRUE);
        \Drupal::logger('access_affinitygroup')->error($msg);
        return NULL;
      }
      if (!empty($deResponse['is_error'])) {
        $msg = $deResponse['message'] ?? 'Unknown error from SimpleLists API.';
        return NULL;
      }
      if (empty($deResponse['contact'])) {
        $msg = 'SimpleLists API response missing contact ID for email: ' . $userEmail;
        \Drupal::logger('access_affinitygroup')->error($msg);
        return NULL;
      }

      return $deResponse['contact'];
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      \Drupal::logger('access_affinitygroup')->error($msg);
      return NULL;
    }
  }

  /**
   * With a user email, get listnames for that user.
   *
   * Set listNames if user found, and return simplelist user contact id.
   */
  public function getUserListNames($userEmail, &$listNames, &$msg) {

    try {
      $listNames = [];
      $slContactId = $this->getUserIdFromEmail($userEmail, $msg);
      if ($slContactId != NULL) {
        // Have the user id, so now find what lists they already have.
        $ch = $this->makeCurl('GET', 'contacts/' . $slContactId . '/');
        $response = curl_exec($ch);
        curl_close($ch);

        $deResponse = json_decode($response, TRUE);
        // Sl  found the email object but not the use contact, so
        // call that a no.
        if (isset($deResponse['is_error']) && $deResponse['is_error']) {
          $msg = $deResponse['message'];
          return NULL;
        }

        $listArray = $deResponse['lists'];
        foreach ($listArray as $listObj) {
          $listNames[] = $listObj['list'];
        }
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return NULL;
    }
    return $slContactId;
  }

  /**
   * Returns list membership Id for a user/list relation.
   *
   * This is different from the user id.
   * Or NULL if not found.
   *
   * $userEmail: user's email address
   * $listName: list address slug.
   */
  public function getListMemberId($userEmail, $listName, &$msg) {
    try {
      $slContactId = $this->getUserIdFromEmail($userEmail, $msg);
      if ($slContactId != NULL) {
        $ch = $this->makeCurl('GET', 'contacts/' . $slContactId . '/');
        $response = curl_exec($ch);
        curl_close($ch);
        $deResponse = json_decode($response, TRUE);
        if (isset($deResponse['is_error']) && $deResponse['is_error']) {
          $msg = $deResponse['message'];
          return NULL;
        }
        $listArray = $deResponse['lists'];

        foreach ($listArray as $listObj) {
          if ($listObj['list'] == $listName) {
            return $listObj['id'];
          }
        }
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return NULL;
    }
    return NULL;
  }

  /**
   * Updates a user's list membership in Simplelists.
   *
   * @param string $simplelistsId
   *   The user's contact id in simplelists account.
   * @param string $listName
   *   The slug part of the email list address.
   * @param string $msg
   *   Set with message.
   *
   * @return bool
   *   Boolean status.
   */
  public function updateUserToList($simplelistsId, $listName, &$msg) {

    try {
      $params = "contact=$simplelistsId&list=$listName";
      $ch = $this->makeCurl('POST', 'membership/', $params);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse['is_error']) {
        $msg = $deResponse['message'];
        return FALSE;
      }

      $msg = 'You have been successfully subscribed to the mailing list.';
      return TRUE;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Deletes a Simplelists mailing list by name.
   */
  public function deleteList($listName, &$msg) {
    $msg = '';
    $urlEnd = "lists/$listName/";
    $ch = $this->makeCurl('DELETE', $urlEnd);
    $response = curl_exec($ch);
    curl_close($ch);
    $deResponse = json_decode($response, TRUE);
    if (isset($deResponse['is_error']) && $deResponse['is_error']) {
      $msg = $deResponse['message'];
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Remove user from a list.
   *
   * Due to SimpleLists API v2 limitations (returns NULL membership IDs), we use
   * a workaround: when removing a user's only subscription, we move them to a
   * placeholder "no-delivery" list instead of deleting the membership. This
   * effectively unsubscribes them from all active communications.
   *
   * Note: The "no-delivery" list must exist in SimpleLists for this to work.
   *
   * @param string $userEmail
   *   The user's email address.
   * @param string $listName
   *   The list name/slug.
   * @param string &$msg
   *   Reference to a message string for error reporting.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function removeUserFromList($userEmail, $listName, &$msg) {

    try {
      // First, check if user is in the list by querying the contact.
      $userId = $this->getUserIdFromEmail($userEmail, $msg);
      if ($userId === NULL) {
        $msg = "User is not found in SimpleLists.";
        \Drupal::logger('access_affinitygroup')->warning("removeUserFromList: User $userEmail not found in SimpleLists");
        return FALSE;
      }

      // Get contact info to see current lists.
      $ch = $this->makeCurl('GET', "contacts/$userId/");
      $contact = curl_exec($ch);
      curl_close($ch);
      $contact = json_decode($contact, TRUE);

      if (isset($contact['is_error']) && $contact['is_error']) {
        $msg = $contact['message'] ?? 'Error getting contact information.';
        \Drupal::logger('access_affinitygroup')->error("removeUserFromList: SimpleLists API error: $msg");
        return FALSE;
      }

      $contact_list = $contact['lists'] ?? [];
      $listMemberId = NULL;
      $isSubscribed = FALSE;
      $remainingLists = [];

      // Check if subscribed and build list of remaining lists
      // (excluding the one to remove)
      foreach ($contact_list as $list) {
        if ($list['list'] == $listName) {
          $isSubscribed = TRUE;
          $listMemberId = $list['id'] ?? NULL;
        }
        else {
          // Keep this list (not the one we're removing)
          $remainingLists[] = $list['list'];
        }
      }

      if (!$isSubscribed) {
        $msg = "User is not subscribed to the list '$listName'.";
        \Drupal::logger('access_affinitygroup')->warning("removeUserFromList: User $userEmail not subscribed to $listName");
        return FALSE;
      }

      // Determine which method to use based on what's available.
      $useMethod = NULL;

      // Method 1: DELETE membership (if we have a valid ID)
      if ($listMemberId !== NULL) {
        $urlEnd = "membership/$listMemberId/";
        $ch = $this->makeCurl('DELETE', $urlEnd);
        $response = curl_exec($ch);
        curl_close($ch);

        $deResponse = json_decode($response, TRUE);
        if (!isset($deResponse['is_error']) || !$deResponse['is_error']) {
          $msg = 'You have been successfully unsubscribed from the mailing list.';
          return TRUE;
        }
      }

      // Method 2: Update contact's lists array.
      if (count($remainingLists) > 0) {
        // User has multiple lists - just update to remaining lists.
        $params = "lists=" . implode(',', $remainingLists);
        $ch = $this->makeCurl('PUT', "contacts/$userId/", $params);
        $response = curl_exec($ch);
        curl_close($ch);

        $deResponse = json_decode($response, TRUE);
        if (isset($deResponse['is_error']) && $deResponse['is_error']) {
          $msg = $deResponse['message'] ?? 'Error removing user from list.';
          \Drupal::logger('access_affinitygroup')->error("removeUserFromList: Method 2 failed: $msg");
          return FALSE;
        }
        $useMethod = 'contact_put';
      }
      else {
        // User's only list - move them to the "no-delivery" placeholder list.
        $params = "lists=no-delivery";
        $ch = $this->makeCurl('PUT', "contacts/$userId/", $params);
        $response = curl_exec($ch);
        curl_close($ch);

        $deResponse = json_decode($response, TRUE);
        if (isset($deResponse['is_error']) && $deResponse['is_error']) {
          $msg = $deResponse['message'] ?? 'Error removing user from list.';
          \Drupal::logger('access_affinitygroup')->error("removeUserFromList: Failed to move user to no-delivery list: $msg");
          return FALSE;
        }
        $useMethod = 'contact_put';
      }

      // Verify the list was actually removed (only for Method 2 - contact PUT)
      if ($useMethod == 'contact_put') {
        $ch = $this->makeCurl('GET', "contacts/$userId/");
        $verifyContact = curl_exec($ch);
        curl_close($ch);
        $verifyContact = json_decode($verifyContact, TRUE);
        $verifyLists = $verifyContact['lists'] ?? [];

        $stillSubscribed = FALSE;
        foreach ($verifyLists as $list) {
          if ($list['list'] == $listName) {
            $stillSubscribed = TRUE;
            break;
          }
        }

        if ($stillSubscribed) {
          $msg = "Could not remove you from the list. Please contact support for assistance.";
          \Drupal::logger('access_affinitygroup')->error("removeUserFromList: Verification failed - user still subscribed to $listName");
          return FALSE;
        }

        $msg = 'You have been successfully unsubscribed from the mailing list.';
        return TRUE;
      }

      // Should not reach here.
      $msg = 'An unexpected error occurred.';
      \Drupal::logger('access_affinitygroup')->error("removeUserFromList: Reached unexpected code path");
      return FALSE;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      \Drupal::logger('access_affinitygroup')->error("removeUserFromList: Exception: $msg");
      return FALSE;
    }
  }

  /**
   * Makes mailgun email list address from slug for the list.
   *
   * Return full email address, and replace slug with (potentially)
   * fixed-up slug.
   */
  public function makeListAddress(&$slug) {
    // Replace whitespace a -.
    $slug = preg_replace('/\s+/', '-', strtolower(trim($slug)));
    $maxSlugLen = self::MAX_ADDRESS_LEN - strlen($this->domain);
    $slug = substr($slug, 0, $maxSlugLen - 1);
    return ($slug . '@' . $this->domain);
  }

  /**
   * Returns NULL if no change attempted because original address not found.
   *
   * Return true if change is successful or if change not necessary.
   * Return false if there was a problem with attempted address change.
   * $msg set only if return is false.
   */
  public function updateUserEmailAddress($oldEmail, $newEmail, &$msg) {

    // If our slist account has this email in a contact, response is slist
    // email object with email id.
    try {
      $ch = $this->makeCurl('GET', 'emails/' . urlencode($oldEmail) . '/');
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse['is_error']) {
        return NULL;
      }

      // Replace the email address in the email obj with new email.
      $params = "email=" . urlencode($newEmail);
      $emailId = $deResponse['id'];
      $ch = $this->makeCurl('PUT', "emails/$emailId/", $params);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse['is_error']) {
        $msg = $deResponse["message"];
        return FALSE;
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return FALSE;
    }
    return TRUE;
  }

}
