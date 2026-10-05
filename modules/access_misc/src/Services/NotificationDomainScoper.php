<?php

namespace Drupal\access_misc\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\content_moderation_notifications\ContentModerationNotificationInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleInterface;

/**
 * Restricts contrib content moderation notification recipients by domain.
 *
 * The contrib content_moderation_notifications module emails every active
 * holder of any role listed on a notification, with no awareness of the
 * Domain Access module. Some roles (match_pm, ondemand_pm, ...) are scoped to
 * a single sub-site by job function, but role holders do NOT carry a
 * matching field_domain_access value on their user accounts, so recipients
 * can't be filtered by comparing the user's own domain assignment. Instead
 * we maintain an explicit domain => roles map and filter the "to" list built
 * by \Drupal\content_moderation_notifications\Notification so that each
 * domain's roles only receive the notification for content assigned to that
 * domain (D8-2809).
 *
 * The map is keyed per notification on purpose. A role-global map would
 * rescope roles such as match_pm on every notification that lists them
 * (e.g. engagement_draft, engagement_review_requested), which is not wanted.
 *
 * Content on a domain that is not in the map (or with no domain at all) is
 * routed to the notification's fallback roles, so a renamed or new domain
 * does not orphan its notifications. Unmapped domains are deliberately not
 * enumerated. As a last guard, scoping never empties a non-empty recipient
 * list.
 */
class NotificationDomainScoper {

  /**
   * Per-notification domain scoping, keyed by notification id.
   *
   * 'domains' maps a domain id to the role ids that should receive the
   * notification for content on that domain. 'fallback' lists the role ids
   * that receive it for content with no domain, or on any domain missing
   * from 'domains'. Notifications not listed here are never scoped.
   */
  public const NOTIFICATION_SCOPE = [
    'review_requested' => [
      'domains' => [
        'amp_cyberinfrastructure_org' => ['match_pm'],
        'openondemand_cyberinfrastructure_org' => ['ondemand_pm'],
        'campuschampions_cyberinfrastructure_org' => ['campuschampionsadmin'],
        'ccmnet_org' => ['ccmnet_pm'],
        'coco_cyberinfrastructure_org' => ['coco_pm'],
        'pasciencedmz_connectci_org' => ['pascience_manager'],
      ],
      'fallback' => ['site_developer'],
    ],
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the domain ids the given entity is assigned to.
   *
   * Different entity types store their Domain Access value under different
   * field names: nodes (e.g. access_news, match_engagement) use
   * field_domain_access, while eventseries/eventinstance entities use
   * domain_access. field_domain_all_affiliates and field_domain_source are
   * intentionally not considered here.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being evaluated.
   *
   * @return string[]
   *   The domain ids the entity is assigned to, or an empty array if none.
   */
  public function getEntityDomainIds(EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }

    foreach (['field_domain_access', 'domain_access'] as $field_name) {
      if ($entity->hasField($field_name)) {
        $domain_ids = [];
        foreach ($entity->get($field_name) as $item) {
          if (!empty($item->target_id)) {
            $domain_ids[] = $item->target_id;
          }
        }
        if ($domain_ids) {
          return $domain_ids;
        }
      }
    }

    return [];
  }

