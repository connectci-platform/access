<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Blocks a recurrence/date change on an eventseries with future registrants.
 *
 * The contrib recurring_events module rebuilds ALL instances when the
 * recurrence configuration changes, hard-deleting the existing instances
 * (and, with them, any attached registrant entities). This constraint stops
 * that rebuild from happening silently under a series that still has future
 * registrants.
 */
#[Constraint(
  id: 'EventSeriesRescheduleBlock',
  label: new TranslatableMarkup('Event series reschedule block', [], ['context' => 'Validation']),
  type: ['entity']
)]
class EventSeriesRescheduleBlockConstraint extends SymfonyConstraint {

  public string $message = 'Changing the recurrence or dates on this event will permanently delete %count future registration(s). Cancel and notify registrants before making this change.';

}
