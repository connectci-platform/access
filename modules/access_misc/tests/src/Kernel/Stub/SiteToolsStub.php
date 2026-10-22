<?php

namespace Drupal\Tests\access_misc\Kernel\Stub;

/**
 * Minimal stand-in for access_misc.sitetools in kernel tests.
 *
 * Reports no current domain or program, so access_misc's submission
 * insert/update hooks do nothing.
 */
class SiteToolsStub {

  /**
   * Stub: no current domain.
   */
  public function getDomain() {
    return NULL;
  }

  /**
   * Stub: no current domain id.
   */
  public function getDomainId() {
    return NULL;
  }

  /**
   * Stub: no current program.
   */
  public function getProgram() {
    return NULL;
  }

  /**
   * Stub: no manager role.
   */
  public function getManagerRole($program) {
    return NULL;
  }

}
