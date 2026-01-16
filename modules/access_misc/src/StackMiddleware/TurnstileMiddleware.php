<?php

namespace Drupal\access_misc\StackMiddleware;

use Drupal\access_misc\Services\TurnstileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Turnstile bot protection middleware.
 *
 * Runs before Drupal bootstrap to minimize server load from bot traffic.
 */
class TurnstileMiddleware implements HttpKernelInterface {

  /**
   * The wrapped HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The Turnstile service.
   *
   * @var \Drupal\access_misc\Services\TurnstileService
   */
  protected $turnstileService;

  /**
   * Constructs a TurnstileMiddleware object.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The decorated kernel.
   * @param \Drupal\access_misc\Services\TurnstileService $turnstile_service
   *   The Turnstile service.
   */
  public function __construct(HttpKernelInterface $http_kernel, TurnstileService $turnstile_service) {
    $this->httpKernel = $http_kernel;
    $this->turnstileService = $turnstile_service;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    // Check if Turnstile protection should run.
    $response = $this->turnstileService->checkRequest($request);
    
    if ($response !== NULL) {
      return $response;
    }

    // Continue with normal request handling.
    return $this->httpKernel->handle($request, $type, $catch);
  }

}
