<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the EventInstancePublishGuard constraint.
 */
class EventInstancePublishGuardConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    if (!$entity instanceof EventInstance || !$constraint instanceof EventInstancePublishGuardConstraint) {
      return;
    }
    if ($this->checkViolation($entity)) {
      $this->context->addViolation($constraint->message);
    }
  }

  /**
   * TRUE if publishing $entity right now is refused (dark-parent guard).
   *
   * Pulled out of validate() so a caller that never runs $entity->validate()
   * — the standalone content-moderation form's own validate handler (see
   * access_events.module), which calls $entity->save() directly with no
   * Symfony validation pass in between — can run the SAME check and surface
   * the SAME constraint message, rather than a second hand-copied
   * implementation that could drift from this one.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $entity
   *   The instance as it would be saved (carrying the prospective new
   *   moderation_state).
   *
   * @return bool
   *   TRUE if publishing must be refused.
   */
  public function checkViolation(EventInstance $entity): bool {
    if ($entity->isNew() || $entity->id() === NULL) {
      return FALSE;
    }

    // validate() runs ahead of save() (form/API submission paths), so
    // $entity->original is not yet populated -- that only happens inside
    // EntityStorageBase::save(). Read the last-saved state directly, mirroring
    // EventSeriesRescheduleBlockConstraintValidator.
    $original = $this->entityTypeManager->getStorage('eventinstance')->loadUnchanged($entity->id());
    if (!$original instanceof EventInstance) {
      return FALSE;
    }

    $from = $original->get('moderation_state')->value;
    $to = $entity->get('moderation_state')->value;
    if ($to !== 'published' || $from === 'published') {
      return FALSE;
    }

    $seriesId = (int) $entity->get('eventseries_id')->target_id;
    $series = $this->entityTypeManager->getStorage('eventseries')->loadUnchanged($seriesId);
    return $series instanceof EventSeries && !$series->isPublished();
  }

}
