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
 * Domain Access module. Some roles (match_pm, ondemand_pm) are scoped to a
 * single ACCESS sub-site by job function, but role holders do NOT carry a
 * matching field_domain_access value on their user accounts, so recipients
 * can't be filtered by comparing the user's own domain assignment. Instead
 * we maintain an explicit role => allowed domain ids map and filter the
 * "to" list built by \Drupal\content_moderation_notifications\Notification
 * so that scoped roles only receive notifications for content assigned to
 * their domain (D8-2809).
 */
class NotificationDomainScoper {

  /**
   * Map of role id to the domain ids that role should receive mail for.
   *
   * Roles not present in this map are unaffected by domain scoping.
   */
  public const ROLE_DOMAIN_SCOPE = [
    'match_pm' => ['amp_cyberinfrastructure_org'],
    'ondemand_pm' => ['openondemand_cyberinfrastructure_org'],
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
   * Filters a notification's recipient list by role-to-domain scoping.
   *
   * Removes recipients who only qualify via a role that is scoped to a
   * domain the entity is not assigned to (see ROLE_DOMAIN_SCOPE), while
   * preserving recipients who also qualify another way (as the entity
   * owner, via an unscoped or in-scope role, or via a literal ad hoc
   * address on the notification).
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
    $scoped_roles = array_intersect($notification->getRoleIds(), array_keys(self::ROLE_DOMAIN_SCOPE));
    if (!$scoped_roles) {
      return $to;
    }

    // Contrib treats the authenticated role as "every active user," so any
    // holder of a scoped role already qualifies for the mail independently
    // of that role; there is nothing to scope.
    if (in_array(RoleInterface::AUTHENTICATED_ID, $notification->getRoleIds(), TRUE)) {
      return $to;
    }

    $content_domains = $this->getEntityDomainIds($entity);

    $out_of_scope_roles = [];
    foreach ($scoped_roles as $role) {
      if (!array_intersect(self::ROLE_DOMAIN_SCOPE[$role], $content_domains)) {
        $out_of_scope_roles[] = $role;
      }
    }

    if (!$out_of_scope_roles) {
      return $to;
    }

    $keep = $this->buildKeepSet($entity, $notification, $out_of_scope_roles);

    $user_storage = $this->entityTypeManager->getStorage('user');
    $exclude = [];
    foreach ($out_of_scope_roles as $role) {
      /** @var \Drupal\user\UserInterface[] $role_users */
      $role_users = $user_storage->loadByProperties(['roles' => $role]);
      foreach ($role_users as $role_user) {
        $email = $role_user->getEmail();
        if ($email) {
          $exclude[mb_strtolower($email)] = TRUE;
        }
      }
    }

    if (!$exclude) {
      return $to;
    }

    $filtered = array_filter($to, function ($email) use ($exclude, $keep) {
      if (!is_string($email) || $email === '') {
        return TRUE;
      }
      $lower = mb_strtolower($email);
      if (!isset($exclude[$lower])) {
        return TRUE;
      }
      return isset($keep[$lower]);
    });

    return array_values($filtered);
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
   *
   * @return array<string, bool>
   *   A set (lowercased email => TRUE) of protected addresses.
   */
  protected function buildKeepSet(EntityInterface $entity, ContentModerationNotificationInterface $notification, array $out_of_scope_roles): array {
    $keep = [];

    if ($notification->sendToAuthor() && $entity instanceof EntityOwnerInterface) {
      $owner_email = $entity->getOwner()->getEmail();
      if ($owner_email) {
        $keep[mb_strtolower($owner_email)] = TRUE;
      }
    }

    $in_scope_roles = array_diff($notification->getRoleIds(), $out_of_scope_roles);
    if ($in_scope_roles) {
      $user_storage = $this->entityTypeManager->getStorage('user');
      foreach ($in_scope_roles as $role) {
        /** @var \Drupal\user\UserInterface[] $role_users */
        $role_users = $user_storage->loadByProperties(['roles' => $role]);
        foreach ($role_users as $role_user) {
          $email = $role_user->getEmail();
          if ($email) {
            $keep[mb_strtolower($email)] = TRUE;
          }
        }
      }
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
