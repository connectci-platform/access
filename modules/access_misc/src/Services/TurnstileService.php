<?php

namespace Drupal\access_misc\Services;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Service for Turnstile bot protection.
 */
class TurnstileService {

  const COOKIE_NAME = 'STYXKEY_turnstile_verified';
  const COOKIE_DURATION = 86400;

  /**
   * Check if the request should be protected by Turnstile.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   A response if the request should be intercepted, NULL otherwise.
   */
  public function checkRequest(Request $request) {
    $env = getenv('PANTHEON_ENVIRONMENT');
    $enable_turnstile = ($env === 'live') || getenv('TURNSTILE_ENABLED');

    if (!$enable_turnstile) {
      return NULL;
    }

    $uri = $request->getRequestUri();

    // Handle Turnstile verification endpoint.
    if (strpos($uri, '/turnstile-verify') === 0) {
      return $this->handleVerifyEndpoint($request);
    }

    // Handle Turnstile challenge page.
    if (strpos($uri, '/turnstile-challenge') === 0) {
      return $this->serveChallengeForm($request);
    }

    // Check faceted search requests.
    $query = $request->query->all();
    if (isset($query['f']) && is_array($query['f']) && count($query['f']) > 0) {
      return $this->checkFacetedRequest($request);
    }

    return NULL;
  }

  /**
   * Handle the Turnstile verification endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function handleVerifyEndpoint(Request $request) {
    $token = $request->query->get('token', '');
    $return_url = $request->query->get('return', '/');
    $secret_key = $this->getTurnstileSecret('TURNSTILE_SECRET_KEY');

    // Sanitize return URL.
    if (!preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $return_url)) {
      $return_url = '/';
    }

    if (!empty($token) && !empty($secret_key)) {
      $result = $this->verifyTurnstileToken($token, $secret_key, $request->getClientIp());

      if ($result['success']) {
        $response = new Response('', 302);
        $response->headers->set('Location', $return_url);

        $cookie_value = hash('sha256', $secret_key . $request->getClientIp());
        $secure = $request->isSecure();
        $cookie = Cookie::create(
          self::COOKIE_NAME,
          $cookie_value,
          time() + self::COOKIE_DURATION,
          '/',
          NULL,
          $secure,
          TRUE,
          FALSE,
          Cookie::SAMESITE_LAX
        );
        $response->headers->setCookie($cookie);

        return $response;
      }
    }

    // Verification failed.
    $challenge_url = '/turnstile-challenge?return=' . urlencode($return_url) . '&error=1';
    $response = new Response('', 302);
    $response->headers->set('Location', $challenge_url);
    return $response;
  }

  /**
   * Serve the Turnstile challenge form.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function serveChallengeForm(Request $request) {
    $return_url = $request->query->get('return', '/');
    $site_key = $this->getTurnstileSecret('TURNSTILE_SITE_KEY');
    $error = $request->query->has('error') ? 'Verification failed. Please try again.' : '';

    // Sanitize return URL.
    if (!preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $return_url)) {
      $return_url = '/';
    }

    // Calculate base path for "skip" link.
    $base_path = strtok($return_url, '?');
    $show_skip_link = ($base_path !== $return_url);

    $html = $this->getChallengePageHtml($site_key, $return_url, $error, $show_skip_link, $base_path);

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * Check a faceted search request for bot protection.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   A response if the request should be blocked, NULL otherwise.
   */
  protected function checkFacetedRequest(Request $request) {
    $query = $request->query->all();
    $facet_count = isset($query['f']) && is_array($query['f']) ? count($query['f']) : 0;

    if ($facet_count === 0) {
      return NULL;
    }

    $user_agent = $request->headers->get('User-Agent', '');

    // Skip verification for logged-in users.
    $is_logged_in = FALSE;
    foreach ($request->cookies->all() as $cookie_name => $cookie_value) {
      if (strpos($cookie_name, 'SESS') === 0 || strpos($cookie_name, 'SSESS') === 0) {
        $is_logged_in = TRUE;
        break;
      }
    }

    // Skip verification for AJAX requests.
    $is_ajax = $request->query->has('_drupal_ajax') || $request->headers->has('X-Requested-With');

    if ($is_ajax || $is_logged_in) {
      return NULL;
    }

    // First line of defense: block known bots.
    if ($this->isKnownBot($user_agent)) {
      error_log('Blocked known bot faceted request: ' . $request->getRequestUri() . ' | UA: ' . $user_agent);
      return new Response('Access denied.', 403);
    }

    // Second line of defense: Turnstile verification.
    $turnstile_secret = $this->getTurnstileSecret('TURNSTILE_SECRET_KEY');

    $cookie_valid = FALSE;
    if ($request->cookies->has(self::COOKIE_NAME) && !empty($turnstile_secret)) {
      $expected_hash = hash('sha256', $turnstile_secret . $request->getClientIp());
      $cookie_valid = hash_equals($expected_hash, $request->cookies->get(self::COOKIE_NAME));
    }

    if (!$cookie_valid && !empty($turnstile_secret)) {
      $challenge_url = '/turnstile-challenge?return=' . urlencode($request->getRequestUri());
      $response = new Response('', 302);
      $response->headers->set('Location', $challenge_url);
      return $response;
    }

    // Fallback: block multiple facets if Turnstile not configured.
    if (empty($turnstile_secret) && $facet_count >= 2) {
      $html = '<!DOCTYPE html>
<html>
<head>
    <title>Service Temporarily Unavailable</title>
</head>
<body>
    <h1>Service Temporarily Unavailable</h1>
    <p>Please use fewer filters or try again later.</p>
    <p><a href="/">Return to the homepage</a></p>
</body>
</html>';
      $response = new Response($html, 503);
      $response->headers->set('Retry-After', '60');
      return $response;
    }

    return NULL;
  }

