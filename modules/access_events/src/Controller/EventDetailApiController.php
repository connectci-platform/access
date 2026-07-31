<?php

declare(strict_types=1);

namespace Drupal\access_events\Controller;

use Drupal\access_events\RegistrationState;
use Drupal\Core\Controller\ControllerBase;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events_registration\Entity\Registrant;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Serves the ACCESS event detail API: GET /api/1.0/events/{eventinstance_id}.
 *
 * Read-only. The route is gated by the ActingUserAccess acting-user gate, which
 * resolves X-Acting-User and sets the acting_user_uid request
 * attribute; the controller reads that attribute for per-user registration
 * state and never trusts request-body identity.
 */
class EventDetailApiController extends ControllerBase {

  public function __construct(protected RequestStack $requestStack) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('request_stack'));
  }

  /**
   * GET /api/1.0/events/{eventinstance_id}.
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $eventinstance
   *   The event instance, resolved by the entity param converter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The full event detail plus the live registration block.
   */
  public function get(EventInstance $eventinstance): JsonResponse {
    $uid = (int) $this->requestStack->getCurrentRequest()
      ->attributes->get('acting_user_uid', 0);

    // In-controller entity-access gate. This MUST live here, not as a route-level
    // _entity_access requirement: route access runs BEFORE the acting-user switch
    // subscriber, so it would evaluate against the service account, not the
    // resolved acting user. Published instances are viewable by anyone; an
    // unpublished instance is viewable only to accounts whose access allows it
    // (the owner, granted by access_events_entity_access()). A null $user
    // (anonymous / unresolved) correctly cannot view an unpublished instance.
    $user = $uid > 0
      ? $this->entityTypeManager()->getStorage('user')->load($uid)
      : NULL;
    if (!$eventinstance->access('view', $user, TRUE)->isAllowed()) {
      return $this->refuse('not_found', 'Event not found.', 404);
    }

    return (new JsonResponse($this->detail($eventinstance, $uid)))
      ->setPrivate()
      ->setMaxAge(0);
  }

  /**
   * POST /api/1.0/events/{eventinstance_id}/register.
   *
   * Runs the full registration guard chain. Without a truthy `confirmed` in the
   * JSON body this is a PREVIEW (guards 1-7 run read-only, nothing is written).
   * With `confirmed:true`, on passing every guard, a registrant is saved.
   *
   * The acting uid comes ONLY from the acting_user_uid request
   * attribute set by the ActingUserAccess gate; the email is read from that
   * loaded user entity and eventseries_id from the loaded instance. The
   * request body identity is never trusted. The state guard chain gates the
   * registration semantics; the write itself is authorized by an explicit
   * registrant createAccess assertion under the switched acting user (save()
   * invokes no access handler on its own).
   *
   * @param \Drupal\recurring_events\Entity\EventInstance $eventinstance
   *   The event instance, resolved by the entity param converter.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (carries the JSON body and acting-uid attribute).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A preview, a success body, or a 409 state refusal.
   */
  public function register(EventInstance $eventinstance, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid', 0);

    // Defense-in-depth: the ActingUserAccess gate guarantees uid >= 1 in prod,
    // so this is unreachable there. But if that invariant ever broke, uid = 0
    // would slip past hasUserRegisteredById(0) (0 is falsy → the user filter is
    // dropped → FALSE on an empty event) and save() a registrant with
    // user_id = 0 / email = NULL. uid < 1 means no real acting user resolved —
    // an identity failure (the gate-403 category), so this is a genuine 403,
    // not a state refusal.
    if ($uid < 1) {
      return $this->refuse('unauthenticated', 'No authenticated acting user for this registration.', 403);
    }

    $confirmed = (json_decode($request->getContent() ?: '{}', TRUE)['confirmed'] ?? NULL) === TRUE;

    /** @var \Drupal\recurring_events_registration\RegistrationCreationService $svc */
    $svc = \Drupal::service('recurring_events_registration.creation_service');
    $svc->setEventInstance($eventinstance);

    // Guard 1 (uid) + Guard 2 (instance) are already resolved (the attribute +
    // param converter). Guards 3-7 below; each hard-gates with a refusal.
    // Guard 3: native registration must be enabled.
    if (!$svc->hasRegistration()) {
      return $this->refuse('not_registrable', 'Native ACCESS registration is not enabled for this event.', 409);
    }
    // Guard 4: the registration window must be open.
    if (!$svc->registrationIsOpen()) {
      return $this->refuse('registration_closed', 'Registration for this event is not currently open.', 409);
    }
    // Guard 5: role restriction (empty/unset permitted set = open to all). This
    // is a 409, not a 403: the user IS identified and authorized (they passed
    // the ActingUserAccess gate), so a role-restriction refusal is a
    // registration-STATE refusal, not an identity/auth failure. 403 is reserved
    // for the gate itself, which runs before this controller.
    if (!$this->rolesPermitted($svc, $uid)) {
      return $this->refuse('not_permitted', 'Your account is not permitted to register for this event.', 409);
    }
    // Guard 6: per-user dedup (counts waitlisted registrants too).
    if ($svc->hasUserRegisteredById($uid)) {
      return $this->refuse('already_registered', 'You are already registered for this event.', 409);
    }
    // Guard 7: availability → seat, else waitlist, else full.
    $waitlisted = FALSE;
    if (!$svc->hasAvailability()) {
      if (!$svc->hasWaitlist()) {
        return $this->refuse('event_full', 'This event is full and has no waitlist.', 409);
      }
      $waitlisted = TRUE;
    }

    // Preview path: report the outcome without writing anything.
    if (!$confirmed) {
      $availability = $svc->retrieveAvailability();
      $preview = [
        'preview' => TRUE,
        'outcome_if_confirmed' => $waitlisted ? 'waitlist' : 'seat',
        'seats_remaining' => $availability < 0 ? NULL : $availability,
        'registration_open' => TRUE,
        'already_registered' => FALSE,
        'message' => $waitlisted
          ? 'This event is full; you would join the waitlist. Call again with confirmed:true.'
          : 'A seat is available. Call again with confirmed:true to register.',
      ];
      if ($waitlisted) {
        $preview['waitlisted_count'] = (int) $svc->retrieveRegisteredPartiesCount(FALSE, TRUE);
      }
      return (new JsonResponse($preview))->setPrivate()->setMaxAge(0);
    }

    // Commit path. Identity is bound from $uid / the loaded user / the instance,
    // NEVER from the request body. EntityBase::save() invokes no access handler,
    // so the explicit createAccess assertion below is the write boundary: it
    // runs the registrant access handler for the acting user (the request has
    // been switched to them by ActingUserSwitchSubscriber), which allows the
    // create when they hold 'add registrant entities' and registration is
    // enabled on the instance. The state guard chain above still gates the
    // registration semantics (window/dedup/capacity/role).
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    $access = $this->entityTypeManager()
      ->getAccessControlHandler('registrant')
      ->createAccess($eventinstance->getType(), $user, [], TRUE);
    if (!$access->isAllowed()) {
      return $this->refuse('not_permitted', 'You are not permitted to register for this event.', 409);
    }
    $registrant = Registrant::create([
      // 'bundle' is the registrant entity bundle (entity_reference to
      // registrant_type); the instance's bundle (e.g. 'default').
      'bundle' => $eventinstance->getType(),
      // 'type' is the separate 'series' | 'instance' discriminator string from
      // the series via the service — NOT the bundle and NOT 'default'.
      'type' => $svc->getRegistrationType(),
      'user_id' => $uid,
      'email' => $user?->getEmail(),
      'eventinstance_id' => $eventinstance->id(),
      'eventseries_id' => $eventinstance->get('eventseries_id')->target_id,
      'waitlist' => $waitlisted ? 1 : 0,
    ]);
    $registrant->save();

    return (new JsonResponse([
      'success' => TRUE,
      'status' => $waitlisted ? 'waitlisted' : 'registered',
      'registrant_id' => $registrant->uuid(),
      'eventinstance_id' => (string) $eventinstance->id(),
      'message' => $waitlisted
        ? 'You have been added to the waitlist.'
        : 'You are registered.',
    ]))->setPrivate()->setMaxAge(0);
  }

  /**
   * Builds a refusal JsonResponse with the canonical {error, message} body.
   */
  protected function refuse(string $code, string $message, int $status): JsonResponse {
    return (new JsonResponse(['error' => $code, 'message' => $message], $status))
      ->setPrivate()
      ->setMaxAge(0);
  }

  /**
   * Whether the acting user's roles satisfy the series' permitted-roles gate.
   *
   * An empty or unset permitted set means registration is open to all. Guarded
   * with method_exists so a contrib without the method degrades to "open".
   *
   * @param \Drupal\recurring_events_registration\RegistrationCreationService $svc
   *   The creation service, already bound to the instance.
   * @param int $uid
   *   The acting user's uid.
   *
   * @return bool
   *   TRUE if permitted (or unrestricted); FALSE if restricted and no overlap.
   */
  protected function rolesPermitted($svc, int $uid): bool {
    if (!method_exists($svc, 'registrationPermittedRoles')) {
      return TRUE;
    }
    $permitted = array_filter((array) $svc->registrationPermittedRoles());
    if (!$permitted) {
      return TRUE;
    }
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    return (bool) array_intersect($permitted, $user ? $user->getRoles() : []);
  }

  /**
   * Builds the detail object for an instance and acting user.
   *
   * The title/description/location/… fields are field_inheritance-computed from
   * the source eventseries; an absent or empty inherited field degrades to
   * null. Dates come from the eventinstance daterange base field, which is
   * stored naive-UTC (no offset) — so the controller only appends a literal Z,
   * it does NOT re-convert the timezone (contrast RegistrationState, which
   * reads timezone-aware DrupalDateTime objects and must convert to UTC first).
   *
   * The generic string reader below is only safe for genuine string/text
   * fields. The two non-string inherited fields are read by type:
   *  - registration (a LINK field): read ->uri, not the generic reader
   *    (Map::getString() would implode uri + title into
   *    "https://…/reg, Register Here" when the link carries title text).
   *  - tags (a multi-value ENTITY_REFERENCE to taxonomy_term): emit a
   *    comma-separated list of term NAMES, matching the search_events listing
   *    (EventTags search_api processor emits term names).
   */
  protected function detail(EventInstance $instance, int $uid): array {
    // Safe only for plain string/text fields (title, description, location,
    // speakers). NOT for the list_string option fields event_type/skill_level —
    // those store an internal KEY that differs from the human LABEL (e.g. the
    // event_type key 'zz_other' → label 'Other'); use $getOptionLabel below.
    $getString = static function ($entity, string $field): ?string {
      if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
        return NULL;
      }
      $item = $entity->get($field)->first();
      $value = $item->value ?? $item->getString();
      return ($value === '' || $value === NULL) ? NULL : (string) $value;
    };

    // For a list_string (option) field, map the stored KEY to its human LABEL
    // via the field's allowed_values, matching what the other event tools
    // (get_my_registrations, search_events) emit. Falls back to the raw key if
    // it isn't in the map (defensive — a stale value after an option was
    // removed), and degrades to null when the field is empty/absent (same as
    // the other detail fields). allowed_values normally lives on the inherited
    // computed field's definition (field_inheritance copies the source field's
    // settings); if it's missing there, read it from the source series field.
    $getOptionLabel = static function ($entity, string $field): ?string {
      if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
        return NULL;
      }
      $item = $entity->get($field)->first();
      $key = $item->value ?? $item->getString();
      if ($key === '' || $key === NULL) {
        return NULL;
      }
      $allowed = $entity->get($field)->getFieldDefinition()->getSetting('allowed_values');
      // Defensive: if the computed field lacks the setting, read it from the
      // source eventseries field definition instead.
      if (empty($allowed) && $entity->hasField('eventseries_id') && !$entity->get('eventseries_id')->isEmpty()) {
        $series = $entity->get('eventseries_id')->entity;
        if ($series && $series->hasField('field_' . $field)) {
          $allowed = $series->get('field_' . $field)->getFieldDefinition()->getSetting('allowed_values');
        }
      }
      // allowed_values may be the simplified {key: label} map or the structured
      // list of {value, label} dicts — normalize the structured form.
      if (is_array($allowed) && $allowed && !array_key_exists($key, $allowed)) {
        $first = reset($allowed);
        if (is_array($first) && isset($first['value'])) {
          $map = [];
          foreach ($allowed as $entry) {
            if (isset($entry['value'])) {
              $map[$entry['value']] = $entry['label'] ?? $entry['value'];
            }
          }
          $allowed = $map;
        }
      }
      $label = (is_array($allowed) && isset($allowed[$key])) ? $allowed[$key] : $key;
      return (string) $label;
    };

    // registration_url: the inherited `registration` computed field is a LINK
    // field — read its uri property, null when empty.
    $registrationUrl = NULL;
    if ($instance->hasField('registration') && !$instance->get('registration')->isEmpty()) {
      $reg = $instance->get('registration')->first();
      $registrationUrl = ($reg && $reg->uri !== '' && $reg->uri !== NULL) ? $reg->uri : NULL;
    }

    // tags: the inherited `tags` computed field is a multi-value
    // entity_reference to taxonomy_term — join the referenced term labels.
    $tags = NULL;
    if ($instance->hasField('tags') && !$instance->get('tags')->isEmpty()) {
      $terms = $instance->get('tags')->referencedEntities();
      $names = array_filter(array_map(static fn ($t) => $t->label(), $terms));
      $tags = $names ? implode(', ', $names) : NULL;
    }

    $date = $instance->get('date')->first();
    $isoZ = static fn (?string $naive): ?string => ($naive !== NULL && $naive !== '')
      ? (rtrim($naive, 'Z') . 'Z')
      : NULL;

    return [
      'id' => (string) $instance->id(),
      'title' => $getString($instance, 'title'),
      'description' => $getString($instance, 'description'),
      'start_date' => $isoZ($date?->value),
      'end_date' => $isoZ($date?->end_value),
      'location' => $getString($instance, 'location'),
      'event_type' => $getOptionLabel($instance, 'event_type'),
      'skill_level' => $getOptionLabel($instance, 'skill_level'),
      'speakers' => $getString($instance, 'speakers'),
      'tags' => $tags,
      'registration_url' => $registrationUrl,
      'series_id' => (string) $instance->get('eventseries_id')->target_id,
      'registration' => RegistrationState::forInstance($instance, $uid),
    ];
  }

}
