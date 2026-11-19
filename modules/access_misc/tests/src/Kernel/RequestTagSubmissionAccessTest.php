<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access_misc\Kernel\Stub\SiteToolsStub;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests view access to request_tag submissions with the real access rules.
 *
 * D8-3101: the request_tag webform's view_any rule is limited to cnctci_pm.
 * Owners keep access to their own submissions through view_own.
 *
 * @group access_misc
 */
class RequestTagSubmissionAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'path',
    'path_alias',
    'taxonomy',
    'webform',
    'domain',
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * The access rules applied to the webform.
   *
   * @var array<string, mixed>
   */
  protected array $accessRules;

  /**
   * Users keyed by label.
   *
   * @var \Drupal\Core\Session\AccountInterface[]
   */
  protected array $users = [];

  /**
   * The submission owned by the owner user.
   */
  protected int $sid;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->register('access_misc.sitetools', SiteToolsStub::class);
  }

  /**
   * Finds the config sync directory relative to this file.
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
   * Returns the access rules used when the sync directory is absent.
   *
   * These mirror webform.webform.request_tag.yml.
   *
   * @return array<string, array<string, array<int, string>>>
   *   The access rules keyed by operation.
   */
  protected function getFallbackAccessRules(): array {
    $roles = [
      'create' => ['authenticated'],
      'view_any' => ['cnctci_pm'],
      'update_any' => [],
      'delete_any' => [],
      'purge_any' => [],
      'view_own' => ['authenticated'],
      'update_own' => [],
      'delete_own' => ['authenticated'],
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $sync_dir = $this->getSyncDir();
    if ($sync_dir !== NULL) {
      $file = $sync_dir . '/webform.webform.request_tag.yml';
      $this->assertFileExists($file);
      $this->accessRules = Yaml::parse(file_get_contents($file))['access'];
    }
    else {
      $this->accessRules = $this->getFallbackAccessRules();
    }

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform']);

    // Burn uid 1 so test users are not super users.
    $this->createUser();

    foreach ([
      RoleInterface::ANONYMOUS_ID,
      RoleInterface::AUTHENTICATED_ID,
      'cnctci_pm',
      'administrator',
    ] as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->save();
    }
    // As in production (user.role.authenticated.yml).
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['view own webform submission']);

    $elements = "name:\n  '#type': textfield\n  '#title': Name\n";
    $webform = Webform::create(['id' => 'request_tag', 'title' => 'Request tag', 'elements' => $elements]);
    $webform->setAccessRules($this->accessRules)->save();

    $this->users['owner'] = $this->createUser();
    $this->users['other'] = $this->createUser();
    $this->users['pm'] = $this->createUser();
    $this->users['pm']->addRole('cnctci_pm')->save();
    $this->users['anonymous'] = new AnonymousUserSession();

    $submission = WebformSubmission::create([
      'webform_id' => 'request_tag',
      'uid' => $this->users['owner']->id(),
      'data' => ['name' => 'Tag'],
    ]);
    $submission->save();
    $this->sid = (int) $submission->id();
  }

  /**
   * Checks an operation on a freshly loaded submission.
   */
  protected function can(string $operation, string $user): bool {
    // Reload so no per-entity access cache leaks between assertions.
    $submission = WebformSubmission::load($this->sid);
    return $submission->access($operation, $this->users[$user]);
  }

  /**
   * Runs an access-checked entity query as the user.
   *
   * @return int[]
   *   Sorted submission ids.
   */
  protected function visibleSids(string $user): array {
    $account = $this->users[$user];
    assert($account instanceof AccountInterface);
    $this->container->get('current_user')->setAccount($account);
    $sids = array_values(\Drupal::entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute());
    $sids = array_map('intval', $sids);
    sort($sids);
    return $sids;
  }

  /**
   * The exported view_any rule is limited to cnctci_pm.
   */
  public function testExportedViewAnyRoles(): void {
    if ($this->getSyncDir() === NULL) {
      $this->markTestSkipped('Config sync directory sites/default/config/default not found; the access sub-repo may be checked out standalone.');
    }
    $roles = $this->accessRules['view_any']['roles'];
    sort($roles);
    $this->assertSame(['cnctci_pm'], $roles);
  }

  /**
   * Only the owner and cnctci_pm may view a submission.
   */
  public function testEntityAccess(): void {
    $this->assertFalse($this->can('view', 'anonymous'), 'anonymous');
    $this->assertFalse($this->can('view', 'other'), 'other authenticated user');
    $this->assertTrue($this->can('view', 'owner'), 'owner');
    $this->assertTrue($this->can('view', 'pm'), 'cnctci_pm');
  }

  /**
   * Query level access matches entity access.
   */
  public function testQueryAccess(): void {
    $this->assertSame([], $this->visibleSids('anonymous'), 'anonymous');
    $this->assertSame([], $this->visibleSids('other'), 'other authenticated user');
    $this->assertSame([$this->sid], $this->visibleSids('owner'), 'owner');
    $this->assertSame([$this->sid], $this->visibleSids('pm'), 'cnctci_pm');
  }

  /**
   * Other users cannot change someone else's submission.
   */
  public function testOthersCannotModify(): void {
    foreach (['update', 'delete'] as $operation) {
      $this->assertFalse($this->can($operation, 'anonymous'), "anonymous $operation");
      $this->assertFalse($this->can($operation, 'other'), "other $operation");
    }
  }

  /**
   * Owners may delete (delete_own) but not update their submission.
   */
  public function testOwnerOperations(): void {
    $this->assertTrue($this->can('delete', 'owner'), 'delete_own grants delete');
    $this->assertFalse($this->can('update', 'owner'), 'update_own is empty');
  }

}
