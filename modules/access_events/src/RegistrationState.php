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
   *   The acting user's uid (from the acting_user_uid attribute).
   *
   * @return array
   *   ['enabled' => FALSE] when native registration is off, otherwise a
   *   three-way partition keyed on whether an acting user resolved:
   *   - Entity-derived, ALWAYS present: enabled (TRUE), capacity (?int),
   *     registered_count (int), seats_remaining (?int), waitlist_enabled (bool).
   *   - Per-user + time-derived, present ONLY when $actingUid >= 1:
   *     already_registered (bool) is per-user; registration_open (bool) and
   *     registration_window (['opens' => ?string, 'closes' => ?string], with
   *     Z-suffixed ISO-8601 dates) are wall-clock-derived. For an anonymous
   *     (uid < 1) call these three are OMITTED so the payload is cacheable — no
   *     cache tag can invalidate a clock comparison, and hasUserRegisteredById(0)
   *     drops the user filter and would read TRUE whenever anyone is registered.
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

    $state = [
      'enabled' => TRUE,
      'capacity' => $capacity,
      'registered_count' => (int) $registered,
      'seats_remaining' => $seatsRemaining,
      'waitlist_enabled' => (bool) $svc->hasWaitlist(),
    ];

    // Per-user + time-derived fields: present ONLY for a resolved acting user.
    // registration_open is wall-clock-derived (registrationIsOpen()) and cannot
    // be tag-invalidated, so it must not appear in the cacheable anonymous
    // payload. already_registered is per-user (uid=0 would drop the filter and
    // read TRUE if anyone is registered). All three are excluded when uid < 1.
    //
    // registration_window.opens: for the 'open' (open-immediately) dates-type
    // the contrib service derives reg_open as a fresh `now` on every call, so
    // surfacing it made `opens` track the wall clock (meaningless, and it made
    // registration_open read as effectively always true). Emit NULL instead —
    // the open-now state is carried by registration_open — so `opens` is stable
    // across calls. Only a genuinely scheduled window keeps its configured
    // reg_open timestamp.
    //
    // hasUserRegisteredById() counts waitlisted registrants too (unlike
    // registered_count above, which is non-waitlisted only). Intentional: a
    // waitlisted user must not be able to re-register, so dedup is stricter
    // than the seat count.
    if ($actingUid >= 1) {
      $state['registration_open'] = (bool) $svc->registrationIsOpen();
      $state['registration_window'] = [
        'opens' => $svc->getRegistrationDatesType() === 'open' ? NULL : $iso($window['reg_open'] ?? NULL),
        'closes' => $iso($window['reg_close'] ?? NULL),
      ];
      $state['already_registered'] = $svc->hasUserRegisteredById($actingUid);
    }

    return $state;
  }

}
