<?php

namespace Drupal\user_profiles\Commands;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\WebformSubmission;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile to migrate profile data from one user to another.
 *
 * @package Drupal\user_profiles\Commands
 */
class UserProfilesCommands extends DrushCommands {

  /**
   * Migrate user data from one user to another.
   *
   * The following will get updated:
   *  - flags:  affinity groups, interest, skill, upvote, interested-in-project
   *  - webform submissions
   *  - roles
   *  - user fields
   *  - nodes (ownership and user reference fields)
   *  - event series (ownership and other authors)
   *  - event instances (ownership)
   *  - event registrations.
   *
   * @param string $from_user_id
   *   Id of user id to merge from.
   * @param string $to_user_id
   *   Id of user id to merge to.
   *
   * @command user_profiles:mergeUser
   * @aliases mergeUser
   * @usage user_profiles:mergeUser
   */
  public function mergeUser(string $from_user_id, string $to_user_id) {

    $this->output()->writeln("------------- Merge user $from_user_id into $to_user_id ---------------------------------");

    $user_from = User::load($from_user_id);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $user_to = User::load($to_user_id);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass

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
    $this->mergeNodeUserReferences($user_from, $user_to);
    $this->mergeEventSeries($user_from, $user_to);
    $this->mergeEventInstances($user_from, $user_to);
    $this->mergeEventRegistrations($user_from, $user_to);
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

    \Drupal::moduleHandler()->loadInclude('node', 'inc', 'node.admin');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

    $nodes = \Drupal::entityQuery('node')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
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
      $node = Node::load($nid);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $this->output()->writeln("    " . $node->getTitle());
    }

