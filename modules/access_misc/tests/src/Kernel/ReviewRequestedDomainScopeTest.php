<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\access_misc\Services\NotificationDomainScoper;
use Drupal\content_moderation_notifications\Entity\ContentModerationNotification;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

/**
 * Tests domain scoping of content_moderation_notifications recipients.
 *
 * D8-2809: content_moderation_notifications emails every active holder of a
 * notification's roles, with no awareness of Domain Access. match_pm and
 * ondemand_pm are scoped roles: their holders should only be notified about
 * content assigned to their own ACCESS sub-site (amp_cyberinfrastructure_org
 * and openondemand_cyberinfrastructure_org respectively).
 * NotificationDomainScoper::scopeRecipients() implements the filtering, and
 * access_misc_content_moderation_notification_mail_data_alter() wires it into
 * contrib's hook_content_moderation_notification_mail_data_alter().
 *
 * @coversDefaultClass \Drupal\access_misc\Services\NotificationDomainScoper
 * @group access_misc
 */
class ReviewRequestedDomainScopeTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * The ACCESS domain id, matching ROLE_DOMAIN_SCOPE.
   */
  private const DOMAIN_ACCESS = 'amp_cyberinfrastructure_org';

  /**
   * The OnDemand domain id, matching ROLE_DOMAIN_SCOPE.
   */
  private const DOMAIN_OOD = 'openondemand_cyberinfrastructure_org';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'workflows',
    'content_moderation',
    'content_moderation_notifications',
    'node',
    'domain',
    // Container dependencies, not assertions of this test: access_misc's
    // services.yml wires a couple of its event subscribers against
    // access.access_id_resolver, and access's own eligibility_check_subscriber
    // wires against access_affinitygroup.allocations_client (which needs
    // key). Same set AccessRegistrantAccessControlHandlerTest carries, for
    // the same reason.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * A user holding only the match_pm role.
   */
  protected User $matchPmUser;

  /**
   * A user holding only the ondemand_pm role.
   */
  protected User $ondemandPmUser;

  /**
   * A user holding BOTH match_pm and ondemand_pm.
   */
  protected User $bothRolesUser;

  /**
   * A user holding an unscoped role (appverse_pm).
   */
  protected User $appversePmUser;

  /**
   * A user holding match_pm, used as a content owner.
   */
  protected User $ownerMatchPmUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);
    $this->installConfig(['user']);
    $this->installConfig(['domain']);

    // Every fixture user is authenticated; grant the authenticated role the
    // permission Node's default access control handler requires for 'view',
    // so entity->access('view', $role_user) inside contrib's Notification
    // service (and our own fixtures) resolves as it does in production.
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['access content']);

    NodeType::create(['type' => 'access_news', 'name' => 'ACCESS News'])->save();

    // field_domain_access mirrors the site's real node.access_news field: a
    // multi-valued entity_reference to the domain entity type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_domain_access',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'domain'],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_domain_access',
      'bundle' => 'access_news',
      'label' => 'Domain access',
    ])->save();

    Domain::create([
      'id' => self::DOMAIN_ACCESS,
      'hostname' => 'amp.example.com',
      'name' => 'ACCESS',
      'scheme' => 'https',
      'status' => 1,
    ])->save();
    Domain::create([
      'id' => self::DOMAIN_OOD,
      'hostname' => 'ood.example.com',
      'name' => 'Open OnDemand',
      'scheme' => 'https',
      'status' => 1,
    ])->save();

    foreach (['match_pm', 'ondemand_pm', 'appverse_pm'] as $roleId) {
      Role::create(['id' => $roleId, 'label' => $roleId])->save();
    }

    $this->matchPmUser = $this->createTestUser(['match_pm'], 'match-pm@example.com');
    $this->ondemandPmUser = $this->createTestUser(['ondemand_pm'], 'ondemand-pm@example.com');
    $this->bothRolesUser = $this->createTestUser(['match_pm', 'ondemand_pm'], 'both-roles@example.com');
    $this->appversePmUser = $this->createTestUser(['appverse_pm'], 'appverse-pm@example.com');
    $this->ownerMatchPmUser = $this->createTestUser(['match_pm'], 'owner-match-pm@example.com');
  }

  /**
   * OOD-domain content: ondemand_pm is included, match_pm is excluded.
   */
  public function testOodDomainContentIncludesOndemandPmExcludesMatchPm(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);
    $to = [$this->matchPmUser->getEmail(), $this->ondemandPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([$this->ondemandPmUser->getEmail()], $filtered);
  }

  /**
   * ACCESS-domain content: match_pm is included, ondemand_pm is excluded.
   */
  public function testAccessDomainContentIncludesMatchPmExcludesOndemandPm(): void {
    $node = $this->createTestNode([self::DOMAIN_ACCESS]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);
    $to = [$this->matchPmUser->getEmail(), $this->ondemandPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([$this->matchPmUser->getEmail()], $filtered);
  }

  /**
   * Content assigned to both domains keeps both scoped roles' recipients.
   */
  public function testContentOnBothDomainsIncludesBothRoles(): void {
    $node = $this->createTestNode([self::DOMAIN_ACCESS, self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);
    $to = [$this->matchPmUser->getEmail(), $this->ondemandPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame($to, $filtered);
  }

  /**
   * Content with no domain excludes both scoped roles.
   */
  public function testContentWithNoDomainExcludesBothScopedRoles(): void {
    $node = $this->createTestNode([]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);
    $to = [$this->matchPmUser->getEmail(), $this->ondemandPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([], $filtered);
  }

  /**
   * A notification whose roles are all unscoped is unaffected.
   */
  public function testUnscopedRoleNotificationUnaffectedByDomainScoping(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('appverse_notify', ['appverse_pm']);
    $to = [$this->appversePmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame($to, $filtered);
  }

  /**
   * A notification that also targets the authenticated role is not scoped.
   *
   * Contrib treats the authenticated role as "every active user," so any
   * holder of a scoped role already qualifies for the mail independently of
   * that role — scopeRecipients() short-circuits and returns $to unchanged.
   */
  public function testAuthenticatedRoleNotificationIsNotScoped(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested_auth', [RoleInterface::AUTHENTICATED_ID, 'match_pm']);
    $to = [$this->matchPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame($to, $filtered);
  }

  /**
   * A user holding BOTH scoped roles is kept when either role is in scope.
   */
  public function testUserHoldingBothScopedRolesIsKept(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);
    $to = [$this->bothRolesUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([$this->bothRolesUser->getEmail()], $filtered);
  }

  /**
   * An author notification keeps the owner even if their role is out of scope.
   */
  public function testAuthorNotificationKeepsOwnerHoldingOutOfScopeRole(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD], (int) $this->ownerMatchPmUser->id());
    $notification = $this->createTestNotification('review_requested_author', ['match_pm'], TRUE);
    $to = [$this->ownerMatchPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([$this->ownerMatchPmUser->getEmail()], $filtered);
  }

  /**
   * A literal ad hoc email matching an out-of-scope user's address is kept.
   */
  public function testLiteralAdhocEmailMatchingOutOfScopeUserIsKept(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested_adhoc', ['match_pm'], FALSE, $this->matchPmUser->getEmail());
    $to = [$this->matchPmUser->getEmail()];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([$this->matchPmUser->getEmail()], $filtered);
  }

  /**
   * Exclusion matches case-insensitively.
   */
  public function testCaseInsensitiveEmailMatchingIsExcluded(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested', ['match_pm']);
    $to = [mb_strtoupper($this->matchPmUser->getEmail())];

    $filtered = $this->scoper()->scopeRecipients($node, $notification, $to);

    $this->assertSame([], $filtered);
  }

  /**
   * The mail_data_alter hook wiring filters the same way as the service.
   */
  public function testHookFiltersMailDataSameAsService(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);
    $data = [
      'notification' => $notification,
      'to' => [$this->matchPmUser->getEmail(), $this->ondemandPmUser->getEmail()],
    ];

    \Drupal::moduleHandler()->alter('content_moderation_notification_mail_data', $node, $data);

    $this->assertSame([$this->ondemandPmUser->getEmail()], $data['to']);
  }

  /**
   * The full pipeline: Notification service, alter hook, mail collector.
   *
   * The real Notification service builds recipients, the hook filters them,
   * and the collected mail's Bcc header reflects only the in-scope
   * recipient. Exercises the full production pipeline (contrib's
   * Notification::sendNotification(), through the alter hook, to the test
   * mail collector), rather than calling the scoper service directly,
   * proving the whole chain is wired correctly end to end.
   */
  public function testFullNotificationPipelineFiltersRecipientsViaMailCollector(): void {
    $node = $this->createTestNode([self::DOMAIN_OOD]);
    $notification = $this->createTestNotification('review_requested', ['match_pm', 'ondemand_pm']);

    \Drupal::service('content_moderation_notifications.notification')
      ->sendNotification($node, [$notification]);

    $mails = $this->getMails();
    $this->assertCount(1, $mails, 'One notification email was sent.');
    $bcc = $mails[0]['params']['headers']['Bcc'] ?? '';
    $this->assertStringContainsString($this->ondemandPmUser->getEmail(), $bcc);
    $this->assertStringNotContainsString($this->matchPmUser->getEmail(), $bcc);
  }

  /**
   * Returns the domain scoper service under test.
   */
  private function scoper(): NotificationDomainScoper {
    return \Drupal::service('access_misc.notification_domain_scoper');
  }

  /**
   * Creates an active user with the given roles and email.
   *
   * @param string[] $roles
   *   Role machine names.
   * @param string $mail
   *   The user's email address.
   */
  private function createTestUser(array $roles, string $mail): User {
    $user = User::create([
      'name' => $mail,
      'mail' => $mail,
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a saved access_news node assigned to the given domain ids.
   *
   * @param string[] $domainIds
   *   Domain ids to assign via field_domain_access; empty for no domain.
   * @param int $ownerUid
   *   The node owner's user id.
   */
  private function createTestNode(array $domainIds, int $ownerUid = 0): Node {
    $node = Node::create([
      'type' => 'access_news',
      'title' => 'Test article',
      'status' => 1,
      'uid' => $ownerUid,
      'field_domain_access' => array_map(static fn (string $id) => ['target_id' => $id], $domainIds),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a saved ContentModerationNotification config entity.
   *
   * Mirrors content_moderation_notifications.content_moderation_notification.
   * review_requested.yml, with a caller-supplied id/roles/author/emails.
   *
   * @param string $id
   *   The config entity id.
   * @param string[] $roles
   *   Role machine names the notification is sent to.
   * @param bool $author
   *   Whether the notification is also sent to the entity owner.
   * @param string $emails
   *   Literal ad hoc emails (comma/newline separated).
   */
  private function createTestNotification(string $id, array $roles, bool $author = FALSE, string $emails = ''): ContentModerationNotification {
    $notification = ContentModerationNotification::create([
      'id' => $id,
      'label' => $id,
      'workflow' => 'editorial',
      'transitions' => ['send_for_review' => 'send_for_review'],
      'roles' => array_combine($roles, $roles),
      'author' => $author,
      'site_mail' => TRUE,
      'user_fields' => [],
      'emails' => $emails,
      'subject' => 'Please review',
      'body' => ['value' => 'Please review this content.', 'format' => 'plain_text'],
    ]);
    $notification->save();
    return $notification;
  }

}
