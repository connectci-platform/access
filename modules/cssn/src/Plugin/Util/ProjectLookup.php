<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\webform\Entity\WebformSubmission;

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
   * @var array
   */
  private $projects;

  /**
   * Array of sorted projects.
   *
   * @var array
   */
  private $projectsSorted;

  /**
   * Function to return projects.
   */
  public function __construct($project_fields, $project_user_id, $project_user_email, $public = FALSE) {
    $this->runQuery($project_fields, $project_user_id, $project_user_email);
  }

  /**
   * Function to Run entity query by type.
   */
  public function runQuery($project_fields, $project_user_id, $project_user_email) {
    $query = \Drupal::database()->select('webform_submission_data', 'wsd');
    $orGroup = $query->orConditionGroup()
      ->condition('wsd.value', $project_user_id)
      ->condition('wsd.value', $project_user_email);
    $orName = $query->orConditionGroup()
      ->condition('wsd.name', 'mentor')
      ->condition('wsd.name', 'mentors')
      ->condition('wsd.name', 'mentee_s_')
      ->condition('wsd.name', 'student')
      ->condition('wsd.name', 'students')
      ->condition('wsd.name', 'interested_in_project');
    $query->fields('wsd', ['sid', 'name']);
    $query->condition($orGroup);
    $query->condition($orName);
    $query->condition('wsd.webform_id', 'project');
    $result = $query->execute()->fetchAll();

    $query_flag = \Drupal::database()->select('flagging', 'f');
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
    if ($result != NULL) {
      foreach ($result as $project_result) {
        $wf = WebformSubmission::load($project_result->sid);
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
  }

  /**
   * Function to sort by status.
   */
  public function sortStatusProjects() {
    $projects = $this->projects;
    $recruiting = $this->arrayPickSort($projects, 'Recruiting');
    $in_progress = $this->arrayPickSort($projects, 'In Progress');
    $in_review = $this->arrayPickSort($projects, 'Reviewing Applicants');
    $on_hold = $this->arrayPickSort($projects, 'On Hold');
    $finishing_up = $this->arrayPickSort($projects, 'Finishing Up');
    $complete = $this->arrayPickSort($projects, 'Complete');
    $halted = $this->arrayPickSort($projects, 'Halted');
    // Combine all of the arrays.
    $projects_sorted = $recruiting + $in_progress + $in_review + $on_hold + $finishing_up + $complete + $halted;
    $this->projectsSorted = $projects_sorted;
  }

  /**
   * Function to pick out a status into an array and sort by title.
   */
  public function arrayPickSort($array, $sortby) {
    $sorted = [];
    if ($array == NULL) {
      return;
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
   */
  public function getProjectList() {
    $n = 1;
    $project_link = '';
    if ($this->projectsSorted == NULL) {
      return;
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
   */
  public function getProjects() {
    return $this->projects;
  }

}