    node_mass_update($nodes, ['uid' => $user_to->id()], NULL, TRUE);
  }

  /**
   * Update user reference fields in nodes.
   *
   * Updates referenced users, not ownership. This handles:
   *  - match_engagement: field_students, field_mentor, field_researcher
   *  - mentorship_engagement: field_mentor, field_mentee.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeNodeUserReferences(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating user references in nodes");

    // Define which content types have which user reference fields.
    $content_type_fields = [
      'match_engagement' => ['field_students', 'field_mentor', 'field_researcher'],
      'mentorship_engagement' => ['field_mentor', 'field_mentee'],
    ];

    foreach ($content_type_fields as $content_type => $fields) {
      foreach ($fields as $field_name) {
        // Find nodes where this field references the from_user.
        $query = \Drupal::entityQuery('node')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          ->accessCheck(FALSE)
          ->condition('type', $content_type)
          ->condition($field_name, $user_from->id());
        $nids = $query->execute();

        if (empty($nids)) {
          continue;
        }

        $this->output()->writeln("  Updating $field_name in $content_type nodes");
        foreach ($nids as $nid) {
          $node = Node::load($nid);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
          $field_values = $node->get($field_name)->getValue();
          $updated = FALSE;

          foreach ($field_values as $delta => $value) {
            if ($value['target_id'] == $user_from->id()) {
              $field_values[$delta]['target_id'] = $user_to->id();
              $updated = TRUE;
            }
          }

          if ($updated) {
            // Remove duplicates if to_user was already referenced.
            $unique_ids = [];
            $unique_values = [];
            foreach ($field_values as $value) {
              if (!in_array($value['target_id'], $unique_ids)) {
                $unique_ids[] = $value['target_id'];
                $unique_values[] = $value;
              }
            }

            $node->set($field_name, $unique_values);
            $node->save();
            $this->output()->writeln("    Updated node '{$node->getTitle()}' (nid: $nid)");
          }
        }
      }
    }
  }

  /**
   * Merge event series ownership and other authors from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeEventSeries(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating event series");

    // Check if eventseries entity type exists.
    $has_eventseries = \Drupal::entityTypeManager()->hasDefinition('eventseries');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if (!$has_eventseries) {
      $this->output()->writeln("  Event series entity type not found, skipping");
      return;
    }

    // Transfer ownership of event series.
    $series_ids = \Drupal::entityQuery('eventseries')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      ->accessCheck(FALSE)
      ->condition('uid', $user_from->id())
      ->execute();

    if (!empty($series_ids)) {
      $this->output()->writeln("  Transferring ownership of event series");
      foreach ($series_ids as $series_id) {
        $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($series_id);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        if ($series) {
          $this->output()->writeln("    Transferring '{$series->label()}' (id: $series_id)");
          $series->setOwnerId($user_to->id());
          $series->save();
        }
      }
    }

    // Update field_other_authors references.
    $series_with_author = \Drupal::entityQuery('eventseries')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      ->accessCheck(FALSE)
      ->condition('field_other_authors', $user_from->id())
      ->execute();

    if (!empty($series_with_author)) {
      $this->output()->writeln("  Updating field_other_authors in event series");
      foreach ($series_with_author as $series_id) {
        $series = \Drupal::entityTypeManager()->getStorage('eventseries')->load($series_id);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        if ($series && $series->hasField('field_other_authors')) {
          $authors = $series->get('field_other_authors')->getValue();
          $updated = FALSE;

          foreach ($authors as $delta => $value) {
            if ($value['target_id'] == $user_from->id()) {
              $authors[$delta]['target_id'] = $user_to->id();
              $updated = TRUE;
            }
          }

          if ($updated) {
            // Remove duplicates if to_user was already an author.
            $unique_ids = [];
            $unique_authors = [];
            foreach ($authors as $value) {
              if (!in_array($value['target_id'], $unique_ids)) {
                $unique_ids[] = $value['target_id'];
                $unique_authors[] = $value;
              }
            }

            $series->set('field_other_authors', $unique_authors);
            $series->save();
            $this->output()->writeln("    Updated other authors in '{$series->label()}' (id: $series_id)");
          }
        }
      }
    }

    if (empty($series_ids) && empty($series_with_author)) {
      $this->output()->writeln("  No event series to migrate");
    }
  }

  /**
   * Merge event instance ownership from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeEventInstances(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating event instances");

    // Check if eventinstance entity type exists.
    $has_eventinstance = \Drupal::entityTypeManager()->hasDefinition('eventinstance');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if (!$has_eventinstance) {
      $this->output()->writeln("  Event instance entity type not found, skipping");
      return;
    }

    $instance_ids = \Drupal::entityQuery('eventinstance')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      ->accessCheck(FALSE)
      ->condition('uid', $user_from->id())
      ->execute();

    if (empty($instance_ids)) {
      $this->output()->writeln("  No event instances to migrate");
      return;
    }

    $this->output()->writeln("  Transferring ownership of " . count($instance_ids) . " event instances");
    foreach ($instance_ids as $instance_id) {
      $instance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($instance_id);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      if ($instance) {
        $instance->setOwnerId($user_to->id());
        $instance->save();
      }
    }
    $this->output()->writeln("  Transferred ownership of " . count($instance_ids) . " event instances");
  }

  /**
   * Merge event registrations from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeEventRegistrations(User $user_from, User $user_to) {
    $this->output()->writeln("Migrating event registrations");

    // Check if registrant entity type exists.
    $has_registrant = \Drupal::entityTypeManager()->hasDefinition('registrant');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if (!$has_registrant) {
      $this->output()->writeln("  Registrant entity type not found, skipping");
      return;
    }

    $registrant_ids = \Drupal::entityQuery('registrant')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      ->accessCheck(FALSE)
      ->condition('user_id', $user_from->id())
      ->execute();

    if (empty($registrant_ids)) {
      $this->output()->writeln("  No event registrations to migrate");
      return;
    }

    $this->output()->writeln("  Transferring " . count($registrant_ids) . " event registrations");
    $storage = \Drupal::entityTypeManager()->getStorage('registrant');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

    foreach ($registrant_ids as $registrant_id) {
      $registrant = $storage->load($registrant_id);
      if ($registrant) {
        // Check if to_user already has a registration for this event instance.
        $event_instance_id = $registrant->get('eventinstance_id')->target_id;
        $existing = \Drupal::entityQuery('registrant')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          ->accessCheck(FALSE)
          ->condition('user_id', $user_to->id())
          ->condition('eventinstance_id', $event_instance_id)
          ->execute();

        if (!empty($existing)) {
          $this->output()->writeln("    To-user already registered for event instance $event_instance_id, skipping");
          continue;
        }

        $registrant->set('user_id', $user_to->id());
        // Also update the email field if it matches the from_user's email.
        if ($registrant->hasField('email')) {
          $from_email = $user_from->getEmail();
          $reg_email = $registrant->get('email')->value;
          if ($reg_email === $from_email) {
            $registrant->set('email', $user_to->getEmail());
          }
        }
        $registrant->save();
        $this->output()->writeln("    Transferred registration for event instance $event_instance_id");
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

    $fields = \Drupal::service('entity_field.manager')
    ->getFieldDefinitions('user', 'user');

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
    [20] => field_academic_status
    [21] => field_access_organization
    [22] => field_askci_username
    [23] => field_blocked_ag_tax
    [24] => field_carnegie_code
    [25] => field_cider_resources
    [26] => field_citizenships
    [27] => field_constant_contact_id
    [28] => field_current_degree_program
    [29] => field_current_occupation
    [30] => field_cv_resume
    [31] => field_degree
    [32] => field_discourse_openondemand_org
    [33] => field_domain_access
    [34] => field_domain_admin
    [35] => field_domain_all_affiliates
    [36] => field_github_username
    [37] => field_hpc_experience
    [38] => field_institution
    [39] => field_is_cc
    [40] => field_region
    [41] => field_user_badges
    [42] => field_user_bio
    [43] => field_user_first_name
    [44] => field_user_last_name
    [45] => field_user_preferred_pronouns
    [46] => user_picture
     */

    $merge_fields = [
      'field_academic_status',
      'field_access_organization',
      'field_askci_username',
      'field_citizenships',
      'field_current_degree_program',
      'field_current_occupation',
      'field_cv_resume',
      'field_degree',
      'field_discourse_openondemand_org',
      'field_github_username',
      'field_hpc_experience',
      'field_institution',
      'field_user_bio',
      'field_user_preferred_pronouns',
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

    // Migrate the boolean field_is_cc manually.
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
      }
      else {
        $this->output()->writeln("  Not Migrating 'field_carnegie_code' with "
          . "value '$from_carnegie_code' because differing institutions ('$from_inst' and '$to_inst')");
      }
    }
    else {
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
      }
      else {
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
        }
        else {
          $this->output()->writeln(
            "    To-user already a member of program '"
              . $from_program->getName() . "'"
                  );
        }
      }
    }

    // Badges should be combined from both users (multi-value entity reference).
    $this->output()->writeln("  Migrating user badges");
    $from_badges = $user_from->get('field_user_badges')->referencedEntities();
    foreach ($from_badges as $from_badge) {
      $to_badges = $user_to->get('field_user_badges')->referencedEntities();
      if (count($to_badges) == 0) {
        $this->output()->writeln("    Adding badge '"
          . $from_badge->getName() . "'");
        $user_to->set('field_user_badges', $from_badge->id());
      }
      else {
        if (!array_filter(
          $to_badges,
          function ($to_badge) use ($from_badge) {
            return $to_badge->id() == $from_badge->id();
          }
        )) {
          $this->output()->writeln(
            "    Appending badge '"
              . $from_badge->getName() . "'"
          );
          $user_to->get('field_user_badges')->appendItem(
            [
              'target_id' => $from_badge->id(),
            ]
          );
        }
        else {
          $this->output()->writeln(
            "    To-user already has badge '"
              . $from_badge->getName() . "'"
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
      }
      elseif (in_array($role, $to_roles)) {
        $this->output()->writeln("  To-user already has role '$role'");
      }
      else {
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

    $ws_query = \Drupal::entityQuery('webform_submission')  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
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

    $term = \Drupal::database()->select('flagging', 'fl');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $term->condition('fl.uid', $user_from->id());
    $term->condition('fl.flag_id', $flag_name);
    $term->fields('fl', ['entity_id']);
    $flagged_items = $term->execute()->fetchCol();
    if ($flagged_items == NULL) {
      $this->output()->writeln("  From-user has no flags with name '$flag_name'");
      return;
    }

    foreach ($flagged_items as $flagged_item) {
      $term = Term::load($flagged_item);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $title = $term->get('name')->value;

      // Check if already flagged. If not, set the flag.
      $flag_service = \Drupal::service('flag');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $flag = $flag_service->getFlagById($flag_name);
      $flag_status = $flag_service->getFlagging($flag, $term, $user_to);
      if (!$flag_status) {
        $bundles = $flag->getBundles();
        if (!empty($bundles) && !in_array($term->bundle(), $bundles)) {
          $this->output()->writeln("*** Error, flag '$flag_name' with title '$title' has bundle "
            . $term->bundle() . " which is not in allowed list: {"
            . implode(', ', $bundles) . '} -- skipping this one.');
        }
        else {
          $this->output()->writeln("  Adding flag $flag_name with title '$title' to to-user");
          $flag_service->flag($flag, $term, $user_to);
        }
      }
      else {
        $this->output()->writeln("  To-user already has flag $flag_name with title '$title'");
      }
    }
  }

}
