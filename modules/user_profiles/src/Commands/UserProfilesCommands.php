<?php

namespace Drupal\user_profiles\Commands;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\WebformSubmission;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile to migrate profile data from
 * one user to another.
 *
 * @package Drupal\user_profiles\Commands
 */
class UserProfilesCommands extends DrushCommands {

  /**
   * Migrate user data from one user to another.  The following
   * will get updated:
   *  - flags:  affinity groups, interest, skill, upvote, interested-in-project
   *  - webform submissions
   *  - roles
   *  - user fields
   *  - nodes
   *
   * @command user_profiles:mergeUser
   * @param string $from_user_id
   *   Id of user id to merge from.
   * @param string $to_user_id
   *   Id of user id to merge to.
   *
   * @aliases mergeUser
   * @usage user_profiles:mergeUser
   */
  public function mergeUser(string $from_user_id, string $to_user_id) {

    $this->output()->writeln("------------- Merge user $from_user_id into $to_user_id ---------------------------------");

    $user_from = User::load($from_user_id);
    $user_to = User::load($to_user_id);

    if (!$user_from) {
      $this->output()->writeln("  *** No user found with id $from_user_id");
      return;
    }
    if (!$user_to) {
      $this->output()->writeln("  *** No user found with id $to_user_id");
      return;
    }

    $first_name1 = $user_from->get('field_user_first_name')->getString();
    $last_name1 = $user_from->get('field_user_last_name')->getString();
    $first_name2 = $user_to->get('field_user_first_name')->getString();
    $last_name2 = $user_to->get('field_user_last_name')->getString();

    $this->output()->writeln("  Migrating data for user '$first_name1 $last_name1' to '$first_name2 $last_name2'");

    $this->mergeNodes($user_from, $user_to);
    $this->mergeUserFields($user_from, $user_to);
    $this->mergeRoles($user_from, $user_to);
    $this->mergeWebformSubmissions($user_from, $user_to);

    $flags = ['affinity_group', 'interest', 'skill', 'upvote', 'interested_in_project'];
    foreach ($flags as $flag) {
      $this->mergeFlag($flag, $user_from, $user_to);
    }
  }

  /**
   * Merge nodes from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeNodes(User $user_from, User $user_to) {

    \Drupal::moduleHandler()->loadInclude('node', 'inc', 'node.admin');

    $nodes = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('uid', $user_from->id())
      ->execute();

    if (count($nodes) == 0) {
      $this->output()->writeln("No nodes to migrate");
      return;
    }

    $this->output()->writeln("Migrating nodes");
    $this->output()->writeln("  Changing ownership of these node titles: ");
    foreach ($nodes as $nid) {
      $node = Node::load($nid);
      $this->output()->writeln("    " . $node->getTitle());
    }

    foreach ($nodes as $nid) {
      $node = Node::load($nid);
      if ($node) {
        $node->set('uid', $user_to->id());
        $node->save();
      }
    }
  }

  /**
   * Merge various fields from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeUserFields(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating user fields");

    /* Here's a list of all fields -- only a subset of these are migrated.

    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');

    [0] => uid
    [1] => uuid
    [2] => langcode
    [3] => preferred_langcode
    [4] => preferred_admin_langcode
    [5] => name
    [6] => pass
    [7] => mail
    [8] => timezone
    [9] => status
    [10] => created
    [11] => changed
    [12] => access
    [13] => login
    [14] => init
    [15] => roles
    [16] => default_langcode
    [17] => mail_change
    [18] => role_change
    [19] => path
    [20] => field_blocked_ag_tax
    [21] => field_carnegie_code
    [22] => field_cider_resources
    [23] => field_citizenships
    [24] => field_constant_contact_id
    [25] => field_current_degree_program
    [26] => field_current_occupation
    [27] => field_cv_resume
    [28] => field_degree
    [29] => field_domain_access
    [30] => field_domain_admin
    [31] => field_domain_all_affiliates
    [32] => field_hpc_experience
    [33] => field_institution
    [34] => field_is_cc
    [35] => field_region
    [36] => field_user_first_name
    [38] => user_picture
     */

    $merge_fields = [
      'field_citizenships',
      'field_current_degree_program',
      'field_current_occupation',
      'field_cv_resume',
      'field_degree',
      'field_hpc_experience',
      'field_institution',
      'user_picture',
    ];

    // For each of the fields listed above, only replace the value for the
    // to_user if to_user field is empty and if the from-user is not empty.
    foreach ($merge_fields as $merge_field) {
      $to_val = $user_to->get($merge_field)->getValue();
      $from_val = $user_from->get($merge_field)->getValue();
      if (!$to_val && $from_val) {
        $value_text = '';
        if (isset($from_val[0]['value'])) {
          $value_text = "with value '" . $from_val[0]['value'] . "'";
        }
        $this->output()->writeln("  Merging field '$merge_field' $value_text");
        $user_to->get($merge_field)->setValue($from_val);
        $user_to->set($merge_field, $from_val);
      }
    }

    // Migrate the boolean field_is_cc manually
    if (
      $user_from->get('field_is_cc')->getValue()[0]['value']
      && !$user_to->get('field_is_cc')->getValue()[0]['value']
    ) {
      $this->output()->writeln("  Setting to-user as a campus champion");
      $user_to->set('field_is_cc', TRUE);
    }

