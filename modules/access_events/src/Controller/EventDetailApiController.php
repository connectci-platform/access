<?php

declare(strict_types=1);

namespace Drupal\access_events\Controller;

use Drupal\access_events\RegistrationState;
use Drupal\Core\Controller\ControllerBase;
use Drupal\recurring_events\Entity\EventInstance;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Serves the ACCESS event detail API: GET /api/1.0/events/{eventinstance_id}.
 *
 * Read-only. The route is gated by the RpAccountAccess acting-user gate, which
 * resolves X-Acting-User and sets the rp_account_effective_uid request
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
      ->attributes->get('rp_account_effective_uid', 0);
    return (new JsonResponse($this->detail($eventinstance, $uid)))
      ->setPrivate()
      ->setMaxAge(0);
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
    // Safe only for string/text fields (title, description, location,
    // event_type, skill_level, speakers).
    $getString = static function ($entity, string $field): ?string {
      if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
        return NULL;
      }
      $item = $entity->get($field)->first();
      $value = $item->value ?? $item->getString();
      return ($value === '' || $value === NULL) ? NULL : (string) $value;
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
      'event_type' => $getString($instance, 'event_type'),
      'skill_level' => $getString($instance, 'skill_level'),
      'speakers' => $getString($instance, 'speakers'),
      'tags' => $tags,
      'registration_url' => $registrationUrl,
      'series_id' => (string) $instance->get('eventseries_id')->target_id,
      'registration' => RegistrationState::forInstance($instance, $uid),
    ];
  }

}
