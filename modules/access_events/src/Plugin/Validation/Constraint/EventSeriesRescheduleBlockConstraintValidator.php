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
    if (!$entity instanceof EventSeries) {
      return;
    }

    // Create path: nothing to compare against yet, and loadUnchanged() on a
    // null/new id would hand checkForOriginalRecurConfigChanges() a NULL
    // where it type-hints EventSeries, fataling every event creation. Bail
    // out before any of that runs.
    if ($entity->isNew() || $entity->id() === NULL) {
      return;
    }

    $original = $this->entityTypeManager
      ->getStorage('eventseries')
      ->loadUnchanged($entity->id());

    if (!$original instanceof EventSeries) {
      return;
    }

    if (!$this->eventCreationService->checkForOriginalRecurConfigChanges($entity, $original)) {
      // Content-only edit (title, body, field_location, ...); the recur/date
      // fields are unchanged, so no rebuild will happen.
      return;
    }

    $futureCount = $this->registrantCounter->countFutureForSeries((int) $entity->id());
    if ($futureCount === 0) {
      return;
    }

    $this->context->addViolation($constraint->message, ['%count' => $futureCount]);
  }

}
