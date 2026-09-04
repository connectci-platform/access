<?php

namespace Drupal\access;

/**
 * Field option values a domain hides from the current editor.
 *
 * The bi-weekly-digest share option on field_choose_where_to_share_this is
 * only meaningful on the ACCESS Support domain, so every other domain's form
 * removes it. That option-hiding rule is shared by access_events (event
 * series) and access_news (news articles) — both the form alter that unsets
 * the option and the entity presave strip guard that restores a stored value
 * the editor's form context hid (see D8-2824; the same class of bug was
 * already fixed for event series affiliations, see
 * _access_events_hidden_series_options()). Centralizing it here is what keeps
 * the two content types from drifting: before this, access_news had no
 * presave guard at all, so an off-domain save silently dropped the flag.
 */
class HiddenFieldOptions {

  /**
   * The domain ID digest sharing is scoped to (the ACCESS Support domain).
   */
  public const DIGEST_DOMAIN_ID = 'amp_cyberinfrastructure_org';

  /**
   * The active domain ID, or NULL if the domain module/negotiator is absent.
   *
   * Looked up fresh on every call, NOT injected at construction: the active
   * domain can change within a request (e.g. a batch/queue worker iterating
   * domains, or a kernel test switching context between assertions), and a
   * constructor-cached reference would go stale. Programmatic contexts
   * (cron, drush) with no active domain resolve to NULL here, which
   * getHiddenDigestOptions() below treats as "hide" — the maximally
   * protective default for a context with nothing to show a form.
   */
  public function getActiveDomainId(): ?string {
    if (!\Drupal::hasService('domain.negotiator')) {
      return NULL;
    }
    $activeDomain = \Drupal::service('domain.negotiator')->getActiveDomain();
    return $activeDomain ? $activeDomain->id() : NULL;
  }

  /**
   * Digest share option hidden outside the ACCESS Support domain.
   *
   * @return array<string, list<string>>
   *   Hidden option values keyed by field name, in the shape both the
   *   eventseries and news form alters/presave guards expect.
   */
  public function getHiddenDigestOptions(): array {
    if ($this->getActiveDomainId() === self::DIGEST_DOMAIN_ID) {
      return [];
    }
    return ['field_choose_where_to_share_this' => ['in_the_access_support_bi_weekly_digest']];
  }

}
