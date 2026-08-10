<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Blocks publishing an occurrence while its parent series is not published.
 *
 * Mirrors the presave throw in EventStateReactions::instancePresave() so a
 * form/API submission that runs validate() surfaces the same refusal as a
 * message instead of an uncaught exception. Both must exist: this constraint
 * only runs where validate() runs, and some save paths call save() directly.
 */
#[Constraint(
  id: 'EventInstancePublishGuard',
  label: new TranslatableMarkup('Event instance publish guard', [], ['context' => 'Validation']),
  type: ['entity']
)]
class EventInstancePublishGuardConstraint extends SymfonyConstraint {

  public string $message = 'This occurrence cannot be published while the event itself is not published. Restore the event (which republishes its occurrences), or publish the event first.';

}
