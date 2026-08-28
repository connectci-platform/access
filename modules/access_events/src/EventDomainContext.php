<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\domain\DomainInterface;
use Drupal\domain\DomainNegotiatorInterface;

/**
 * Runs a callback as if the request had arrived on an event's own domain.
 *
 * Event notifications are rendered wherever the work happens to be triggered —
 * a drush cron run, an API request on some other domain — but they must read
 * as if they came from the domain the event belongs to. Two independent
 * mechanisms decide that, and only one of them is the domain negotiator:
 *
 * 1. TRANSPORT / mail routing. hook_mailer_init() implementations pick an SMTP
 *    transport from the ACTIVE DOMAIN, so \Drupal::service('domain.negotiator')
 *    has to be pointed at the event's domain. This is what the existing
 *    domain-switch call sites in this codebase (ccmnet_cron(),
 *    access_misc's registrant digest) are doing.
 *
 * 2. LINK HOSTS. Absolute URLs — [registrant:delete_url],
 *    [registrant:edit_url], [eventinstance:url], all of which resolve through
 *    $entity->toUrl(...)->setAbsolute(TRUE) in
 *    recurring_events_registration.tokens.inc — take their scheme/host/port
 *    from the ROUTER REQUEST CONTEXT, not from the negotiator.
 *    DomainNegotiator::setActiveDomain() only assigns a property; it does not
 *    touch \Drupal\Core\Routing\UrlGenerator::doGenerate(), which builds an
 *    absolute URL as
 *    $context->getScheme() . '://' . $context->getHost() . $port .
 *    $context->getBaseUrl() . $path.
 *    So switching the active domain alone leaves every link pointing at the
 *    enqueueing request's host. This service switches both.
 *
 * domain_source's outbound path processor does not cover this either: it only
 * rewrites a link when the routed entity carries a domain source field, which
 * eventinstance and registrant do not. (It also memoises the active domain on
 * the service instance in DomainSourcePathProcessor::getActiveDomain(), so it
 * would not observe a mid-request switch even if they did.)
 *
 * KNOWN GAP — per-domain CONFIG overrides are deliberately not switched here.
 * domain_config's DomainConfigOverrider::loadOverrides() memoises results in a
 * function-static $lookups keyed only on config names + language, never on the
 * domain, and ConfigFactory caches the resolved objects on top of that. Once
 * system.site has been read in a PHP process there is no supported way to make
 * it re-resolve against a different domain. In practice that means a queued
 * notification's From address still comes from whichever domain's system.site
 * was resolved first in the process. That is a pre-existing, separate defect
 * (it predates and outlives this service) and fixing it needs a change in
 * contrib, not a workaround here.
 *
 * The request stack is intentionally left alone. Only the request CONTEXT
 * governs absolute URL generation, and pushing a synthetic Request would
 * change what \Drupal::request() returns for every unrelated consumer running
 * inside the callback (route match, session, client IP) for no benefit.
 */
class EventDomainContext {

  /**
   * The domain_access field name shared by eventseries and eventinstance.
   */
  public const FIELD = 'domain_access';

  public function __construct(
    protected RequestContext $requestContext,
    protected ?DomainNegotiatorInterface $negotiator = NULL,
  ) {}

  /**
   * Runs a callback in the domain context of an entity's domain_access value.
   *
   * When the entity has no usable domain the callback runs unchanged, which
   * keeps the pre-existing "use the current request's host" behaviour for
   * content that was never assigned a domain.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose domain_access field names the domain to switch to.
   * @param callable $callback
   *   The callback to run.
   *
   * @return mixed
   *   Whatever the callback returns.
   */
  public function forEntity(FieldableEntityInterface $entity, callable $callback): mixed {
    $domain = $this->resolveDomain($entity);
    if ($domain === NULL) {
      return $callback();
    }
    return $this->forDomain($domain, $callback);
  }

