<?php

namespace Drupal\access_affinitygroup;

/**
 * Pure decision helpers for Constant Contact integration.
 *
 * Kept free of Drupal services and curl so the logic that drives
 * send-success detection and environment selection is unit-testable.
 */
class CcLogic {

  /**
   * TRUE when an HTTP status code represents a successful CC response.
   *
   * Some CC success responses have an empty body (e.g. the schedule POST),
   * so success MUST be judged by status code, not body presence.
   */
  public static function httpSucceeded(int $httpCode): bool {
    return $httpCode >= 200 && $httpCode < 300;
  }

  /**
   * TRUE only on the live Pantheon environment.
   *
   * The single source of truth for "may this environment rotate the shared,
   * rotating live CC refresh tokens?". Used by env resolution and by both
   * automatic-rotation guards (cron refresh, 401 auto-refresh) so the
   * root-cause rule lives in one tested place instead of duplicated string
   * literals.
   *
   * @param string|null $pantheonEnv
   *   Value of getenv('PANTHEON_ENVIRONMENT') (FALSE/null when unset).
   */
  public static function isLiveEnv(?string $pantheonEnv): bool {
    return $pantheonEnv === 'live';
  }

  /**
   * Resolve which CC token environment a Pantheon environment may use.
   *
   * The live 'support'/'openondemand' token slots hold rotating refresh
   * tokens shared via the database. Only the 'live' Pantheon environment
   * may operate on them; every other environment falls back to 'test' so a
   * pulled-down DB cannot rotate (and thereby invalidate) live's tokens.
   * An explicit forced override always wins (admin token tooling).
   *
   * @param string|null $pantheonEnv
   *   Value of getenv('PANTHEON_ENVIRONMENT') (FALSE/null when unset).
   * @param string $domainClass
   *   Current domain name run through Html::getClass().
   * @param string|null $forced
   *   The access_affinitygroup.forcedTokenSettings state value, if any.
   */
  public static function resolveCcEnvironment(?string $pantheonEnv, string $domainClass, ?string $forced): string {
    if (!empty($forced)) {
      return $forced;
    }
    if (!self::isLiveEnv($pantheonEnv)) {
      return 'test';
    }
    return $domainClass === 'open-ondemand' ? 'openondemand' : 'support';
  }

  /**
   * TRUE when a refresh should be skipped because another process already did it.
   *
   * Constant Contact rotates refresh tokens, so a caller that is about to refresh
   * a token which has changed in storage since it last read it must adopt the
   * newly-stored token instead of refreshing again (a second rotation would
   * invalidate the token the other process just stored).
   *
   * @param string|null $usedRefreshToken
   *   The refresh token this caller read before contending for the lock.
   * @param string|null $currentStoredRefreshToken
   *   The refresh token currently in state, re-read after acquiring the lock.
   */
  public static function shouldSkipRefresh(?string $usedRefreshToken, ?string $currentStoredRefreshToken): bool {
    // Nothing stored, or unchanged: this caller should perform the refresh.
    if ($currentStoredRefreshToken === NULL || $currentStoredRefreshToken === '') {
      return FALSE;
    }
    return $currentStoredRefreshToken !== $usedRefreshToken;
  }

}