  /**
   * Get Turnstile secrets from file or environment.
   *
   * @param string $name
   *   The secret name.
   *
   * @return string
   *   The secret value.
   */
  protected function getTurnstileSecret($name) {
    static $secrets = NULL;

    if ($secrets === NULL) {
      $possible_paths = [
        'sites/default/files/private/.keys/secrets.json',
        __DIR__ . '/../../../../../sites/default/files/private/.keys/secrets.json',
        '/files/private/.keys/secrets.json',
      ];

      $secrets = [];
      foreach ($possible_paths as $path) {
        if (file_exists($path)) {
          $raw = file_get_contents($path);
          $secrets = json_decode($raw, TRUE) ?: [];
          break;
        }
      }
    }

    if (isset($secrets[$name])) {
      return $secrets[$name];
    }

    return getenv($name) ?: '';
  }

  /**
   * Check if the user agent is a known bot.
   *
   * @param string $user_agent
   *   The user agent string.
   *
   * @return bool
   *   TRUE if this is a known bot.
   */
  protected function isKnownBot($user_agent) {
    $known_bots = [
      'bot', 'Bot', 'BOT', 'crawler', 'Crawler', 'spider', 'Spider',
      'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot', 'BLEXBot',
      'YandexBot', 'Googlebot', 'bingbot', 'Baiduspider', 'Sogou', 'Exabot',
      'facebot', 'ia_archiver', 'Screaming Frog', 'python', 'Python',
      'Go-http-client', 'Java/', 'wget', 'curl', 'libwww', 'lwp-trivial',
      'httrack', 'nutch', 'msnbot', 'Discordbot', 'WhatsApp', 'Twitterbot',
      'facebookexternalhit', 'LinkedInBot', 'Slackbot', 'Telegram', 'Signal',
      'DataForSeoBot', 'SeznamBot', 'BingPreview', 'PageSpeed', 'Lighthouse',
      'Chrome-Lighthouse', 'HeadlessChrome', 'PhantomJS', 'SlimerJS',
      'CensysInspect', 'NetcraftSurveyAgent', 'masscan', 'nmap',
    ];

    foreach ($known_bots as $bot) {
      if (stripos($user_agent, $bot) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Verify a Turnstile token with Cloudflare.
   *
   * @param string $token
   *   The Turnstile token.
   * @param string $secret_key
   *   The secret key.
   * @param string $remote_ip
   *   The remote IP address.
   *
   * @return array
   *   The verification result with 'success' key.
   */
  protected function verifyTurnstileToken($token, $secret_key, $remote_ip) {
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $secret_key,
        'response' => $token,
        'remoteip' => $remote_ip,
      ]),
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
      $result = json_decode($response, TRUE);
      return $result ?: ['success' => FALSE];
    }

    return ['success' => FALSE];
  }

  /**
   * Get the challenge page HTML.
   *
   * @param string $site_key
   *   The Turnstile site key.
   * @param string $return_url
   *   The return URL.
   * @param string $error
   *   The error message.
   * @param bool $show_skip_link
   *   Whether to show the skip link.
   * @param string $base_path
   *   The base path for the skip link.
   *
   * @return string
   *   The HTML content.
   */
  protected function getChallengePageHtml($site_key, $return_url, $error, $show_skip_link, $base_path) {
    $site_key_html = htmlspecialchars($site_key);
    $return_url_json = json_encode($return_url);
    $error_html = $error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $skip_link_html = $show_skip_link ? '<a href="' . htmlspecialchars($base_path) . '" class="skip-link">Continue without filters &rarr;</a>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify You're Human - ACCESS</title>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #f5f5f5;
      margin: 0;
      padding: 20px;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .container {
      background: white;
      padding: 40px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      max-width: 450px;
      width: 100%;
      text-align: center;
    }
    h1 { margin: 0 0 10px 0; color: #333; font-size: 24px; }
    p { color: #666; margin: 0 0 24px 0; line-height: 1.5; }
    .error { background: #fee; color: #c00; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .cf-turnstile { display: flex; justify-content: center; margin-bottom: 20px; }
    button {
      background: #0073e6;
      color: white;
      border: none;
      padding: 12px 32px;
      font-size: 16px;
      border-radius: 4px;
      cursor: pointer;
      transition: background 0.2s;
    }
    button:hover { background: #005bb5; }
    button:disabled { background: #ccc; cursor: not-allowed; }
    .skip-link { display: block; margin-top: 20px; color: #666; text-decoration: none; font-size: 14px; }
    .skip-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Quick Verification</h1>
    <p>To help protect our site from automated traffic, please complete this quick verification.</p>
    $error_html
    <div id="error-msg" class="error" style="display:none;"></div>
    <div class="cf-turnstile" data-sitekey="$site_key_html" data-callback="onTurnstileSuccess"></div>
    <button type="button" id="submit-btn" onclick="submitVerification()" disabled>Continue</button>
    <script>
      var turnstileToken = null;
      var returnUrl = $return_url_json;

      function onTurnstileSuccess(token) {
        turnstileToken = token;
        document.getElementById("submit-btn").disabled = false;
      }

      function submitVerification() {
        if (!turnstileToken) {
          document.getElementById("error-msg").textContent = "Please complete the verification first.";
          document.getElementById("error-msg").style.display = "block";
          return;
        }
        var verifyUrl = "/turnstile-verify?token=" + encodeURIComponent(turnstileToken) + "&return=" + encodeURIComponent(returnUrl);
        window.location.href = verifyUrl;
      }
    </script>
    $skip_link_html
  </div>
</body>
</html>
HTML;
  }

}
