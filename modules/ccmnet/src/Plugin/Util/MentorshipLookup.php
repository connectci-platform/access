<?php

namespace Drupal\ccmnet\Plugin\Util;

/**
 * Lookup connected Match+ nodes.
 *
 * @MatchLookup(
 *   id = "mentorship_lookup",
 *   title = @Translation("Mentorship Lookup"),
 *   description = @Translation("Lookup Users with mentorship engagements."),
 * )
 */
class MentorshipLookup {
  /**
   * Store matching nodes.
   *
   * @var array
   */
  private $matches;

  /**
   * Array of sorted matches.
   *
   * @var array
   */
  private $mentorshipsSorted;

  /**
   * Function to return matching nodes.
   */
  public function __construct($mentorships_fields, $mentor_user_id, $public = FALSE) {
    // If not public, add engagements authored by User.
    if (!$public) {
      $query = \Drupal::database()->select('node_field_data', 'nfd');
      $query->fields('nfd', ['nid']);
      $query->condition('nfd.type', 'mentorship_engagement');
      $query->condition('nfd.uid', $mentor_user_id);
      $result = $query->execute()->fetchAll();
      $nids = array_column($result, 'nid');
      $this->matches['author'] = [
        'name' => 'Author',
        'nodes' => \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids),
      ];
    }
    foreach ($mentorships_fields as $match_field_key => $match_field) {
      $this->runQuery($match_field, $match_field_key, $mentor_user_id);
    }
    $this->gatherMatches($public);
  }

  /**
   * Function to Run entity query by type.
   */
  public function runQuery($match_field_name, $match_field, $mentor_user_id) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'mentorship_engagement')
      ->condition($match_field, $mentor_user_id)
      ->accessCheck(FALSE)
      ->execute();
    if ($query != NULL) {
      $this->matches[$match_field] = [
        'name' => $match_field_name,
        'nodes' => \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($query),
      ];
    }
  }

  /**
   * Function to lookup nodes and sort array.
   */
  public function gatherMatches($public) {
    $matches = $this->matches;
    $match_array = [];
    if ($matches == NULL) {
      return;
    }
    foreach ($matches as $key => $match) {
      foreach ($match['nodes'] as $node) {
        $title = $node->getTitle();
        $nid = $node->id();
        $match_name = $match['name'];
        $field_status = $node->get('field_me_state')->getValue();
        $field_status = $field_status[0]['target_id'];
        $field_status = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($field_status);
        $field_status = $field_status->getName();

        // Don't display engagement with a non-public status on public profile.
        if ($public == TRUE) {
          $non_public = ['Reviewing', 'On Hold', 'Halted'];
          if (in_array($field_status, $non_public)) {
            unset($matches[$key]);
            break;
          }
        }
        $match_array[$nid] = [
          'status' => $field_status,
          'name' => $match_name,
          'title' => $title,
          'nid' => $nid,
        ];
      }
    }
    $this->mentorshipsSorted = $match_array;
  }

  /**
   * Function to sort by status - needs update if used.
   */
  public function sortStatusMatches() {
    $matches = $this->mentorshipsSorted;
    $draft = $this->arrayPickSort($matches, 'draft');
    $in_review = $this->arrayPickSort($matches, 'in_review');
    $accepted = $this->arrayPickSort($matches, 'accepted');
    $recruiting = $this->arrayPickSort($matches, 'recruiting');
    $reviewing = $this->arrayPickSort($matches, 'reviewing_applicants');
    $in_progress = $this->arrayPickSort($matches, 'in_progress');
    $finishing = $this->arrayPickSort($matches, 'finishing_up');
    $completed = $this->arrayPickSort($matches, 'complete');
    $on_hold = $this->arrayPickSort($matches, 'on_hold');
    $halted = $this->arrayPickSort($matches, 'halted');
    // Combine all of the arrays.
    $mentorships_sorted = $draft + $in_review + $accepted + $recruiting + $reviewing + $in_progress + $finishing + $completed + $on_hold + $halted;
    $this->mentorshipsSorted = $mentorships_sorted;
  }

  /**
   * Function to pick out a status into an array and sort by title.
   */
  public function arrayPickSort($array, $sortby) {
    $sorted = [];
    if ($array == NULL) {
      return;
    }
    foreach ($array as $key => $value) {
      if ($value['status'] && $value['status'][0]['value'] == $sortby) {
        $sorted[$key] = $value;
      }
    }
    uasort($sorted, function ($a, $b) {
      return strnatcmp($a['title'], $b['title']);
    });
    return $sorted;
  }

  /**
   * Function to return styled list.
   */
  public function getMentorshipList() {
    $n = 1;
    $mentorship_link = '';
    if ($this->mentorshipsSorted == NULL) {
      return;
    }
    foreach ($this->mentorshipsSorted as $mentorship) {
      $stripe_class = $n % 2 == 0 ? 'bg-light bg-light-teal' : '';
      $title = $mentorship['title'];
      $nid = $mentorship['nid'];
      $mentorship_status = $mentorship['status'];
      $mentorship_name = $mentorship['name'];
      $lowercase = lcfirst($mentorship_name);
      $first_letter = substr($lowercase, 0, 1);
      $mentorship_name = "<div data-toggle='tooltip' data-placement='left' title='$mentorship_name'>
        <div class='rounded-full text-white text-lg text-bold bg-md-teal p-0 w-6 h-6'><div class='text-center leading-5'>$first_letter</div></div>
      </div>";
      $mentorship_link .= "<li class='d-flex flex p-3 $stripe_class'>
        <div class='text-truncate' style='width: 400px;'>
          <a href='https://ccmnet.org/node/$nid' class='font-bold underline hover--no-underline hover--text-dark-teal'>$title</a>
        </div>
        <div>
          $mentorship_name
        </div>
        <div class='ms-2 ml-2'>
          $mentorship_status
        </div>
      </li>";
      $n++;
    }
    return $mentorship_link;
  }

  /**
   * Function to return matching nodes.
   */
  public function getMatches() {
    return $this->matches;
  }

}
