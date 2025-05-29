<?php

namespace Drupal\access_affinitygroup\Plugin;

/**
 *
 */
class SimpleListsApi {

  private $domain;
  private $apiKey;
  const  MAX_ADDRESS_LEN = 60;

  /**
   *
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
   *
   */
  public function getDomain() {
    return $this->domain;
  }

  /**
   * Returns curl obj
   *  $op: POST/GET/PUT/DELETE
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
   * Create the list slug@domain
   *  agTitle is used in the group email footer with the unsubscribe link.
   *  Return boolean status. Fills $msg.
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

      $msg = "List created.";
      return TRUE;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Check if user is subscribed to list and if so get their type of digest.
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

    // If user is not a member of the list, return false.
    $isMember = 'none';

    $user_subscribed = FALSE;
    if ($subscribers != NULL) {
      foreach ($subscribers as $sub) {
        if ($sub == $userId) {
          // Find user simplelist id in list.
          $isMember = TRUE;
          break;
        }
      }
    }

    // If user is a member, get their email digest status.
    if ($isMember) {
      try {
        // Gets Contact.
        $ch = $this->makeCurl('GET', "contacts/$userId/");
        $contact = curl_exec($ch);
        curl_close($ch);
        $contact = json_decode($contact, TRUE);
        $contact_list = $contact['lists'] ?? [];
        foreach ($contact_list as $list) {
          if ($list['list'] == $listName) {
            // Digest is either 1 for daily digest or else send right away.
            $user_subscribed = $list['digest'] === 1 ? 'daily' : 'full';
            break;
          }
        }
      }
      catch (\Exception $e) {
        $msg = $e->getMessage();
        $contact = NULL;
      }
    }

    return $user_subscribed;
  }

  /**
   * Check if user is subscribed to list and if so get their type of digest.
   */
  public function setUserDigest($listName, $userEmail, $digest, &$msg) {
    // Get user simplelist id.
    $userId = $this->getUserIdFromEmail($userEmail, $msg);

    try {
      // Gets Contact.
      $ch = $this->makeCurl('GET', "contacts/$userId/");
      $contact = curl_exec($ch);
      curl_close($ch);
      $contact = json_decode($contact, TRUE);
      $contact_list = $contact['lists'];
      foreach ($contact_list as $list) {
        if ($list['list'] == $listName) {
          $membershipId = $list['id'];
          break;
        }
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $membershipId = NULL;
    }

    if ($membershipId) {
      try {
        // Update Membership digest settings.
        $params = "digest=$digest";
        $ch = $this->makeCurl('PUT', "membership/$membershipId/", $params);
        $response = curl_exec($ch);
        curl_close($ch);
      }
      catch (\Exception $e) {
        $msg = $e->getMessage();
      }

      try {
        // Update Membership digest settings.
        $ch = $this->makeCurl('GET', "membership/$membershipId/");
        $membership = curl_exec($ch);
        curl_close($ch);
      }
      catch (\Exception $e) {
        $msg = $e->getMessage();
      }
    }

  }

  /**
   * Add user, and add to listName if not null. returns new id or null.
   * $listName is slug.
   */
  public function addUser($uid, $userEmail, $firstName, $lastName, $listName, &$msg) {

    try {
      $userEmail = urlencode($userEmail);
      $firstName = urlencode(substr($firstName, 0, 30));
      $lastName = urlencode(substr($lastName, 0, 30));

      $params = "emails=$userEmail&firstname=$firstName&surname=$lastName&notes=uid$uid";
      if (!empty($listName)) {
        $params .= "&lists=$listName";
      }
      $ch = $this->makeCurl('POST', 'contacts/', $params);
      $response = curl_exec($ch);
      curl_close($ch);

      $deResponse = json_decode($response, TRUE);
      if (isset($deResponse['is_error']) && $deResponse['is_error']) {
        $msg = $deResponse['message'];
        return NULL;
      }
      $msg = 'User added.';
      return $deResponse['id'];
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
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
        // Sl  found the email object but not the use contact, so call that a no.
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
   * Return list membership Id for a user/list relation
   * This is different from the user id.
   * Or NULL if not found
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
   * UpdateUserToList.
   *
   * @param [type] $simplelistsId:
   *   the user's contact id in simplelists account.
   * @param [type] $listName:
   *   the slug part of the email list address.
   * @param [type] $msg
   *   set with message.
   *
   * @return boolean status
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
      $msg = 'User added to list.';
      return TRUE;
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return TRUE;
    }
  }

  /**
   *
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
   *
   */
  public function removeUserFromList($userEmail, $listName, &$msg) {

    try {
      $listMemberId = $this->getListMemberId($userEmail, $listName, $msg);
      if ($listMemberId != NULL) {
        $urlEnd = "membership/$listMemberId/";
        $ch = $this->makeCurl('DELETE', $urlEnd);
        curl_exec($ch);
        curl_close($ch);
        return TRUE;
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Make mailgun email list address from slug (short name)
   * email address for the list.
   * Return full email address, and replace slug with (potentially) fixed-up slug.
   */
  public function makeListAddress(&$slug) {
    // Replace whitespace a -.
    $slug = preg_replace('/\s+/', '-', strtolower(trim($slug)));
    $maxSlugLen = self::MAX_ADDRESS_LEN - strlen($this->domain);
    $slug = substr($slug, 0, $maxSlugLen - 1);
    return ($slug . '@' . $this->domain);
  }

  /**
   * Return NULL is no change attempted because because orignal address not found.
   * Return true if change is successful or if change not necessary
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
