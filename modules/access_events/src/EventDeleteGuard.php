<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * The single place the has-registrations delete rule is decided.
 *
 * An eventseries or eventinstance that has ANY registrations — past or
 * future, attendance history is protected data — can never be hard-deleted,
 * from any path (browser form, API, or direct entity code). Editors cancel
 * (archive) the event instead, which keeps the registrations, or remove the
 * registrations first. Every call site that can reach a delete asks THIS
 * service rather than re-deriving the count/message logic itself, so the
 * rule and its wording live in one place: the two predelete hooks, the
 * contrib pre_delete_instance hook (the layer that actually protects
 * contrib's delete forms — see access_events.module for why), the two
 * delete-form alters, and the API draft-delete refusal branch.
 */
class EventDeleteGuard {

  public function __construct(protected RegistrantCounter $registrantCounter) {}

  /**
   * Returns why $entity cannot be deleted, or NULL when it may be.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries|\Drupal\recurring_events\Entity\EventInstance $entity
   *   The eventseries or eventinstance being considered for deletion.
   *
   * @return string|null
   *   The canonical human-readable refusal message, or NULL if the entity
   *   has no registrations and deletion may proceed.
   */
  public function deletionBlockedReason(EventSeries|EventInstance $entity): ?string {
    $count = $entity instanceof EventSeries
      ? $this->registrantCounter->countForSeries((int) $entity->id())
      : $this->registrantCounter->countForInstance((int) $entity->id());
    if ($count === 0) {
      return NULL;
    }
    return sprintf(
      'This event has %d registration(s) and cannot be deleted. Cancel the event instead (registrations are kept and registrants notified), or remove the registrations first.',
      $count,
    );
  }

  /**
   * Throws when $entity has registrations; a silent no-op otherwise.
   *
   * The backstop every code-level delete path (predelete hooks, the contrib
   * pre_delete_instance hook) calls before letting a delete proceed.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries|\Drupal\recurring_events\Entity\EventInstance $entity
   *   The eventseries or eventinstance being deleted.
   *
   * @throws \RuntimeException
   *   When the entity has any registrations.
   */
  public function assertDeletable(EventSeries|EventInstance $entity): void {
    $reason = $this->deletionBlockedReason($entity);
    if ($reason !== NULL) {
      throw new \RuntimeException($reason);
    }
  }

}