    // Per Andrew:  Carnegie code should only be copied if the old institution
    // is the same as the new institution.
    $from_carnegie_code = $user_from->get('field_carnegie_code')->getValue();
    if ($from_carnegie_code) {
      $from_carnegie_code = $from_carnegie_code[0]['value'];
    }
    if ($from_carnegie_code) {
      $from_inst = $user_from->get('field_institution')->getValue();
      if ($from_inst) {
        $from_inst = $from_inst[0]['value'];
      }
      $to_inst = $user_to->get('field_institution')->getValue();
      if ($to_inst) {
        $to_inst = $to_inst[0]['value'];
      }
      if ($from_inst === $to_inst) {
        $this->output()->writeln("  Migrating 'field_carnegie_code' with value '$from_carnegie_code'");
        $user_to->set('field_carnegie_code', $from_carnegie_code);
      } else {
        $this->output()->writeln("  Not Migrating 'field_carnegie_code' with "
          . "value '$from_carnegie_code' because differing institutions ('$from_inst' and '$to_inst')");
      }
    } else {
      $this->output()->writeln("  From user has no carnegie code to migrate.");
    }

    // Per Andrew:  field_region should only be added to, not replaced.
    $this->output()->writeln("  Migrating region / program fields");
    $from_region = $user_from->get('field_region')->referencedEntities();
    foreach ($from_region as $from_program) {
      $to_region = $user_to->get('field_region')->referencedEntities();
      if (count($to_region) == 0) {
        $this->output()->writeln("    Adding region / program '"
          . $from_program->getName() . "'");
        $user_to->set('field_region', $from_program->id());
      } else {
        if (!array_filter(
          $to_region,
          function ($to_program) use ($from_program) {
            return $to_program->id() == $from_program->id();
          }
        )) {
          $this->output()->writeln(
            "    Appending program '"
              . $from_program->getName() . "'"
          );
          $user_to->get('field_region')->appendItem(
            [
              'target_id' => $from_program->id(),
            ]
          );
        } else {
          $this->output()->writeln(
            "    To-user already a member of program '"
              . $from_program->getName() . "'"
          );
        }
      }
    }

    $user_to->save();
  }

  /**
   * Merge the roles from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeRoles(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating roles");

    $roles = $user_from->getRoles();
    $to_roles = $user_to->getRoles();
    $changes = FALSE;
    foreach ($roles as $role) {
      if (in_array($role, ['anonymous', 'authenticated', 'administrator'])) {
        $this->output()->writeln("  Skipping role '$role' - can't be assigned programatically");
      } elseif (in_array($role, $to_roles)) {
        $this->output()->writeln("  To-user already has role '$role'");
      } else {
        $this->output()->writeln("  Migrating role '$role'");
        $user_to->addRole($role);
        $changes = TRUE;
      }
    }
    if ($changes) {
      $user_to->save();
    }
  }

  /**
   * Change the ownership of any webform submissions.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   *
   *   Can node_mass_update do this?
   */
  private function mergeWebformSubmissions(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating webform submissions");

    $ws_query = \Drupal::entityQuery('webform_submission')
      ->condition('uid', $user_from->id())
      ->accessCheck(FALSE);
    $ws_results = $ws_query->execute();
    if ($ws_results == NULL) {
      $this->output()->writeln("  From-user has no webform submissions");
      return;
    }
    foreach ($ws_results as $ws_result) {
      $ws = WebformSubmission::load($ws_result);
      $ws_id = $ws->getWebform()->id();
      $ws->setOwner($user_to);
      $ws->save();
      $this->output()->writeln("  Updated ownership of webform submission of type $ws_id");
    }
  }

  /**
   * Copy the flag setting.
   *
   * @param string $flag_name
   *   The name of the flag to merge.
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeFlag($flag_name, User $user_from, User $user_to) {
    $this->output()->writeln("Migrating flags with name '$flag_name'");

    $term = \Drupal::database()->select('flagging', 'fl');
    $term->condition('fl.uid', $user_from->id());
    $term->condition('fl.flag_id', $flag_name);
    $term->fields('fl', ['entity_id']);
    $flagged_items = $term->execute()->fetchCol();
    if ($flagged_items == NULL) {
      $this->output()->writeln("  From-user has no flags with name '$flag_name'");
      return;
    }

    foreach ($flagged_items as $flagged_item) {
      $term = Term::load($flagged_item);
      $title = $term->get('name')->value;

      // Check if already flagged. If not, set the flag.
      $flag_service = \Drupal::service('flag');
      $flag = $flag_service->getFlagById($flag_name);
      $flag_status = $flag_service->getFlagging($flag, $term, $user_to);
      if (!$flag_status) {
        $bundles = $flag->getBundles();
        if (!empty($bundles) && !in_array($term->bundle(), $bundles)) {
          $this->output()->writeln("*** Error, flag '$flag_name' with title '$title' has bundle "
            . $term->bundle() . " which is not in allowed list: {"
            . implode(', ', $bundles) . '} -- skipping this one.');
        } else {
          $this->output()->writeln("  Adding flag $flag_name with title '$title' to to-user");
          $flag_service->flag($flag, $term, $user_to);
        }
      } else {
        $this->output()->writeln("  To-user already has flag $flag_name with title '$title'");
      }
    }
  }
}
