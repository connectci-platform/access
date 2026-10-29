<?php

/**
 * @file
 * Stands in for the Pantheon platform's pantheon_get_secret() in tests.
 *
 * Values come from $GLOBALS['access_test_pantheon_secrets'].
 */

if (!function_exists('pantheon_get_secret')) {

  /**
   * Returns a test Pantheon secret, or NULL if it does not exist.
   */
  function pantheon_get_secret(string $name): ?string {
    return $GLOBALS['access_test_pantheon_secrets'][$name] ?? NULL;
  }

}
