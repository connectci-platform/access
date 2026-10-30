<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access_misc\Kernel\Stub\SiteToolsStub;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests program-scoped delete access to project submissions.
 *
 * D8-2892: the pascience_manager role may delete project submissions whose
 * Program (region) is PA Science (933), and no other program's. Access is
 * checked end to end through $submission->access('delete'), so the webform
 * access handler and access_misc_webform_submission_access() both run.
 *
 * access_misc is enabled so its hook is collected at container build time;
 * its sitetools service is replaced with a stub in register().
 *
 * @coversDefaultClass \Drupal\access_misc\Access\ProjectSubmissionDeleteAccess
 * @group access_misc
 */
class ProjectSubmissionDeleteAccessTest extends KernelTestBase {

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
    // access_misc provides the hook under test; the others are container
    // dependencies of its services, as in MailAlterReplyToTest.
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform']);

    // Burn uid 1 so test users are not super users.
    $this->createUser();

    foreach (['pascience_manager', 'administrator'] as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->save();
    }

    foreach (['project' => TRUE, 'other_form' => FALSE] as $id => $is_project) {
      Webform::create([
        'id' => $id,
        'title' => $id,
        'elements' => "region:\n  '#type': webform_entity_checkboxes\n  '#title': Program\n  '#target_type': taxonomy_term\n  '#selection_handler': default\n  '#selection_settings':\n    target_bundles:\n      region: region\n",
        'access' => [
          'delete_any' => ['roles' => ['administrator'], 'users' => [], 'permissions' => []],
        ],
      ])->save();
    }
  }

  /**
   * Creates a submission.
   */
  protected function submission(string $webform_id, $region): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => $webform_id,
      'data' => ['region' => $region],
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Creates a user with the given roles.
   */
  protected function userWithRoles(array $roles) {
    $user = $this->createUser();
    foreach ($roles as $role) {
      $user->addRole($role);
    }
    $user->save();
    return $user;
  }

  /**
   * The PA Science manager can delete a PA Science project.
   */
  public function testManagerCanDeletePaScienceProject(): void {
    $manager = $this->userWithRoles(['pascience_manager']);
    $this->assertTrue($this->submission('project', ['933'])->access('delete', $manager));
    // A scalar region value is handled too.
    $this->assertTrue($this->submission('project', '933')->access('delete', $manager));
  }

  /**
   * The PA Science manager cannot delete another program's project.
   */
  public function testManagerCannotDeleteOtherProgramProject(): void {
    $manager = $this->userWithRoles(['pascience_manager']);
    $this->assertFalse($this->submission('project', ['323'])->access('delete', $manager));
  }

  /**
   * A project shared with another program cannot be deleted by the manager.
   */
  public function testManagerCannotDeleteSharedProject(): void {
    $manager = $this->userWithRoles(['pascience_manager']);
    $this->assertFalse($this->submission('project', ['933', '323'])->access('delete', $manager));
  }

  /**
   * A project with no region cannot be deleted by the manager.
   */
  public function testManagerCannotDeleteProjectWithoutRegion(): void {
    $manager = $this->userWithRoles(['pascience_manager']);
    $this->assertFalse($this->submission('project', [])->access('delete', $manager));
  }

  /**
   * A user without the role cannot delete a PA Science project.
   */
  public function testUserWithoutRoleCannotDelete(): void {
    $user = $this->userWithRoles([]);
    $this->assertFalse($this->submission('project', ['933'])->access('delete', $user));
  }

  /**
   * The role gets no delete access to submissions of other webforms.
   */
  public function testNonProjectWebformIsNotGranted(): void {
    $manager = $this->userWithRoles(['pascience_manager']);
    $this->assertFalse($this->submission('other_form', ['933'])->access('delete', $manager));
  }

  /**
   * Administrators keep delete access through the webform's delete_any rule.
   */
  public function testAdministratorCanStillDelete(): void {
    $admin = $this->userWithRoles(['administrator']);
    $this->assertTrue($this->submission('project', ['323'])->access('delete', $admin));
  }

  /**
   * The service never forbids, and varies by role.
   */
  public function testServiceIsNeutralNotForbidden(): void {
    $user = $this->userWithRoles([]);
    $result = $this->container->get('access_misc.project_submission_delete_access')
      ->access($this->submission('project', ['933']), $user);
    $this->assertTrue($result->isNeutral());
    $this->assertContains('user.roles', $result->getCacheContexts());
  }

}
