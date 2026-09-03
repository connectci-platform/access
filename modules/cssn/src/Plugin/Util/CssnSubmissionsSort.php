<?php

namespace Drupal\cssn\Plugin\Util;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;

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
   * Sorts CSSN webform submitters into the matching programs and roles.
   *
   * @param int $start
   *   The offset of the first submission to process.
   * @param int $end
   *   The number of submissions to process.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(int $start, int $end, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $submission_storage = $entity_type_manager->getStorage('webform_submission');
    $ws_results = $submission_storage->getQuery()
      ->condition('uri', '/form/join-the-cssn-network')
      ->range($start, $end)
      ->accessCheck(FALSE)
      ->execute();

    $terms = $entity_type_manager->getStorage('taxonomy_term')
      ->loadByProperties(['name' => 'ACCESS CSSN']);
    $term = reset($terms);
    if (!$term) {
      return;
    }
    $term_id = $term->id();

    $roles_by_choice = [
      'MATCH Mentor' => 'mentor',
      'Student-Facilitator' => 'student',
      'Premier Consultant' => 'consultant',
    ];

    foreach ($ws_results as $ws_result) {
      /** @var \Drupal\webform\WebformSubmissionInterface $ws */
      $ws = $submission_storage->load($ws_result);
      $ws_data = $ws->getData();
      if (empty($ws_data)) {
        continue;
      }
      $checked = $ws_data['i_am_joining_as_a_'][0];
      if ($checked !== 'General Member' && !isset($roles_by_choice[$checked])) {
        continue;
      }

      $role_program_sorter = new RoleProgramSorter($ws->getOwner(), $entity_type_manager, $messenger);
      $role_program_sorter->addFieldRegion($term_id);
      if (isset($roles_by_choice[$checked])) {
        $role_program_sorter->addRole($roles_by_choice[$checked]);
      }
    }
  }

}
