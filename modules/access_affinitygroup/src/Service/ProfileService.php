<?php

namespace Drupal\access_affinitygroup\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\user\UserInterface;

/**
 * Maps a user entity to the flat PRIME profile the assistant primes on.
 */
class ProfileService {

  public const CONSENT_FIELD = 'field_assistant_consent';

  public const CONSENT_VERSION_FIELD = 'field_assistant_consent_version';

  public const CONSENT_ASKED_FIELD = 'field_assistant_consent_asked';

  /**
   * The current version of the consent wording.
   *
   * A stored allow or deny made under a lower version reads as unset, so
   * raising this asks everyone again.
   */
  public const CONSENT_VERSION = 1;

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * The effective consent: 'unset', 'allow', or 'deny'.
   *
   * Unset covers never answered, dismissed with "not now", and a choice made
   * under an older consent version.
   */
  public function consentState(UserInterface $user): string {
    if (!$this->hasCurrentVersion($user)) {
      return 'unset';
    }
    $value = $this->raw($user, self::CONSENT_FIELD);
    return in_array($value, ['allow', 'deny'], TRUE) ? $value : 'unset';
  }

  /**
   * When the user last answered or dismissed the question, in ISO 8601 UTC.
   *
   * NULL when never asked, or when the last answer was under an older
   * consent version.
   */
  public function askedAt(UserInterface $user): ?string {
    $asked = $this->raw($user, self::CONSENT_ASKED_FIELD);
    if ($asked === NULL || !$this->hasCurrentVersion($user)) {
      return NULL;
    }
    return gmdate('Y-m-d\TH:i:s\Z', (int) $asked);
  }

  /**
   * Records an explicit allow or deny, stamping the version and time.
   */
  public function recordChoice(UserInterface $user, string $value): void {
    $user->set(self::CONSENT_FIELD, $value);
    $this->stamp($user);
    $user->save();
  }

  /**
   * Records a "not now": the question was asked, consent stays unset.
   *
   * Clears any stored choice, since one from an older version would
   * otherwise count again once the version is stamped.
   */
  public function recordNotNow(UserInterface $user): void {
    $user->set(self::CONSENT_FIELD, NULL);
    $this->stamp($user);
    $user->save();
  }

  /**
   * Stamps the version and time when a save changes the consent value.
   *
   * Called from hook_user_presave(), so the profile edit form and any other
   * entity save are covered as well as the endpoint.
   */
  public function stampIfConsentChanged(UserInterface $user): void {
    if (!$user->hasField(self::CONSENT_FIELD)) {
      return;
    }
    $original = $user->isNew() ? NULL : $user->getOriginal();
    $before = $original instanceof UserInterface ? $this->raw($original, self::CONSENT_FIELD) : NULL;
    if ($before !== $this->raw($user, self::CONSENT_FIELD)) {
      $this->stamp($user);
    }
  }

  /**
   * Sets the current consent version and the asked time to now.
   */
  private function stamp(UserInterface $user): void {
    $user->set(self::CONSENT_VERSION_FIELD, self::CONSENT_VERSION);
    $user->set(self::CONSENT_ASKED_FIELD, $this->time->getCurrentTime());
  }

  /**
   * Whether the stored consent was recorded under the current version.
   */
  private function hasCurrentVersion(UserInterface $user): bool {
    $version = $this->raw($user, self::CONSENT_VERSION_FIELD);
    return $version !== NULL && (int) $version >= self::CONSENT_VERSION;
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
  private function raw(UserInterface $user, string $field): mixed {
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
      if ($node instanceof EntityInterface && $node->label() !== 'Other') {
        return $node->label();
      }
    }
    return $this->str($user, 'field_institution');
  }

  /**
   * Resolves the user's allocated CiDeR resources to {name, slug} pairs.
   *
   * @return array<int, array{name: string, slug: string|null}>
   *   The resources, in field order.
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
  private function resourceSlug(FieldableEntityInterface $node): ?string {
    return ($node->hasField('field_access_global_resource_id')
            && !$node->get('field_access_global_resource_id')->isEmpty())
      ? (string) $node->get('field_access_global_resource_id')->value : NULL;
  }

}
