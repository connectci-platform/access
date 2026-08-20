<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Lookup connected Project+ nodes.
 *
 * @ProjectLookup(
 *   id = "project_lookup",
 *   title = @Translation("Project Lookup"),
 *   description = @Translation("Lookup Users project+ connections for community
 *   persona.")
 * )
 */
class ProjectLookup {

  /**
   * Store project submissions.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $projects = [];

  /**
   * Array of sorted projects, keyed by submission id.
   *
   * @var array<int|string, array<string, mixed>>
   */
  private array $projectsSorted = [];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Collects the project submissions connected to a user.
   *
   * @param array<string, string> $project_fields
   *   Submission field names mapped to the label to display.
   * @param int|string $project_user_id
   *   The user id to look projects up for.
   * @param string $project_user_email
   *   The email address to look projects up for.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $project_fields, $project_user_id, string $project_user_email, Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->runQuery($project_fields, $project_user_id, $project_user_email);
  }

  /**
   * Function to Run entity query by type.
   *
   * @param array<string, string> $project_fields
   *   Submission field names mapped to the label to display.
   * @param int|string $project_user_id
   *   The user id to look projects up for.
   * @param string $project_user_email
   *   The email address to look projects up for.
   */
  public function runQuery(array $project_fields, $project_user_id, string $project_user_email): void {
    $query = $this->database->select('webform_submission_data', 'wsd');
    $or_group = $query->orConditionGroup()
      ->condition('wsd.value', $project_user_id)
      ->condition('wsd.value', $project_user_email);
    $or_name = $query->orConditionGroup()
      ->condition('wsd.name', 'mentor')
      ->condition('wsd.name', 'mentors')
      ->condition('wsd.name', 'mentee_s_')
      ->condition('wsd.name', 'student')
      ->condition('wsd.name', 'students')
      ->condition('wsd.name', 'interested_in_project');
    $query->fields('wsd', ['sid', 'name']);
    $query->condition($or_group);
    $query->condition($or_name);
    $query->condition('wsd.webform_id', 'project');
    $result = $query->execute()->fetchAll();

    $query_flag = $this->database->select('flagging', 'f');
    $query_flag->fields('f', ['entity_id', 'flag_id']);
    $query_flag->condition('f.uid', $project_user_id);
    $query_flag->condition('f.flag_id', 'interested_in_project');
    $result_flag = $query_flag->execute()->fetchAll();
    $flagged_results = array_map(function ($result_flag) {
      return (object) [
        'sid' => $result_flag->entity_id,
        'name' => 'interested_in_project',
      ];
    }, $result_flag);
    $result = array_merge($result, $flagged_results);
    $submission_storage = $this->entityTypeManager->getStorage('webform_submission');
    foreach ($result as $project_result) {
      /** @var \Drupal\webform\WebformSubmissionInterface|null $wf */
      $wf = $submission_storage->load($project_result->sid);
      if ($wf != NULL) {
        $wf_lookup = $wf->getData();
        $this->projects[] = [
          'title' => $wf_lookup['project_title'],
          'name' => $project_fields[$project_result->name],
          'status' => $wf_lookup['status'],
          'sid' => $project_result->sid,
        ];
      }
    }
  }

  /**
   * Function to sort by status.
   */
  public function sortStatusProjects(): void {
    $projects = $this->projects;
    $recruiting = $this->arrayPickSort($projects, 'Recruiting');
    $in_progress = $this->arrayPickSort($projects, 'In Progress');
    $in_review = $this->arrayPickSort($projects, 'Reviewing Applicants');
    $on_hold = $this->arrayPickSort($projects, 'On Hold');
    $finishing_up = $this->arrayPickSort($projects, 'Finishing Up');
    $complete = $this->arrayPickSort($projects, 'Complete');
    $halted = $this->arrayPickSort($projects, 'Halted');
    // Combine all of the arrays.
    $this->projectsSorted = $recruiting + $in_progress + $in_review + $on_hold + $finishing_up + $complete + $halted;
  }

  /**
   * Function to pick out a status into an array and sort by title.
   *
   * @param array<int|string, array<string, mixed>> $array
   *   The projects to filter.
   * @param string $sortby
   *   The status value to keep.
   *
   * @return array<int|string, array<string, mixed>>
   *   The matching entries, keyed by submission id.
   */
  public function arrayPickSort(array $array, string $sortby): array {
    $sorted = [];
    if (!$array) {
      return $sorted;
    }
    foreach ($array as $value) {
      if ($value['status'] && $value['status'] == $sortby) {
        $sid = $value['sid'];
        $sorted[$sid] = $value;
      }
    }
    return $sorted;
  }

  /**
   * Function to return styled list.
   *
   * @return string
   *   The rendered list items.
   */
  public function getProjectList(): string {
    $n = 1;
    $project_link = '';
    if (!$this->projectsSorted) {
      return $project_link;
    }
    foreach ($this->projectsSorted as $project) {
      $stripe_class = $n % 2 == 0 ? 'bg-light-teal bg-light' : '';
      $title = $project['title'];
      $sid = $project['sid'];
      $project_status = $project['status'];
      $project_name = $project['name'];
      if (($project_status == 'Recruiting' && $project_name == 'Interested') || $project_name != 'Interested') {
        $lowercase = lcfirst($project_name);
        $first_letter = substr($lowercase, 0, 1);
        $project_name = "<div data-tippy-content='$project_name'>
          <div class='rounded-full text-white text-lg text-bold bg-md-teal p-0 w-6 h-6'><div class='text-center leading-5'>$first_letter</div></div>
        </div>";
        $project_link .= "<li class='py-2 $stripe_class'>
          <div class='text-truncate' style='width: 400px;'>
            <a href='/admin/structure/webform/manage/project/submission/$sid' class='font-bold underline hover--no-underline hover--text-dark-teal'>$title</a>
          </div>
          <div class='invisible hidden ms-5'>
            $project_name
          </div>
          <div class='ms-2 invisible hidden'>
            $project_status
          </div>
        </li>";
        $n++;
      }
    }
    return $project_link;
  }

  /**
   * Function to return projects.
   *
   * @return array<int, array<string, mixed>>
   *   The project submissions.
   */
  public function getProjects(): array {
    return $this->projects;
  }

}