  /**
   * Filters a notification's recipient list by domain scoping.
   *
   * Only notifications listed in NOTIFICATION_SCOPE are touched. Removes
   * recipients who only qualify via a managed role (a mapped or fallback
   * role) that is out of scope for the entity's domains, while preserving
   * recipients who also qualify another way (as the entity owner, via an
   * unmanaged or in-scope role, or via a literal ad hoc address on the
   * notification). Content with no domain, or on any domain missing from the
   * map, also brings the fallback roles into scope. If filtering would leave
   * nobody, the fallback role holders from the original list are returned
   * instead, and failing that the original list; a non-empty list is never
   * emptied.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the notification is about.
   * @param \Drupal\content_moderation_notifications\ContentModerationNotificationInterface $notification
   *   The notification being sent.
   * @param string[] $to
   *   The recipient email addresses built by contrib.
   *
   * @return string[]
   *   The filtered, re-indexed recipient list.
   */
  public function scopeRecipients(EntityInterface $entity, ContentModerationNotificationInterface $notification, array $to): array {
    $scope = self::NOTIFICATION_SCOPE[$notification->id()] ?? NULL;
    if (!$scope) {
      return $to;
    }

    $notification_roles = $notification->getRoleIds();

    // Contrib treats the authenticated role as "every active user," so any
    // holder of a managed role already qualifies for the mail independently
    // of that role; there is nothing to scope.
    if (in_array(RoleInterface::AUTHENTICATED_ID, $notification_roles, TRUE)) {
      return $to;
    }

    $mapped_roles = array_merge(...array_values($scope['domains']));
    $managed_roles = array_intersect(array_unique(array_merge($mapped_roles, $scope['fallback'])), $notification_roles);
    if (!$managed_roles) {
      return $to;
    }

    $content_domains = $this->getEntityDomainIds($entity);

    $in_scope_roles = [];
    $use_fallback = !$content_domains;
    foreach ($content_domains as $domain_id) {
      if (isset($scope['domains'][$domain_id])) {
        $in_scope_roles = array_merge($in_scope_roles, $scope['domains'][$domain_id]);
      }
      else {
        $use_fallback = TRUE;
      }
    }
    if ($use_fallback) {
      $in_scope_roles = array_merge($in_scope_roles, $scope['fallback']);
    }

    $out_of_scope_roles = array_values(array_diff($managed_roles, $in_scope_roles));
    if (!$out_of_scope_roles) {
      return $to;
    }

    $role_emails = [];
    $keep = $this->buildKeepSet($entity, $notification, $out_of_scope_roles, $role_emails);

    $exclude = [];
    foreach ($out_of_scope_roles as $role) {
      $exclude += $this->getRoleEmails($role, $role_emails);
    }

    if (!$exclude) {
      return $to;
    }

    $filtered = array_values(array_filter($to, function ($email) use ($exclude, $keep) {
      if (!is_string($email) || $email === '') {
        return TRUE;
      }
      $lower = mb_strtolower($email);
      if (!isset($exclude[$lower])) {
        return TRUE;
      }
      return isset($keep[$lower]);
    }));

    if ($filtered) {
      return $filtered;
    }

    // Never leave a notification with no recipients: fall back to the
    // fallback role holders who were already on the list, then to the
    // original list.
    $fallback_emails = [];
    foreach ($scope['fallback'] as $role) {
      $fallback_emails += $this->getRoleEmails($role, $role_emails);
    }
    $fallback_to = array_values(array_filter($to, static fn ($email) => is_string($email) && isset($fallback_emails[mb_strtolower($email)])));

    return $fallback_to ?: $to;
  }

  /**
   * Gets the emails of a role's holders, loading each role at most once.
   *
   * @param string $role
   *   The role id.
   * @param array<string, array<string, bool>> $cache
   *   Per-call cache of role id => set of lowercased emails.
   *
   * @return array<string, bool>
   *   A set (lowercased email => TRUE) of the role holders' addresses.
   */
  protected function getRoleEmails(string $role, array &$cache): array {
    if (!isset($cache[$role])) {
      $cache[$role] = [];
      /** @var \Drupal\user\UserInterface[] $role_users */
      $role_users = $this->entityTypeManager->getStorage('user')->loadByProperties(['roles' => $role]);
      foreach ($role_users as $role_user) {
        $email = $role_user->getEmail();
        if ($email) {
          $cache[$role][mb_strtolower($email)] = TRUE;
        }
      }
    }
    return $cache[$role];
  }

  /**
   * Builds the set of emails that must never be removed during scoping.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the notification is about.
   * @param \Drupal\content_moderation_notifications\ContentModerationNotificationInterface $notification
   *   The notification being sent.
   * @param string[] $out_of_scope_roles
   *   The role ids that are out of scope for this content.
   * @param array<string, array<string, bool>> $role_emails
   *   Per-call cache of role id => emails, see getRoleEmails().
   *
   * @return array<string, bool>
   *   A set (lowercased email => TRUE) of protected addresses.
   */
  protected function buildKeepSet(EntityInterface $entity, ContentModerationNotificationInterface $notification, array $out_of_scope_roles, array &$role_emails): array {
    $keep = [];

    if ($notification->sendToAuthor() && $entity instanceof EntityOwnerInterface) {
      $owner_email = $entity->getOwner()->getEmail();
      if ($owner_email) {
        $keep[mb_strtolower($owner_email)] = TRUE;
      }
    }

    foreach (array_diff($notification->getRoleIds(), $out_of_scope_roles) as $role) {
      $keep += $this->getRoleEmails($role, $role_emails);
    }

    // Ad hoc addresses are a Twig template; only literal, already-resolved
    // email addresses can be honored here since we can't render Twig from
    // this service. Templated entries are left for contrib to resolve and
    // are simply not protected by this keep set.
    $adhoc = preg_split("/((\r?\n)|(\r\n?)|,)/", (string) $notification->getEmails());
    foreach ($adhoc as $email) {
      $email = trim($email);
      if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $keep[mb_strtolower($email)] = TRUE;
      }
    }

    return $keep;
  }

}
