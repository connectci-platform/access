<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\Validation\Constraint;

use Drupal\access_events\RegistrantCounter;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventCreationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the EventSeriesRescheduleBlock constraint.
 */
class EventSeriesRescheduleBlockConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventCreationService $eventCreationService,
    protected RegistrantCounter $registrantCounter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('recurring_events.event_creation_service'),
      $container->get('access_events.registrant_counter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    if (!$entity instanceof EventSeries || !$constraint instanceof EventSeriesRescheduleBlockConstraint) {
      return;
    }
    foreach ($this->checkViolations($entity, $constraint) as $message) {
      $this->context->addViolation($message);
    }
  }

  /**
   * Runs both checks against $entity's last-saved state and returns any
   * violation messages, in the same order validate() would add them.
   *
   * Pulled out of validate() so a caller that is not itself running inside a
   * Symfony validation pass — the standalone content-moderation form's own
   * validate handler, which never invokes $entity->validate() (see
   * access_events.module) — can run the SAME two checks and surface the
   * SAME wording, rather than a second hand-copied implementation that could
   * drift from this one.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $entity
   *   The series as it would be saved (carrying the prospective new
   *   moderation_state/recur config).
   * @param \Drupal\access_events\Plugin\Validation\Constraint\EventSeriesRescheduleBlockConstraint $constraint
   *   Supplies the two message strings, so wording lives in exactly one
   *   place (the constraint class) regardless of caller.
   *
   * @return string[]
   *   Zero, one, or both violation messages.
   */
  public function checkViolations(EventSeries $entity, EventSeriesRescheduleBlockConstraint $constraint): array {
    // Create path: nothing to compare against yet, and loadUnchanged() on a
    // null/new id would hand checkForOriginalRecurConfigChanges() a NULL
    // where it type-hints EventSeries, fataling every event creation. Bail
    // out before any of that runs.
    if ($entity->isNew() || $entity->id() === NULL) {
      return [];
    }

    $original = $this->entityTypeManager
      ->getStorage('eventseries')
      ->loadUnchanged($entity->id());

    if (!$original instanceof EventSeries) {
      return [];
    }

    $messages = [];

    // The request-adjustment refusal: Request Adjustment (published ->
    // needs_adjustment) on a series that still has live registrations would
    // silently hide the event from visitors instead of notifying registrants
    // via the cancel path. Checked against $original (the loaded, currently-
    // published default revision), not $entity — the being-saved object
    // already carries the NEW moderation_state, so comparing entity-to-
    // entity would never see the published -> needs_adjustment edge. This
    // runs independently of the recur-config check below: a Request
    // Adjustment carries no date change, so checkForOriginalRecurConfigChanges()
    // would return FALSE and skip straight past the schedule-rebuild check.
    if ($original->isPublished()
      && $entity->get('moderation_state')->value === 'needs_adjustment'
      && $this->registrantCounter->countNotPastForSeries((int) $entity->id()) > 0) {
      $messages[] = $constraint->requestAdjustmentMessage;
    }

    if (!$this->eventCreationService->checkForOriginalRecurConfigChanges($entity, $original)) {
      // Content-only edit (title, body, field_location, ...); the recur/date
      // fields are unchanged, so no rebuild will happen.
      return $messages;
    }

    $notPastCount = $this->registrantCounter->countNotPastForSeries((int) $entity->id());
    if ($notPastCount === 0) {
      return $messages;
    }

    $messages[] = $constraint->message;
    return $messages;
  }

}
