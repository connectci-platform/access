<?php

namespace Drupal\Tests\cssn\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\cssn\Plugin\Util\ProjectLookup;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests that Declined projects drop off ProjectLookup's sorted list.
 *
 * @group cssn
 */
class ProjectLookupDeclinedTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Modules to enable.
   *
   * Deliberately NOT enabling cssn (heavy install hooks). We test the
   * ProjectLookup class directly, same pattern as
   * UserAffinityGroupsProcessorTest.
   *
   * @var array
   */
  protected static $modules = [
    'webform', 'flag', 'user', 'system', 'field', 'text', 'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('webform', ['webform']);
    $this->installEntitySchema('webform_submission');
    $this->installEntitySchema('user');
    $this->installEntitySchema('flagging');
    $this->installConfig(['webform']);
  }

  /**
   * Creates a project webform submission linked to a user via $name.
   *
   * @param string $title
   *   The project_title data value.
   * @param string $status
   *   The status data value.
   * @param string $name
   *   The submission field name linking the submission to the user (one of
   *   the names ProjectLookup::runQuery() looks for, e.g. 'mentor').
   * @param int $uid
   *   The user id to link the submission to.
   */
  private function createProjectSubmission(string $title, string $status, string $name, int $uid): void {
    $submission = WebformSubmission::create([
      'webform_id' => 'project',
      'data' => [
        'project_title' => $title,
        'status' => $status,
        $name => $uid,
      ],
    ]);
    $submission->save();
  }

  /**
   * Tests that a Recruiting project is returned and a Declined one is not.
   */
  public function testDeclinedProjectDropsOffSortedList(): void {
    Webform::create(['id' => 'project', 'title' => 'Project'])->save();

    $user = $this->createUser();

    $this->createProjectSubmission('Recruiting Project', 'Recruiting', 'mentor', (int) $user->id());
    $this->createProjectSubmission('Declined Project', 'Declined', 'mentor', (int) $user->id());

    $project_fields = ['mentor' => 'Mentor'];
    $lookup = new ProjectLookup($project_fields, $user->id(), $user->getEmail(), \Drupal::database(), \Drupal::entityTypeManager());
    $lookup->sortStatusProjects();

    $list = $lookup->getProjectList();

    $this->assertStringContainsString('Recruiting Project', $list);
    $this->assertStringNotContainsString('Declined Project', $list);
  }

}
