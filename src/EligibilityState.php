<?php

namespace Drupal\access;

/**
 * Request-scoped holder for the current user's eligibility status.
 *
 * Populated by EligibilityCheckSubscriber on each authenticated main
 * request. Consumed by EligibilityBannerBlock to decide whether to
 * render the banner.
 *
 * Three states:
 * - unknown (initial, also after a transient API failure with no cache hit)
 * - eligible
 * - ineligible (with a reason string from the allocations API)
 *
 * The object is mutable; the last setter call wins. Callers should treat
 * `getReason()` as meaningful only when `isKnown() && !isEligible()`.
 */
class EligibilityState {

  private bool $known = FALSE;
  private bool $eligible = FALSE;
  private ?string $reason = NULL;

  public function isKnown(): bool {
    return $this->known;
  }

  public function isEligible(): bool {
    return $this->eligible;
  }

  public function getReason(): ?string {
    return $this->reason;
  }

  public function setEligible(): void {
    $this->known = TRUE;
    $this->eligible = TRUE;
    $this->reason = NULL;
  }

  public function setIneligible(string $reason): void {
    $this->known = TRUE;
    $this->eligible = FALSE;
    $this->reason = $reason;
  }

}
