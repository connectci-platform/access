<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

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
 * Tests view access to project submissions.
 *
 * D8-3101: the project webform's view_any rule is limited to staff roles, and
 * published ("approved") projects plus projects that list the user as mentor or
 * student stay viewable through access_misc. The real exported access rules
 * are applied. Both entity access and query level access (views) are checked.
 *
 * @coversDefaultClass \Drupal\access_misc\Access\ProjectSubmissionViewAccess
 * @group access_misc
 */
class ProjectSubmissionViewAccessTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->register('access_misc.sitetools', SiteToolsStub::class);
  }

  /**
   * Submission ids keyed by label.
   *
   * @var int[]
   */
  protected array $sids = [];

  /**
   * Users keyed by label.
   *
   * @var \Drupal\Core\Session\AccountInterface[]
   */
  protected array $users = [];

  /**
   * Finds the config sync directory relative to this file.
   */
  protected function getSyncDir(): string {
    $dir = __DIR__;
    while (($parent = dirname($dir)) !== $dir) {
      $candidate = $dir . '/sites/default/config/default';
      if (is_dir($candidate)) {
        return $candidate;
      }
      $dir = $parent;
    }
    $this->markTestSkipped('Config sync directory sites/default/config/default not found; the access sub-repo may be checked out standalone.');
  }

  /**
   * Loads the exported project webform config.
   */
  protected function loadProjectConfig(): array {
    $file = $this->getSyncDir() . '/webform.webform.project.yml';
    $this->assertFileExists($file);
    return Yaml::parse(file_get_contents($file));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $config = $this->loadProjectConfig();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform']);

    // Burn uid 1 so test users are not super users.
    $this->createUser();

    $rids = [
      RoleInterface::ANONYMOUS_ID,
      RoleInterface::AUTHENTICATED_ID,
      'careers_sc',
      'pascience_manager',
      'administrator',
    ];
    foreach ($rids as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->save();
    }
    // As in production (user.role.authenticated.yml).
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['view own webform submission']);

    $elements = "approved:\n  '#type': checkbox\n  '#title': Approved\n"
      . "mentor:\n  '#type': entity_autocomplete\n  '#title': Mentor\n  '#multiple': true\n  '#target_type': user\n"
      . "student:\n  '#type': entity_autocomplete\n  '#title': Student\n  '#multiple': true\n  '#target_type': user\n";
    $webform = Webform::create(['id' => 'project', 'title' => 'Project', 'elements' => $elements]);
    $webform->setAccessRules($config['access'])->save();

    foreach (['owner', 'mentor', 'student', 'other', 'manager'] as $label) {
      $this->users[$label] = $this->createUser();
    }
    $this->users['manager']->addRole('pascience_manager')->save();
    $this->users['anonymous'] = new AnonymousUserSession();

    $this->sids['approved'] = $this->submission(1, [])->id();
    $this->sids['hidden'] = $this->submission(0, [])->id();
    $this->sids['mentored'] = $this->submission(0, ['mentor' => [$this->users['mentor']->id()]])->id();
    $this->sids['studented'] = $this->submission(0, ['student' => [$this->users['student']->id()]])->id();
  }

  /**
   * Creates a project submission owned by the owner user.
   */
  protected function submission(int $approved, array $extra): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => 'project',
      'uid' => $this->users['owner']->id(),
      'data' => ['approved' => $approved] + $extra,
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Loads a submission by label.
   */
  protected function load(string $label): WebformSubmission {
    return WebformSubmission::load($this->sids[$label]);
  }

  /**
   * Runs an access-checked entity query as the user.
   *
   * @return int[]
   *   Sorted submission ids.
   */
  protected function visibleSids(string $user): array {
    $this->container->get('current_user')->setAccount($this->users[$user]);
    $sids = array_values(\Drupal::entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute());
    $sids = array_map('intval', $sids);
    sort($sids);
    return $sids;
  }

  /**
   * Sorted ids for the given labels.
   */
  protected function sidsOf(array $labels): array {
    $sids = array_map(fn ($label) => (int) $this->sids[$label], $labels);
    sort($sids);
    return $sids;
  }

  /**
   * The exported view_any rule is limited to staff roles.
   */
  public function testExportedViewAnyRoles(): void {
    $roles = $this->loadProjectConfig()['access']['view_any']['roles'];
    $this->assertSame(['careers_sc', 'pascience_manager'], array_values($roles));
  }

  /**
   * Unapproved projects are visible only to owner, members and staff.
   */
  public function testUnapprovedEntityAccess(): void {
    $hidden = $this->load('hidden');
    $this->assertFalse($hidden->access('view', $this->users['anonymous']));
    $this->assertFalse($hidden->access('view', $this->users['other']));
    $this->assertTrue($hidden->access('view', $this->users['owner']));
    $this->assertTrue($hidden->access('view', $this->users['manager']));
    $this->assertTrue($this->load('mentored')->access('view', $this->users['mentor']));
    $this->assertTrue($this->load('studented')->access('view', $this->users['student']));
    // Membership is per project.
    $this->assertFalse($this->load('mentored')->access('view', $this->users['student']));
    $this->assertFalse($hidden->access('view', $this->users['mentor']));
  }

  /**
   * Approved projects are visible to everyone.
   */
  public function testApprovedEntityAccess(): void {
    $approved = $this->load('approved');
    $this->assertTrue($approved->access('view', $this->users['anonymous']));
    $this->assertTrue($approved->access('view', $this->users['other']));
  }

  /**
   * Query level access matches entity access.
   */
  public function testQueryAccess(): void {
    $this->assertSame($this->sidsOf(['approved']), $this->visibleSids('anonymous'));
    $this->assertSame($this->sidsOf(['approved']), $this->visibleSids('other'));
    $this->assertSame($this->sidsOf(['approved', 'mentored']), $this->visibleSids('mentor'));
    $this->assertSame($this->sidsOf(['approved', 'studented']), $this->visibleSids('student'));
    $this->assertSame($this->sidsOf(['approved', 'hidden', 'mentored', 'studented']), $this->visibleSids('owner'));
    $this->assertSame($this->sidsOf(['approved', 'hidden', 'mentored', 'studented']), $this->visibleSids('manager'));
  }

  /**
   * The service never forbids and does not grant other operations.
   */
  public function testServiceNeverForbids(): void {
    $result = $this->container->get('access_misc.project_submission_view_access')
      ->access($this->load('hidden'), $this->users['other']);
    $this->assertTrue($result->isNeutral());
    $this->assertFalse($this->load('approved')->access('update', $this->users['other']));
  }

}
