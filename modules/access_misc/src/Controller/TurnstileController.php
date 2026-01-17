<?php

namespace Drupal\access_misc\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\access_misc\Services\TurnstileService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for Turnstile bot protection routes.
 */
class TurnstileController extends ControllerBase {

  /**
   * The Turnstile service.
   *
   * @var \Drupal\access_misc\Services\TurnstileService
   */
  protected $turnstileService;

  /**
   * Constructs a TurnstileController object.
   *
   * @param \Drupal\access_misc\Services\TurnstileService $turnstile_service
   *   The Turnstile service.
   */
  public function __construct(TurnstileService $turnstile_service) {
    $this->turnstileService = $turnstile_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('access_misc.turnstile_service')
    );
  }

  /**
   * Display the Turnstile challenge form.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function challenge(Request $request) {
    $return_url = $request->query->get('return', '/');
    $site_key = $this->turnstileService->getTurnstileSecret('TURNSTILE_SITE_KEY');
    $error = $request->query->has('error') ? 'Verification failed. Please try again.' : '';

    // Sanitize return URL.
    if (!preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $return_url)) {
      $return_url = '/';
    }

    // Calculate base path for "skip" link.
    $base_path = strtok($return_url, '?');
    $show_skip_link = ($base_path !== $return_url);

    $html = $this->turnstileService->getChallengePageHtml($site_key, $return_url, $error, $show_skip_link, $base_path);

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * Verify a Turnstile token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function verify(Request $request) {
    $token = $request->query->get('token', '');
    $return_url = $request->query->get('return', '/');
    $secret_key = $this->turnstileService->getTurnstileSecret('TURNSTILE_SECRET_KEY');

    // Sanitize return URL.
    if (!preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $return_url)) {
      $return_url = '/';
    }

    if (!empty($token) && !empty($secret_key)) {
      $result = $this->turnstileService->verifyTurnstileToken($token, $secret_key, $request->getClientIp());

      if ($result['success']) {
        $response = new Response('', 302);
        $response->headers->set('Location', $return_url);

        $cookie_value = hash('sha256', $secret_key . $request->getClientIp());
        $secure = $request->isSecure();

        setcookie(
          TurnstileService::COOKIE_NAME,
          $cookie_value,
          [
            'expires' => time() + TurnstileService::COOKIE_DURATION,
            'path' => '/',
            'secure' => $secure,
            'httponly' => TRUE,
            'samesite' => 'Lax',
          ]
        );

        return $response;
      }
    }

    // Verification failed.
    $challenge_url = '/turnstile-challenge?return=' . urlencode($return_url) . '&error=1';
    $response = new Response('', 302);
    $response->headers->set('Location', $challenge_url);
    return $response;
  }

}
