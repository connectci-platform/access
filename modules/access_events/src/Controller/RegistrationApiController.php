<?php

namespace Drupal\access_events\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists the acting user's event registrations.
 */
class RegistrationApiController extends ControllerBase {

  public function __construct(private readonly EntityTypeManagerInterface $etm) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Returns a flat, self-sufficient list of the acting user's registrations.
   */
  public function list(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    // Defense-in-depth: the route's acting-user access check guarantees a
    // positive uid, but never trust that wiring alone — a uid of 0 would
    // match anonymous-owned registrants (a real data shape in
    // recurring_events) and must never list or cancel them.
    if ($uid < 1) {
      throw new AccessDeniedHttpException('No acting user resolved.');
    }
    // Explicit allow-list: any value other than these three is rejected, so a
    // typo (or 'all' abbreviations) can never silently fall through to an
    // unfiltered list. Empty/absent defaults to 'upcoming'.
    $when = (string) $request->query->get('when', 'upcoming');
    if ($when === '') {
      $when = 'upcoming';
    }
    if (!in_array($when, ['upcoming', 'past', 'all'], TRUE)) {
      return new JsonResponse(
        ['error' => "Invalid 'when' value. Use upcoming, past, or all."],
        Response::HTTP_BAD_REQUEST
      );
    }
    $now = gmdate('Y-m-d\TH:i:s');
    $storage = $this->etm->getStorage('registrant');
    // Access is gated by the route's _custom_access (acting-user check) and the
    // query is hard-scoped to this user's uid. Entity access would evaluate
    // against the SERVICE account, not the acting user — so bypass it here.
    $query = $storage->getQuery()->condition('user_id', $uid)->accessCheck(FALSE);
    if ($when === 'upcoming') {
      $query->condition('eventinstance_id.entity.date.end_value', $now, '>=')
        ->sort('eventinstance_id.entity.date.value', 'ASC');
    }
    elseif ($when === 'past') {
      $query->condition('eventinstance_id.entity.date.end_value', $now, '<')
        ->sort('eventinstance_id.entity.date.value', 'DESC');
    }
    else {
      // 'all': no date filter, but sort deterministically by start ascending
      // (same field the upcoming branch sorts on) rather than storage order.
      $query->sort('eventinstance_id.entity.date.value', 'ASC');
    }
    $ids = $query->execute();
    $registrants = $storage->loadMultiple($ids);
    $out = [];
    foreach ($registrants as $registrant) {
      $instance = $registrant->get('eventinstance_id')->entity;
      if (!$instance) {
        continue;
      }
      $out[] = [
        'registrant_id' => $registrant->uuid(),
        'eventinstance_id' => (int) $instance->id(),
        'event_title' => $instance->get('title')->value,
        'start_date' => $this->iso($instance->get('date')->value),
        'end_date' => $this->iso($instance->get('date')->end_value),
        'location' => $this->plain($instance->get('location')->value ?? NULL),
        'virtual_meeting_link' => $instance->get('virtual_meeting_link')->uri ?? NULL,
        'event_type' => $this->eventTypeLabel($instance),
        'waitlist' => (bool) $registrant->get('waitlist')->value,
        'registered_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $registrant->get('created')->value),
      ];
    }
    return new JsonResponse(['registrations' => $out]);
  }

  /**
   * DELETE /api/1.0/registrations/{registrant_id} — cancel the acting user's own registration.
   */
  public function cancel(string $registrant_id, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    // Defense-in-depth: the route's acting-user access check guarantees a
    // positive uid, but never trust that wiring alone — a uid of 0 would
    // match anonymous-owned registrants (a real data shape in
    // recurring_events) and must never list or cancel them.
    if ($uid < 1) {
      throw new AccessDeniedHttpException('No acting user resolved.');
    }
    $storage = $this->etm->getStorage('registrant');
    // Load by uuid via loadByProperties — route entity-param upcasting expects
    // the integer id and would 404 a uuid.
    $matches = $storage->loadByProperties(['uuid' => $registrant_id]);
    $registrant = reset($matches);
    if (!$registrant) {
      throw new NotFoundHttpException('Registration not found.');
    }
    // Ownership check in the controller (NOT via entity access — the site's
    // registrant access handler is overridden by access_misc and denies even
    // the owner). A user may cancel ONLY their own registration.
    if ((int) $registrant->getOwnerId() !== $uid) {
      throw new AccessDeniedHttpException('You may only cancel your own registration.');
    }
    // $entity->delete() bypasses access checks (EntityBase::delete goes straight
    // to storage), so this succeeds with the service account holding no
    // registrant permissions. Do NOT grant mcp_service registrant permissions.
    $registrant->delete();
    return new JsonResponse(['status' => 'cancelled', 'registrant_id' => $registrant_id]);
  }

  /**
   * Normalizes a naive-UTC datetime string to ISO-8601 with a trailing Z.
   */
  private function iso(?string $dt): ?string {
    return $dt ? (substr($dt, 0, 19) . 'Z') : NULL;
  }

  /**
   * Strips markup to plain text, returning NULL when empty.
   */
  private function plain(?string $html): ?string {
    if ($html === NULL || $html === '') {
      return NULL;
    }
    $s = trim(strip_tags($html));
    return $s === '' ? NULL : $s;
  }

  /**
   * Maps the event_type machine key to its human-readable label.
   */
  private function eventTypeLabel($instance): ?string {
    $key = $instance->get('event_type')->value ?? NULL;
    if ($key === NULL || $key === '') {
      return NULL;
    }
    $allowed = $instance->get('event_type')->getFieldDefinition()->getSetting('allowed_values') ?? [];
    return $allowed[$key] ?? $key;
  }

}
