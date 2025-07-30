<?php

namespace Drupal\access_affinitygroup\Plugin;

/**
 * Make Constant Contact api call.
 */
class ConstantContactApi {

  /**
   * Return constantcontact.settings config.
   */
  private $configSettings;

  /**
   * Return access_token.
   */
  private $accessToken;

  /**
   * Return refresh_token.
   */
  private $refreshToken;

  /**
   * Return clientId.
   */
  private $clientId;

  /**
   * Return clientSecret.
   */
  private $clientSecret;

  private $httpResponseCode;
  private $errorMessage;
  /**
   * If true, log but do not display err to user.
   */
  private $supressErrDisplay;

  /**
   * Function to sort the curl headers.
   * Sets the clientId and the clientSecret. If they are not present, sets to empty.
   */
  public function __construct() {
    try {
      $config_factory = \Drupal::configFactory();
      $this->configSettings = $config_factory->getEditable('access_affinitygroup.settings');
      $this->accessToken = $this->configSettings->get('access_token');
      $this->refreshToken = \Drupal::state()->get('access_affinitygroup.refresh_token');


      $cc_key = \Drupal::service('key.repository')->getKey('constant_contact_client_id')->getKeyValue();
      if (empty($cc_key)) {
        \Drupal::logger('access_affinitygroup')->error('Constant Contact: client id not in repository.');
      }
      else {
        $cc_key = urlencode(trim($cc_key));
      }
      $this->clientId = $cc_key;

      $key_secret = \Drupal::service('key.repository')->getKey('constant_contact_client_secret')->getKeyValue();
      if (empty($key_secret)) {
        \Drupal::logger('access_affinitygroup')->error('Constant Contact: client secret not in repository.');
      }
      else {
        $key_secret = urlencode(trim($key_secret));
      }

      $this->clientSecret = $key_secret;
      $this->supressErrDisplay = FALSE;

      if (empty($this->clientSecret) || empty($this->clientId)) {
        $policy = 'affinitygroup';
        $policy_subtype = 'cc_error';
        $role = 'site_developer';
        $site_dev_emails = \Drupal::service('access_misc.usertools')->getEmails([$role], []);
        $set_email = explode(',', $set_email);
        $message = t('Constant Contact: client id or secret not set.');
        $variables = [
          'message' => $message,
        ];

        \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $site_dev_emails, $variables);
      }
    }
    catch (\Exception $e) {
      $policy = 'affinitygroup';
      $policy_subtype = 'cc_error';
      $role = 'site_developer';
      $site_dev_emails = \Drupal::service('access_misc.usertools')->getEmails([$role], []);
      $set_email = explode(',', $set_email);

      \Drupal::logger('access_affinitygroup')->notice('Exception in constantContactApi constructor: ' . $e->getMessage());

      $message = t('Exception in constantContactApi constructor: ') . $e->getMessage();
      $variables = [
        'message' => $message,
      ];
      \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $site_dev_emails, $variables);
    }
  }

  /**
   *
   */
  public function setSupressErrDisplay($v) {
    $this->supressErrDisplay = $v;
  }

  /**
   * @param  $redirectURI
   *   - URL Encoded Redirect URI
   * @param  $clientId
   *   - API Key
   * @param  $scope
   *   - URL encoded, plus sign delimited list of scopes that your
   *   application requires. The 'offline_access' scope needed to request a
   *   refresh token is added by default.
   * @param  $state
   *   - Arbitrary string value(s) to verify response and preserve
   *   application state
   * @return string - Full Authorization URL
   */
  public function getAuthorizationURL($clientId, $redirectURI, $scope, $state) {
    // Create authorization URL.
    $baseURL = "https://authz.constantcontact.com/oauth2/default/v1/authorize";
    $authURL = $baseURL . "?client_id=" . $clientId . "&scope=" . $scope . "+offline_access&response_type=code&state=" . $state . "&redirect_uri=" . $redirectURI;

    return $authURL;
  }

  /**
   * Initialize Constant Contact Token.
   */
  public function initializeToken($code) {
    try {
      $clientId = $this->clientId;
      $clientSecret = $this->clientSecret;
      $host = \Drupal::request()->getSchemeAndHttpHost();
      $redirectURI = urlencode("$host/admin/services/constantcontact-token");

      $returned_token = $this->getAccessToken($redirectURI, $clientId, $clientSecret, $code);
      $returned_token = json_decode($returned_token);

      if (isset($returned_token->error)) {
        \Drupal::logger('access_affinitygroup')->notice("cc init token err set: " . $returned_token->error_description);
      }

      if ($returned_token && !isset($returned_token->error)) {
        $this->setAccessToken($returned_token->access_token);
        $this->setRefreshToken($returned_token->refresh_token);
        \Drupal::logger('access_affinitygroup')->notice("Constant Contact: new access_token and refresh_token stored");
        \Drupal::messenger()->addMessage("Constant Contact: new access_token and refresh_token stored");
      }
      else {
        $this->apiError($returned_token->error, $returned_token->error_description);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('access_affinitygroup')->notice("cc init token exception: " . $e->getMessage());
    }
  }

  /**
   * Refresh Constant Contact Token and set new ones.
   */
  public function newToken() {
    try {
      $refreshToken = $this->refreshToken;

      $clientId = $this->clientId;
      $clientSecret = $this->clientSecret;
      // Use cURL to get a new access token and refresh token.
      $ch = curl_init();

      // Define base URL.
      $base = 'https://authz.constantcontact.com/oauth2/default/v1/token';

      // Create full request URL.
      $url = $base . '?refresh_token=' . $refreshToken . '&grant_type=refresh_token';
      curl_setopt($ch, CURLOPT_URL, $url);

      // Set authorization header
      // Make string of "API_KEY:SECRET".
      $auth = $clientId . ':' . $clientSecret;
      // Base64 encode it.
      $credentials = base64_encode($auth);
      // Create and set the Authorization header to use the encoded credentials,
      // and set the Content-Type header.
      $authorization = 'Authorization: Basic ' . $credentials;
      curl_setopt($ch, CURLOPT_HTTPHEADER, [$authorization, 'Content-Type: application/x-www-form-urlencoded']);

      // Set method and to expect response.
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

      // Make the call.
      $result = curl_exec($ch);
      $result = json_decode($result);

      $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);

      $host = \Drupal::request()->getSchemeAndHttpHost();

      if (!isset($result->error)) {
        $this->setAccessToken($result->access_token);
        $this->setRefreshToken($result->refresh_token);
        \Drupal::logger('access_affinitygroup')->notice("Constant Contact: new access_token and refresh_token stored $host");
        \Drupal::messenger()->addMessage("Constant Contact: new access_token and refresh_token stored");
      }
      else {
        \Drupal::logger('access_affinitygroup')->notice("New token httpCode: $httpCode");
        \Drupal::logger('access_affinitygroup')->error("New token error; host $host");
        $this->apiError($result->error, $result->error_description);

        $policy = 'affinitygroup';
        $policy_subtype = 'cc_error';
        $role = 'site_developer';
        $site_dev_emails = \Drupal::service('access_misc.usertools')->getEmails([$role], []);
        $set_email = explode(',', $set_email);

        $message = 'New token error at host ' . $host . '. See logs for access_affinitygroup.';
        $variables = [
          'message' => $message,
        ];
        \Drupal::service('access_misc.symfony.mail')->email($policy, $policy_subtype, $site_dev_emails, $variables);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('access_affinitygroup')->notice('Exception in new token: ' . $e->getMessage());
    }
  }

  /**
   * @param $key
   *   - error key.
   * @param $message
   *   - error message.
   */
  public function apiError($key, $message) {

    \Drupal::logger('access_affinitygroup')->error("$key: $message");
    if (!$this->supressErrDisplay) {
      \Drupal::messenger()->addMessage("$key: $message", 'error');
    }
  }

  /**
   * Make api call.
   *
   * @param $endpoint
   *   - end of the URL api call.
   * @param $post_data
   *   - included with $type PUT json encoded.
   * @param $type
   *   - POST or GET, defaults to GET.
   *
   *   Returns result from CC call or NULL upon error.
   *   If error returned from Constant contact:
   *   this function will show the http error
   *   as well and any error message from constant contact.
   *   this->httpResponseCode set
   *   this->errorMessage set to CC error message as well.
   */
  public function apiCall($endpoint, $post_data = NULL, $type = 'GET') {

    $access_token = $this->accessToken;
    // Use cURL to get a new access token and refresh token.
    $ch = curl_init();

    // Create full request URL.
    $url = 'https://api.cc.email/v3' . $endpoint;
    curl_setopt($ch, CURLOPT_URL, $url);

    // Set authorization header
    // Make string of "API_KEY".
    $auth = $access_token;
    // Base64 encode it.
    $credentials = $auth;
    // Create and set the Authorization header to use the encoded credentials,
    // and set the Content-Type header.
    $authorization = 'Authorization: Bearer ' . $credentials;
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$authorization, 'Content-Type: application/json']);

    if ($type != 'GET') {

      curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

      if ($type == 'POST') {
        curl_setopt($ch, CURLOPT_POST, TRUE);
      }
      elseif ($type == 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
      }
      elseif ($type == 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
      }
    }

    // Set method and to expect response.
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    // Make the call.
    $returned_result = curl_exec($ch);
    $result = json_decode($returned_result);

    // Log any http error code.
    $this->httpResponseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $errMsg = getHttpErrMsg($this->httpResponseCode);
    if (!empty($errMsg)) {
      $this->apiError($errMsg, "");
    }
    else {
      if (empty($returned_result)) {
        $this->apiError("Error from Constant Contact: no result", "");
        return NULL;
      }
    }

    // Check for CC error. error_key field is only present in case of error.
    // First special case: when unauth (ie token not refreshed) returned_result is not
    // an array list like rest of error returns.
    if (preg_match('/error_key/', $returned_result, $matches, PREG_OFFSET_CAPTURE)) {

      if (![] === $result) {
        $errmsg = $result->error_message;
        $errkey = $result->error_key;
      }
      else {
        foreach ($result as $error) {
          if (empty($error)) {
            $errmsg = "Unknown ConstantContact Error.";
            $errkey = '-';
          }
          elseif (is_string($error)) {
            $errmsg = $error;
            $errkey = '-';
          }
          else {
            // Normal CC error structure.
            $errmsg = (!property_exists($error, 'error_message') || !isset($error->error_message)) ?
              'ConstantContact Error' : $error->error_message;
            $errkey = (!property_exists($error, 'error_key') ||  !isset($error->error_key)) ?
              '-' : $error->error_key;
          }
        }
      }
      $this->errorMessage = $errmsg;
      $this->apiError($errkey, $errmsg);

      $result = NULL;
    }
    return $result;
  }

  /**
   * Add user to constant contact emails.
   *
   * @string $firstname - contact's first name.
   * @string $lastname - contact's last name.
   * @string $mail - contact's last name.
   *
   * Try to add contact and return new contact id
   * generated by Constant Contact, or existing contact id if
   * user's email is already registered as a contact.
   * Returns 0 if error adding contact
   */
  public function addContact($firstname, $lastname, $mail) {
    $cc_contact = [
      'email_address' => [
        'address' => $mail,
        'permission_to_send' => 'implicit',
      ],
      'first_name' => $firstname,
      'last_name' => $lastname,
      'create_source' => 'Account',
    ];
    $cc_contact = json_encode($cc_contact);

    $this->supressErrDisplay = TRUE;
    $new_contact = $this->apiCall('/contacts', $cc_contact, 'POST');

    // If reposonse is resource conflict, that means the email is already a contact but
    // we somehow unset the Constant Contact person id for this person's record and it
    // needs to be reset in our database.
    // See if the error message contains the Id, which it usually does.
    if ($this->httpResponseCode == 409) {

      // See if the error message contains a contact id. Message will look like this:
      // Validation failed: Email already exists for contact 61d00338-4bd5-11ed-8c0a-fa163ec17584.
      if (preg_match('/.{8}-.{4}-.{4}-.{4}-.{12}/', $this->errorMessage, $match)) {
        return $match;
      }
    }

    if (empty($new_contact)) {
      return 0;
    }
    else {
      return $new_contact->contact_id;
    }
  }

  /**
   * Update user info in constant contact.
   *
   * @string $ccId - contact's constant contact id.
   * @string $firstname - contact's first name.
   * @string $lastname - contact's last name.
   * @string $mail - contact's last name.
   * Return false if error.
   */
  public function updateContact($ccId, $firstname, $lastname, $mail) {
    $contact = [
      'email_address' => [
        'address' => $mail,
        'permission_to_send' => 'implicit',
      ],
      'first_name' => $firstname,
      'last_name' => $lastname,
      'create_source' => 'Account',
      'update_source' => 'Account',
    ];
    $contact = json_encode($contact);
    \Drupal::logger('cron_affinitygroup')->notice("Update CC $ccId, $firstname, $lastname, $mail");

    $this->apiCall("/contacts/$ccId", $contact, 'PUT');
  }

  /**
   * Check if a valid connection can be made to constant contact.
   *
   * @return bool
   */
  public function getConnectionStatus() {

    if (empty($this->accessToken) || empty($this->refreshToken)) {
      return FALSE;
    }
    $this->apiCall('/account/summary');
    return ($this->httpResponseCode == 200);
  }

  /**
   * Save new access_token.
   */
  private function setAccessToken($access_token) {
    $this->accessToken = $access_token;
    $this->configSettings->set('access_token', $access_token);
    $this->configSettings->save();
  }

  /**
   * Save new refresh_token.
   */
  private function setRefreshToken($refresh_token) {
    $this->refreshToken = $refresh_token;
    \Drupal::state()->set('access_affinitygroup.refresh_token', $refresh_token);

  }

  /*
   * This function can be used to exchange an authorization code for an access token.
   * Make this call by passing in the code present when the account owner is redirected back to you.
   * The response will contain an 'access_token' and 'refresh_token'
   */

  /**
   * @param  $redirectURI
   *   - URL Encoded Redirect URI
   * @param  $clientId
   *   - API Key
   * @param  $clientSecret
   *   - API Secret
   * @param  $code
   *   - Authorization Code
   * @return string - JSON String of results
   */
  private function getAccessToken($redirectURI, $clientId, $clientSecret, $code) {
    // Use cURL to get access token and refresh token.
    $ch = curl_init();

    // Define base URL.
    $base = 'https://authz.constantcontact.com/oauth2/default/v1/token';

    // Create full request URL.
    $url = $base . '?code=' . $code . '&redirect_uri=' . $redirectURI . '&grant_type=authorization_code';
    curl_setopt($ch, CURLOPT_URL, $url);

    // Set authorization header
    // Make string of "API_KEY:SECRET".
    $auth = $clientId . ':' . $clientSecret;
    // Base64 encode it.
    $credentials = base64_encode($auth);
    // Create and set the Authorization header to use the encoded credentials, and set the Content-Type header.
    $authorization = 'Authorization: Basic ' . $credentials;
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$authorization, 'Content-Type: application/x-www-form-urlencoded']);

    // Set method and to expect response.
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    // Make the call.
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
  }

}

/**
 * Return error msg, or NULL if not error (in 200's)
 *
 * @todo Perhaps we only check for 401 + just show code num otherwise
 */
function getHttpErrMsg($httpCode) {

  if ($httpCode < 300) {
    return NULL;
  }

  switch ($httpCode) {
    case 400:
      $m = 'Bad request. Either the JSON was malformed or there was a data validation error.';
      break;

    case 401:
      $m = 'The Access Token used is invalid.';
      break;

    case 403:
      $m = 'Forbidden request. You lack the necessary scopes, you lack the necessary user privileges, or the application is deactivated.';
      break;

    case 409:
      $m = 'Conflict. The resource you are creating or updating conflicts with an existing resource.';
      break;

    case 415:
      $m = 'Unsupported Media Type; the payload must be in JSON format, and Content-Type must be application/json';
      break;

    case 500:
      $m = 'There was a problem with our internal service.';
      break;

    case 503:
      $m = 'Our internal service is temporarily unavailable.';
      break;

    default:
      $m = 'HTTP error code: ' . $httpCode;
  }
  return 'Constant Contact: ' . $m;
}
