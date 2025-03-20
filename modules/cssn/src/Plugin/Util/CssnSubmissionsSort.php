<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\webform\Entity\WebformSubmission;

/**
 * Sort CSSN Webform Submissions.
 *
 * @CssnSubmissionSort(
 *   id = "cssn_submission_sort",
 *   title = @Translation("CSSN Submission Sorter"),
 *   description = @Translation("Sort CSSN Webform Submissions.")
 * )
 */
class CssnSubmissionsSort {

  /**
   * Function to return CSSN results and sort users to program/roles.
   */
  public function __construct($start, $end) {
    $ws_query = \Drupal::entityQuery('webform_submission')
      ->condition('uri', '/form/join-the-cssn-network')
      ->range($start, $end)
      ->accessCheck(FALSE);
    $ws_results = $ws_query->execute();
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => 'ACCESS CSSN']);
    $term = reset($term);
    $term_id = $term->id();
    foreach ($ws_results as $ws_result) {
      $ws = WebformSubmission::load($ws_result);
      $ws_data = $ws->getData();
      if (!empty($ws_data)) {
        $ws_owner = $ws->getOwner();
        $role_program_sorter = new RoleProgramSorter($ws_owner);
        $checked = $ws_data['i_am_joining_as_a_'][0];
        if ($checked == 'General Member') {
          $role_program_sorter->addFieldRegion($term_id);
        }
        if ($checked == 'MATCH Mentor') {
          $role_program_sorter->addFieldRegion($term_id);
          $role_program_sorter->addRole('mentor');
        }
        if ($checked == 'Student-Facilitator') {
          $role_program_sorter->addFieldRegion($term_id);
          $role_program_sorter->addRole('student');
        }
        if ($checked == 'Premier Consultant') {
          $role_program_sorter->addFieldRegion($term_id);
          $role_program_sorter->addRole('consultant');
        }
      }
    }
  }

}