  /**
   * Runs a callback with a given domain active, restoring the prior state.
   *
   * @param \Drupal\domain\DomainInterface $domain
   *   The domain to make active for the duration of the callback.
   * @param callable $callback
   *   The callback to run.
   *
   * @return mixed
   *   Whatever the callback returns.
   */
  public function forDomain(DomainInterface $domain, callable $callback): mixed {
    $context = $this->requestContext;
    $originalDomain = $this->negotiator?->getActiveDomain();
    $original = [
      'scheme' => $context->getScheme(),
      'host' => $context->getHost(),
      'httpPort' => $context->getHttpPort(),
      'httpsPort' => $context->getHttpsPort(),
      'completeBaseUrl' => $context->getCompleteBaseUrl(),
    ];

    // Restore in a finally: a token replacement or an entity load inside the
    // callback can throw, and leaving a foreign domain active would send every
    // later link and every later mail in this process to the wrong site — a
    // far worse failure than the one being fixed.
    try {
      $this->negotiator?->setActiveDomain($domain);
      $this->applyToRequestContext($domain);
      return $callback();
    }
    finally {
      $context->setScheme($original['scheme']);
      $context->setHost($original['host']);
      $context->setHttpPort($original['httpPort']);
      $context->setHttpsPort($original['httpsPort']);
      $context->setCompleteBaseUrl($original['completeBaseUrl']);
      // DomainNegotiator exposes no way to return to "nothing negotiated yet",
      // so when there was no active domain to begin with the negotiator keeps
      // the one set here. The request context — the part that decides link
      // hosts — is always restored exactly.
      if ($originalDomain instanceof DomainInterface) {
        $this->negotiator->setActiveDomain($originalDomain);
      }
    }
  }

  /**
   * Returns the hostname of an entity's domain, or NULL when it has none.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to read domain_access from.
   *
   * @return string|null
   *   The hostname, or NULL when no domain is assigned.
   */
  public function resolveHostname(FieldableEntityInterface $entity): ?string {
    return $this->resolveDomain($entity)?->getHostname();
  }

  /**
   * Returns the first domain referenced by an entity's domain_access field.
   *
   * An event can be assigned to several domains; the notification can only
   * carry one host, so the first is used. This mirrors what
   * PostSurvey::getEventDomain() did before it moved here.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to read domain_access from.
   *
   * @return \Drupal\domain\DomainInterface|null
   *   The domain, or NULL when none is assigned or resolvable.
   */
  public function resolveDomain(FieldableEntityInterface $entity): ?DomainInterface {
    if (!$entity->hasField(self::FIELD)) {
      return NULL;
    }
    $items = $entity->get(self::FIELD);
    // Kernel tests that do not install the domain module stub domain_access as
    // a plain string field. Checking the item list type rather than assuming a
    // reference field keeps this a no-op there instead of a fatal.
    if (!$items instanceof EntityReferenceFieldItemListInterface || $items->isEmpty()) {
      return NULL;
    }
    foreach ($items->referencedEntities() as $referenced) {
      if ($referenced instanceof DomainInterface) {
        return $referenced;
      }
    }
    return NULL;
  }

  /**
   * Points the router request context at a domain's scheme, host and port.
   */
  private function applyToRequestContext(DomainInterface $domain): void {
    // getScheme(FALSE) yields a bare 'http'/'https' with no '://' suffix.
    $scheme = $domain->getScheme(FALSE);
    // A domain hostname may carry a non-standard port (common on local and CI
    // hostnames). UrlGenerator only emits a port when it differs from the
    // scheme's default, so parking it on the matching setter and leaving the
    // other at its default reproduces the domain's own URLs exactly.
    $hostname = $domain->getHostname();
    $port = NULL;
    if (str_contains($hostname, ':')) {
      [$hostname, $port] = explode(':', $hostname, 2);
    }

    $context = $this->requestContext;
    $context->setScheme($scheme);
    $context->setHost($hostname);
    $context->setHttpPort($scheme === 'http' && $port !== NULL ? (int) $port : 80);
    $context->setHttpsPort($scheme === 'https' && $port !== NULL ? (int) $port : 443);
    // Domain::getPath() is scheme + hostname + the global $base_path, so this
    // stays correct for a subdirectory install. rtrim matches how
    // DomainSourcePathProcessor builds its own base_url override.
    $context->setCompleteBaseUrl(rtrim($domain->getPath(), '/'));
  }

}
