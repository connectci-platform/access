<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\QueueWorker;

use Drupal\access_events\EventDomainContext;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\domain\DomainInterface;
use Drupal\recurring_events_registration\Plugin\QueueWorker\EmailNotificationsQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a queued registration notice with the EVENT's domain active.
 *
 * D8-2835: contrib's EmailNotificationsQueueWorker::processItem() just hands
 * the pre-rendered subject/body to mailManager->mail() — it never touches
 * \Drupal::service('domain.negotiator'), so hook_mailer_build()
 * (access_misc_mailer_build()) resolves the From/Reply-To/SMTP transport off
 * whichever domain is active when CRON drains the queue, not the domain the
 * event/registrant belongs to. access_events_queue_info_alter() swaps this
 * class in for the plugin id contrib registers
 * ('recurring_events_registration_email_notifications_queue_worker'), so
 * every queued registration notice — cancel, reinstate, reschedule — is sent
 * through here.
 *
 * The domain id this reads was stamped onto the item's params at ENQUEUE
 * time by access_events_recurring_events_registration_message_params_alter(),
 * off the registrant's own eventinstance (see EventDomainContext::
 * resolveDomain()). Only a scalar id crosses the queue table; this class
 * re-loads the Domain entity at send time and, when it still exists, sends
 * through EventDomainContext::forDomain() so the active domain (and
 * therefore hook_mailer_build()'s routing) matches the event for the
 * duration of the parent's send — then is restored, whatever happens inside.
 *
 * Items with no stamped domain id, or whose domain id no longer resolves to
 * a live Domain entity (a pre-existing queue item enqueued before this fix
 * shipped, or a domain deleted after the notice was queued), fall through to
 * parent::processItem() unchanged — today's pre-fix behaviour, not a new
 * failure mode.
 */
class DomainAwareEmailNotificationsQueueWorker extends EmailNotificationsQueueWorker {

  /**
   * Constructs a DomainAwareEmailNotificationsQueueWorker.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin
   *   instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager, passed through to the parent constructor unchanged.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager, passed through to the parent constructor
   *   unchanged.
   * @param \Drupal\access_events\EventDomainContext $domainContext
   *   The domain context service used to send with the right domain active.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load the stamped domain id back into
   *   a Domain entity.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    protected EventDomainContext $domainContext,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $mail_manager, $language_manager);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin
   *   instance.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('access_events.domain_context'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item): void {
    $domainId = $item->params[EventDomainContext::DOMAIN_PARAM] ?? NULL;
    if ($domainId === NULL) {
      parent::processItem($item);
      return;
    }

    $domain = $this->entityTypeManager->getStorage('domain')->load($domainId);
    if (!$domain instanceof DomainInterface) {
      // Stamped id no longer resolves (domain deleted since enqueue) —
      // fall back to unchanged contrib behaviour rather than error.
      parent::processItem($item);
      return;
    }

    $this->domainContext->forDomain($domain, function () use ($item): void {
      parent::processItem($item);
    });
  }

}
