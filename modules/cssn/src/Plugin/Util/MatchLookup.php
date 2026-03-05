<?php

namespace Drupal\cssn\Plugin\Util;

/**
 * Lookup connected Match+ nodes.
 *
 * @MatchLookup(
 *   id = "match_lookup",
 *   title = @Translation("Match Lookup"),
 *   description = @Translation("Lookup Users match+ connections for community
 *   persona.")
 * )
 */
class MatchLookup {
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
  private $matchesSorted;

  /**
   * Function to return matching nodes.
   */
  public function __construct($match_fields, $match_user_id, $public = FALSE) {
    // If not public, add engagements authored by User.
    if (!$public) {
      $query = \Drupal::database()->select('node_field_data', 'nfd');
      $query->fields('nfd', ['nid']);
      $query->condition('nfd.type', 'match_engagement');
      $query->condition('nfd.uid', $match_user_id);
      $result = $query->execute()->fetchAll();
      $nids = array_column($result, 'nid');
      $this->matches['author'] = [
        'name' => 'Author',
        'nodes' => \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids),
      ];
    }
    foreach ($match_fields as $match_field_key => $match_field) {
      $this->runQuery($match_field, $match_field_key, $match_user_id);
    }
    $this->gatherMatches($public);
  }

  /**
   * Function to Run entity query by type.
   */
  public function runQuery($match_field_name, $match_field, $match_user_id) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'match_engagement')
      ->condition($match_field, $match_user_id)
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
        $field_status = $node->get('field_status')->getValue();
        $field_status = !empty($field_status) ? $field_status : '';
        // Don't display engagement with a non-public status on public profile.
        if ($public == TRUE) {
          $non_public = ['draft', 'in_review', 'accepted', 'on_hold', 'halted'];
          if (in_array($field_status[0]['value'], $non_public)) {
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
    $this->matchesSorted = $match_array;
  }

  /**
   * Function to sort by status.
   */
  public function sortStatusMatches() {
    $matches = $this->matchesSorted;
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
    $matches_sorted = $draft + $in_review + $accepted + $recruiting + $reviewing + $in_progress + $finishing + $completed + $on_hold + $halted;
    $this->matchesSorted = $matches_sorted;
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
  public function getMatchList() {
    $n = 1;
    $match_link = '';
    if ($this->matchesSorted == NULL) {
      return;
    }
    foreach ($this->matchesSorted as $match) {
      $stripe_class = $n % 2 == 0 ? 'bg-light bg-light-teal' : '';
      $title = $match['title'];
      $nid = $match['nid'];
      $match_status = $match['status'];
      $match_translated_status = [
        'draft' => 'Draft',
        'in_review' => 'In Review',
        'accepted' => 'Accepted',
        'recruiting' => 'Recruiting',
        'reviewing_applicants' => 'Reviewing Applicants',
        'in_progress' => 'In-Progress',
        'finishing_up' => 'Finishing Up',
        'complete' => 'Complete',
        'on_hold' => 'On Hold',
        'halted' => 'Halted',
      ];
      if ($match_status) {
        $set_status = $match_status[0]['value'];
        $match_status = $match_translated_status[$set_status];
      }
      $match_name = $match['name'];
      if (($match_status == 'Recruiting' && $match_name == 'Interested') || $match_name != 'Interested') {
        $lowercase = lcfirst($match_name);
        $first_letter = substr($lowercase, 0, 1);
        $match_name = "<div data-tippy-content='$match_name'>
          <div class='rounded-full text-white text-lg text-bold bg-md-teal p-0 w-6 h-6'><div class='text-center leading-5'>$first_letter</div></div>
        </div>";
        $match_link .= "<li class='d-flex flex p-3 $stripe_class'>
          <div class='text-truncate' style='width: 400px;'>
            <a href='https://support.access-ci.org/node/$nid' class='font-bold underline hover--no-underline hover--text-dark-teal'>$title</a>
          </div>
          <div class='font-weight-bold ms-5'>
            $match_name
          </div>
          <div class='ms-2'>
            $match_status
          </div>
        </li>";
        $n++;
      }
    }
    return $match_link;
  }

  /**
   * Function to return matching nodes.
   */
  public function getMatches() {
    return $this->matches;
  }

}
