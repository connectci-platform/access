<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests generic view access to resource submissions with the real access rules.
 *
 * The resource webform restricts view_any to administrators and KB PMs. Public
 * resources must stay visible through entity access and entity queries.
 *
 * @group access_cilink
 */
class KbResourceSubmissionAccessTest extends KbResourceKernelTestBase {

  /**
   * The exported access rules of the resource webform.
   *
   * @var array<string, mixed>
   */
  protected array $accessRules;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $sync_dir = $this->getSyncDir();
    if ($sync_dir !== NULL) {
      $file = $sync_dir . '/webform.webform.resource.yml';
      $this->assertFileExists($file);
      $config = Yaml::parse(file_get_contents($file));
      $this->accessRules = $config['access'];
    }
    else {
      $this->accessRules = $this->getFallbackAccessRules();
    }

    // As in production, authenticated users may view their own submissions.
    Role::load('authenticated')->grantPermission('view own webform submission')->save();

    $webform = Webform::load('resource');
    $webform->setAccessRules($this->accessRules)->save();
  }

  /**
   * Finds the exported config sync directory.
   *
   * @return string|null
   *   The directory, or NULL when the access sub-repo is checked out
   *   standalone and no site config is available.
   */
  protected function getSyncDir(): ?string {
    $dir = __DIR__;
    while (($parent = dirname($dir)) !== $dir) {
      $candidate = $dir . '/sites/default/config/default';
      if (is_dir($candidate)) {
        return $candidate;
      }
      $dir = $parent;
    }
    return NULL;
  }

  /**
   * Returns the resource access rules used when the sync directory is absent.
   *
   * These mirror webform.webform.resource.yml.
   *
   * @return array<string, array<string, array<int, string>>>
   *   The access rules keyed by operation.
   */
  protected function getFallbackAccessRules(): array {
    $roles = [
      'create' => ['authenticated'],
      'view_any' => ['administrator', 'kb_pm'],
      'update_any' => ['administrator', 'match_pm', 'kb_pm'],
      'delete_any' => ['administrator', 'match_pm', 'kb_pm'],
      'purge_any' => [],
      'view_own' => ['anonymous', 'authenticated'],
      'update_own' => ['authenticated'],
      'delete_own' => ['administrator'],
      'administer' => [],
      'test' => [],
      'configuration' => [],
    ];
    $rules = [];
    foreach ($roles as $operation => $operation_roles) {
      $rules[$operation] = [
        'roles' => $operation_roles,
        'users' => [],
        'permissions' => [],
      ];
    }
    return $rules;
  }

  /**
   * Tests the exported rules restrict view_any.
   */
  public function testExportedViewAnyRoles(): void {
    if ($this->getSyncDir() === NULL) {
      $this->markTestSkipped('Config sync directory sites/default/config/default not found; the access sub-repo may be checked out standalone.');
    }
    $roles = $this->accessRules['view_any']['roles'];
    sort($roles);
    $this->assertSame(['administrator', 'kb_pm'], $roles);
  }

  /**
   * Asserts entity view access for an account.
   */
  protected function assertView(bool $expected, WebformSubmission $submission, AccountInterface $account, string $message): void {
    // Reload so no per-entity access cache leaks between assertions.
    $submission = WebformSubmission::load($submission->id());
    $this->assertSame($expected, $submission->access('view', $account), $message);
  }

  /**
   * Returns the sids visible to an account through an entity query.
   *
   * @return int[]
   *   Sorted submission IDs.
   */
  protected function queryAs(AccountInterface $account): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($account);
    try {
      $sids = $this->container->get('entity_type.manager')
        ->getStorage('webform_submission')
        ->getQuery()
        ->accessCheck(TRUE)
        ->execute();
    }
    finally {
      $switcher->switchBack();
    }
    $sids = array_map('intval', array_values($sids));
    sort($sids);
    return $sids;
  }

  /**
   * Tests entity access for pending, private and public resources.
   */
  public function testEntityAccess(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $pm = $this->createUser(['kb_pm']);
    $admin = $this->createUser(['administrator']);
    $anonymous = new AnonymousUserSession();

    $pending = $this->createResource(['approved' => 0], (int) $owner->id());
    $pending_private = $this->createResource(['approved' => 0, 'private' => 1], (int) $owner->id());
    $private = $this->createResource(['private' => 1], (int) $owner->id());
    $public = $this->createResource([], (int) $owner->id());
    $no_private = $this->createResource(['private' => NULL], (int) $owner->id());

    foreach ([$pending, $pending_private, $private] as $submission) {
      $sid = $submission->id();
      $this->assertView(FALSE, $submission, $anonymous, "anonymous on $sid");
      $this->assertView(FALSE, $submission, $other, "non-owner on $sid");
      $this->assertView(TRUE, $submission, $owner, "owner on $sid");
      $this->assertView(TRUE, $submission, $pm, "kb_pm on $sid");
      $this->assertView(TRUE, $submission, $admin, "administrator on $sid");
    }

    foreach ([$public, $no_private] as $submission) {
      $sid = $submission->id();
      $this->assertView(TRUE, $submission, $anonymous, "anonymous on $sid");
      $this->assertView(TRUE, $submission, $other, "non-owner on $sid");
      $this->assertView(TRUE, $submission, $owner, "owner on $sid");
    }
  }

  /**
   * Tests entity query access.
   */
  public function testQueryAccess(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $pm = $this->createUser(['kb_pm']);
    $anonymous = new AnonymousUserSession();

    $pending = $this->createResource(['approved' => 0], (int) $owner->id());
    $private = $this->createResource(['private' => 1], (int) $owner->id());
    $public = $this->createResource([], (int) $owner->id());
    $no_private = $this->createResource(['private' => NULL], (int) $owner->id());

    $public_sids = [(int) $public->id(), (int) $no_private->id()];
    $all = [
      (int) $pending->id(),
      (int) $private->id(),
      (int) $public->id(),
      (int) $no_private->id(),
    ];
    sort($public_sids);
    sort($all);

    $this->assertSame($public_sids, $this->queryAs($anonymous), 'anonymous');
    $this->assertSame($public_sids, $this->queryAs($other), 'non-owner');
    // The owner also sees their own submissions via "view own".
    $this->assertSame($all, $this->queryAs($owner), 'owner');
    $this->assertSame($all, $this->queryAs($pm), 'kb_pm');
  }

}
