<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\access_misc\Routing\RouteSubscriber;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access_misc\Kernel\Stub\SiteToolsStub;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests route level access to raw resource and project submission pages.
 *
 * D8-3101: entity view access is granted to everyone for public items, so the
 * raw submission pages are restricted at the route level.
 *
 * @coversDefaultClass \Drupal\access_misc\Access\SubmissionPageAccess
 * @group access_misc
 */
class SubmissionPageAccessTest extends KernelTestBase {

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
   * Users keyed by label.
   *
   * @var \Drupal\Core\Session\AccountInterface[]
   */
  protected array $users = [];

  /**
   * Submissions keyed by label.
   *
   * @var \Drupal\webform\Entity\WebformSubmission[]
   */
  protected array $submissions = [];

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

    foreach ([
      RoleInterface::ANONYMOUS_ID,
      RoleInterface::AUTHENTICATED_ID,
      'kb_pm',
      'careers_sc',
      'pascience_manager',
      'administrator',
    ] as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->save();
    }
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['view own webform submission']);

    $approved = "approved:\n  '#type': checkbox\n  '#title': Approved\n";
    $members = "mentor:\n  '#type': entity_autocomplete\n  '#title': Mentor\n  '#multiple': true\n  '#target_type': user\n"
      . "student:\n  '#type': entity_autocomplete\n  '#title': Student\n  '#multiple': true\n  '#target_type': user\n";
    $defs = [
      'resource' => [$approved, ['administrator', 'kb_pm']],
      'project' => [$approved . $members, ['careers_sc', 'pascience_manager']],
      'other' => [$approved, []],
    ];
    foreach ($defs as $id => [$elements, $view_any]) {
      $webform = Webform::create(['id' => $id, 'title' => $id, 'elements' => $elements]);
      if ($view_any) {
        $access = $webform->getAccessRules();
        $access['view_any']['roles'] = $view_any;
        $access['view_own']['roles'] = [RoleInterface::AUTHENTICATED_ID];
        $webform->setAccessRules($access);
      }
      $webform->save();
    }

    foreach (['owner', 'nonowner', 'kb', 'pam', 'mentor', 'student', 'othermentor'] as $label) {
      $this->users[$label] = $this->createUser();
    }
    $this->users['kb']->addRole('kb_pm')->save();
    $this->users['pam']->addRole('pascience_manager')->save();
    $this->users['anonymous'] = new AnonymousUserSession();

    $this->submissions['resource'] = $this->submission('resource');
    $this->submissions['project'] = $this->submission('project', [
      'mentor' => [$this->users['mentor']->id()],
      'student' => [$this->users['student']->id()],
    ]);
    $this->submissions['other_project'] = $this->submission('project', [
      'mentor' => [$this->users['othermentor']->id()],
    ]);
    $this->submissions['other'] = $this->submission('other');
  }

  /**
   * Creates an approved submission owned by the owner user.
   */
  protected function submission(string $webform_id, array $extra = []): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => $webform_id,
      'uid' => $this->users['owner']->id(),
      'data' => ['approved' => 1] + $extra,
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Runs the access check service.
   */
  protected function check(string $submission, string $user) {
    return $this->container->get('access_misc.submission_page_access')
      ->access($this->submissions[$submission], $this->users[$user]);
  }

  /**
   * Tests access to approved resource submission pages.
   */
  public function testResource(): void {
    $this->assertTrue($this->check('resource', 'anonymous')->isForbidden());
    $this->assertTrue($this->check('resource', 'nonowner')->isForbidden());
    $this->assertTrue($this->check('resource', 'owner')->isAllowed());
    $this->assertTrue($this->check('resource', 'kb')->isAllowed());
    // A project staff role has no say over resources.
    $this->assertTrue($this->check('resource', 'pam')->isForbidden());
  }

  /**
   * Tests access to approved project submission pages.
   */
  public function testProject(): void {
    $this->assertTrue($this->check('project', 'anonymous')->isForbidden());
    $this->assertTrue($this->check('project', 'nonowner')->isForbidden());
    $this->assertTrue($this->check('project', 'owner')->isAllowed());
    $this->assertTrue($this->check('project', 'pam')->isAllowed());
    $this->assertTrue($this->check('project', 'mentor')->isAllowed());
    $this->assertTrue($this->check('project', 'student')->isAllowed());
    $this->assertTrue($this->check('project', 'othermentor')->isForbidden());
    // Membership is per project.
    $this->assertTrue($this->check('other_project', 'mentor')->isForbidden());
    $this->assertTrue($this->check('other_project', 'student')->isForbidden());
  }

  /**
   * Other webforms are not affected.
   */
  public function testOtherWebformUntouched(): void {
    $this->assertTrue($this->check('other', 'anonymous')->isAllowed());
    $this->assertTrue($this->check('other', 'nonowner')->isAllowed());
  }

  /**
   * Results for the restricted webforms vary by user.
   */
  public function testCacheContexts(): void {
    foreach (['resource', 'project'] as $label) {
      foreach (['anonymous', 'owner', 'kb'] as $user) {
        $contexts = $this->check($label, $user)->getCacheContexts();
        $this->assertContains('user', $contexts);
        $this->assertContains('user.permissions', $contexts);
      }
    }
  }

  /**
   * The route subscriber adds the requirement to submission view routes.
   */
  public function testRouteRequirements(): void {
    $provider = $this->container->get('router.route_provider');
    foreach ([
      'entity.webform.user.submission',
      'entity.webform_submission.canonical',
      'entity.webform_submission.table',
    ] as $name) {
      $this->assertSame('TRUE', $provider->getRouteByName($name)->getRequirement('_access_misc_submission_page'), $name);
      $this->assertSame('webform_submission.view', $provider->getRouteByName($name)->getRequirement('_entity_access'), $name);
    }
    $this->assertNull($provider->getRouteByName('entity.webform_submission.edit_form')->getRequirement('_access_misc_submission_page'));
  }

  /**
   * Routes that pass the check as a default (webform_node) are covered too.
   */
  public function testNodeStyleRoutes(): void {
    $collection = new RouteCollection();
    $collection->add('node_view', new Route(
      '/node/{node}/webform/submission/{webform_submission}',
      ['entity_access' => 'webform_submission.view'],
      ['_custom_access' => 'callback'],
    ));
    $collection->add('node_edit', new Route(
      '/node/{node}/webform/submission/{webform_submission}/edit',
      ['entity_access' => 'webform_submission.update'],
      ['_custom_access' => 'callback'],
    ));
    (new RouteSubscriber())->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertSame('TRUE', $collection->get('node_view')->getRequirement('_access_misc_submission_page'));
    $this->assertSame('callback', $collection->get('node_view')->getRequirement('_custom_access'));
    $this->assertNull($collection->get('node_edit')->getRequirement('_access_misc_submission_page'));
  }

  /**
   * End to end through the access manager.
   */
  public function testAccessManager(): void {
    $manager = $this->container->get('access_manager');
    $params = [
      'webform' => 'resource',
      'webform_submission' => $this->submissions['resource']->id(),
    ];
    $this->assertFalse($manager->checkNamedRoute('entity.webform.user.submission', $params, $this->users['anonymous']));
    $this->assertFalse($manager->checkNamedRoute('entity.webform.user.submission', $params, $this->users['nonowner']));
    $this->assertTrue($manager->checkNamedRoute('entity.webform.user.submission', $params, $this->users['owner']));
    $this->assertTrue($manager->checkNamedRoute('entity.webform.user.submission', $params, $this->users['kb']));
  }

}
