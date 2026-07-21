<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events_registration\RegistrationCreationService;

/**
 * Computes the live, per-user registration state for an event instance.
 *
 * Read-only: wraps the contrib RegistrationCreationService so both the detail
 * route and the register preview path share one source of truth for capacity,
 * seats, the registration window, and per-user dedup.
 */
final class RegistrationState {

  /**
   * Builds the registration-state array for an instance and acting user.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $instance
   *   The event instance.
   * @param int $actingUid
   *   The acting user's uid (from the rp_account_effective_uid attribute).
   *
   * @return array
   *   ['enabled' => FALSE] when native registration is off, otherwise the full
   *   state: enabled, capacity (?int), registered_count (int),
   *   seats_remaining (?int), waitlist_enabled (bool), registration_open (bool),
   *   registration_window (['opens' => ?string, 'closes' => ?string]) with
   *   Z-suffixed ISO-8601 dates, and already_registered (bool).
   */
  public static function forInstance(EventInstance $instance, int $actingUid): array {
    /** @var \Drupal\recurring_events_registration\RegistrationCreationService $svc */
    $svc = \Drupal::service('recurring_events_registration.creation_service');
    $svc->setEventInstance($instance);

    if (!$svc->hasRegistration()) {
      return ['enabled' => FALSE];
    }

    // retrieveAvailability(): -1 = unlimited; else capacity − non-waitlisted
    // count, clamped >= 0.
    $availability = $svc->retrieveAvailability();
    $registered = $svc->retrieveRegisteredPartiesCount(TRUE, FALSE);

    $series = $instance->getEventSeries();
    $capRaw = (int) $series->get('event_registration')->capacity;
    $capacity = $capRaw > 0 ? $capRaw : NULL;
    $seatsRemaining = $availability < 0 ? NULL : $availability;

    // registrationOpeningClosingTime() returns
    // ['reg_open' => ?DrupalDateTime, 'reg_close' => ?DrupalDateTime] (verified
    // against the installed contrib RegistrationCreationService). Those
    // DrupalDateTime objects are in the site's default timezone, NOT UTC — so
    // convert to UTC before appending the 'Z' suffix, otherwise the timestamp is
    // mislabeled by the TZ offset.
    // Clone before setTimezone(): reg_close aliases the instance's cached
    // start_date object, and setTimezone() mutates in place — cloning keeps the
    // conversion from leaking into other reads of the same start_date (e.g. A2).
    $window = $svc->registrationOpeningClosingTime() ?: [];
    $iso = static fn ($dt): ?string => $dt
      ? (clone $dt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
      : NULL;

    return [
      'enabled' => TRUE,
      'capacity' => $capacity,
      'registered_count' => (int) $registered,
      'seats_remaining' => $seatsRemaining,
      'waitlist_enabled' => (bool) $svc->hasWaitlist(),
      'registration_open' => (bool) $svc->registrationIsOpen(),
      'registration_window' => [
        'opens' => $iso($window['reg_open'] ?? NULL),
        'closes' => $iso($window['reg_close'] ?? NULL),
      ],
      // hasUserRegisteredById() counts waitlisted registrants too (unlike
      // registered_count above, which is non-waitlisted only). Intentional: a
      // waitlisted user must not be able to re-register, so dedup is stricter
      // than the seat count.
      'already_registered' => $svc->hasUserRegisteredById($actingUid),
    ];
  }

}
