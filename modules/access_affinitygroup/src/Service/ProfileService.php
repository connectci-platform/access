<?php

namespace Drupal\access_affinitygroup\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\user\UserInterface;

/**
 * Maps a user entity to the flat PRIME profile the assistant primes on.
 */
class ProfileService {

  public const CONSENT_FIELD = 'field_assistant_consent';

  /**
   * Tri-state consent: 'unset' (empty/never answered), 'allow', or 'deny'.
   */
  public function consentState(UserInterface $user): string {
    if (!$user->hasField(self::CONSENT_FIELD) || $user->get(self::CONSENT_FIELD)->isEmpty()) {
      return 'unset';
    }
    $value = $user->get(self::CONSENT_FIELD)->value;
    return in_array($value, ['allow', 'deny'], TRUE) ? $value : 'unset';
  }

  /**
   * The PRIME-tier profile as a flat, display-ready array.
   *
   * Reads field_is_cc and field_cider_resources with direct value access,
   * which bypasses field_permissions (both are permission_type: custom). This
   * is intentional: the endpoint returns the acting user's OWN record behind
   * the assistant-consent gate, not another user's data.
   *
   * @return array{
   *   first_name: string|null,
   *   last_name: string|null,
   *   pronouns: string|null,
   *   organization: string|null,
   *   academic_status: string|null,
   *   is_campus_champion: bool,
   *   allocated_resources: array<int, array{name: string, slug: string|null}>
   *   }
   */
  public function primeProfile(UserInterface $user): array {
    return [
      'first_name' => $this->str($user, 'field_user_first_name'),
      'last_name' => $this->str($user, 'field_user_last_name'),
      'pronouns' => $this->str($user, 'field_user_preferred_pronouns'),
      'organization' => $this->organization($user),
      'academic_status' => $this->enumLabel($user, 'field_academic_status'),
      'is_campus_champion' => (bool) ($this->raw($user, 'field_is_cc') ?? FALSE),
      'allocated_resources' => $this->resources($user),
    ];
  }

  /**
   * Trims a plain-string field value; blank or missing resolves to NULL.
   */
  private function str(UserInterface $user, string $field): ?string {
    if (!$user->hasField($field) || $user->get($field)->isEmpty()) {
      return NULL;
    }
    $value = trim((string) $user->get($field)->value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Reads a field's raw value with no trimming/casting.
   */
  private function raw(UserInterface $user, string $field) {
    return ($user->hasField($field) && !$user->get($field)->isEmpty())
      ? $user->get($field)->value : NULL;
  }

  /**
   * Resolves a list_string field's key to its allowed-values label.
   */
  private function enumLabel(UserInterface $user, string $field): ?string {
    if (!$user->hasField($field) || $user->get($field)->isEmpty()) {
      return NULL;
    }
    $key = $user->get($field)->value;
    // Drupal simplifies allowed_values to [key => label] on load.
    $allowed = $user->get($field)->getFieldDefinition()
      ->getFieldStorageDefinition()->getSetting('allowed_values');
    return $allowed[$key] ?? $key;
  }

  /**
   * Resolves the user's organization name.
   *
   * Mirrors MentorshipPersonBlock: use the org node's title, EXCEPT when the
   * org is the "Other" node, in which case the free-text institution is the
   * real answer. Empty org ref also falls back to institution.
   */
  private function organization(UserInterface $user): ?string {
    if ($user->hasField('field_access_organization')
        && !$user->get('field_access_organization')->isEmpty()) {
      $node = $user->get('field_access_organization')->entity;
      if ($node && $node->label() !== 'Other') {
        return $node->label();
      }
    }
    return $this->str($user, 'field_institution');
  }

  /**
   * Resolves the user's allocated CiDeR resources to {name, slug} pairs.
   */
  private function resources(UserInterface $user): array {
    if (!$user->hasField('field_cider_resources') || $user->get('field_cider_resources')->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($user->get('field_cider_resources')->referencedEntities() as $node) {
      $out[] = [
        'name' => operations_cider_resource_display_name($node),
        'slug' => $this->resourceSlug($node),
      ];
    }
    return $out;
  }

  /**
   * The dotted global resource id, e.g. 'delta-cpu.ncsa.access-ci.org'.
   */
  private function resourceSlug(EntityInterface $node): ?string {
    return ($node->hasField('field_access_global_resource_id')
            && !$node->get('field_access_global_resource_id')->isEmpty())
      ? (string) $node->get('field_access_global_resource_id')->value : NULL;
  }

}
