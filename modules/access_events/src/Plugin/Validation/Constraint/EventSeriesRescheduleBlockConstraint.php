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
 *
 * Also carries the request-adjustment refusal message: Request Adjustment
 * (published -> needs_adjustment) would hide a live, registered event rather
 * than notifying its registrants — see
 * EventSeriesRescheduleBlockConstraintValidator for both
 * conditions.
 */
#[Constraint(
  id: 'EventSeriesRescheduleBlock',
  label: new TranslatableMarkup('Event series reschedule block', [], ['context' => 'Validation']),
  type: ['entity']
)]
class EventSeriesRescheduleBlockConstraint extends SymfonyConstraint {

  public string $message = "This event has registrations, so its schedule cannot be rebuilt. To reschedule: cancel the event (registrants are notified), change each occurrence's date, then restore the event. To adjust dates, edit the occurrences individually; to add or remove a date, add an occurrence or cancel one.";

  public string $requestAdjustmentMessage = 'Request Adjustment would hide a live event that has registrations. Cancel (Archive) it instead — registrants will be notified.';

}
