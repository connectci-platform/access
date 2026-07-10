<?php

namespace Drupal\user_profiles;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\Entity\Node;
use Drupal\recurring_events\EventInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\user_profiles\Exception\UserMergeException;
use Drupal\webform\Entity\WebformSubmission;
use Psr\Log\LoggerInterface;

/**
 * Merges one user account's content into another.
 *
 * Runtime service (web-safe) extracted from UserProfilesCommands so the
 * login hook can call it. The Drush command delegates here.
 */
class UserMerger {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected Connection $database,
    protected FlagServiceInterface $flagService,
    protected LoggerInterface $logger,
  ) {}

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
   * @param int $from_user_id
   *   Id of user to merge from.
   * @param int $to_user_id
   *   Id of user to merge to.
   *
   * @return array
   *   Summary array with counts of items merged per category.
   */
  public function mergeUser(int $from_user_id, int $to_user_id): array {

    $this->logger->info("------------- Merge user $from_user_id into $to_user_id ---------------------------------");

    $user_storage = $this->entityTypeManager->getStorage('user');
    $user_from = $user_storage->load($from_user_id);
    $user_to = $user_storage->load($to_user_id);

    if (!$user_from instanceof User) {
      $this->logger->info("  *** No user found with id $from_user_id");
      return [];
    }
    if (!$user_to instanceof User) {
      $this->logger->info("  *** No user found with id $to_user_id");
      return [];
    }

    $first_name1 = $user_from->get('field_user_first_name')->getString();
    $last_name1 = $user_from->get('field_user_last_name')->getString();
    $first_name2 = $user_to->get('field_user_first_name')->getString();
    $last_name2 = $user_to->get('field_user_last_name')->getString();

    $this->logger->info("  Migrating data for user '$first_name1 $last_name1' to '$first_name2 $last_name2'");

    $summary = [];
    $summary['nodes'] = $this->mergeNodes($user_from, $user_to);
    $summary['node_references'] = $this->mergeNodeUserReferences($user_from, $user_to);
    $summary['event_series'] = $this->mergeEventSeries($user_from, $user_to);
    $summary['event_instances'] = $this->mergeEventInstances($user_from, $user_to);
    $summary['event_registrations'] = $this->mergeEventRegistrations($user_from, $user_to);
    $this->mergeUserFields($user_from, $user_to);
    $summary['roles'] = $this->mergeRoles($user_from, $user_to);
    $summary['webform_submissions'] = $this->mergeWebformSubmissions($user_from, $user_to);

    $flags = ['affinity_group', 'interest', 'skill', 'upvote', 'interested_in_project'];
    $flags_count = 0;
    foreach ($flags as $flag) {
      $flags_count += $this->mergeFlag($flag, $user_from, $user_to);
    }
    $summary['flags'] = $flags_count;

    return $summary;
  }

  /**
   * Merge the from-user into the to-user, then block the from-user.
   *
   * The entire operation runs in a single transaction so a mid-merge
   * failure cannot leave a half-merged account in a bad state.
   *
   * The source account is BLOCKED (disabled), not deleted: the user can no
   * longer log into or use the duplicate, but the record is retained so a
   * merge that missed something remains fully recoverable. This also avoids
   * data loss from delete side effects (e.g. registrants that stay on the
   * from-user would be orphaned by a delete) and from any non-transactional
   * user_delete hooks.
   *
   * @return array
   *   Summary of merged item counts.
   *
   * @throws \Drupal\user_profiles\Exception\UserMergeException
   */
  public function mergeAndBlock(int $from_user_id, int $to_user_id): array {
    $transaction = $this->database->startTransaction();
    try {
      $summary = $this->mergeUser($from_user_id, $to_user_id);
      $from_user = $this->entityTypeManager->getStorage('user')->load($from_user_id);
      if ($from_user instanceof User) {
        // Block (disable) rather than delete, so the merge stays reversible.
        $from_user->block();
        $from_user->save();
      }
      $this->logger->notice('Merged and blocked user @from into @to: @summary', [
        '@from' => $from_user_id,
        '@to' => $to_user_id,
        '@summary' => json_encode($summary),
      ]);
      return $summary;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('User merge @from -> @to failed and was rolled back: @message', [
        '@from' => $from_user_id,
        '@to' => $to_user_id,
        '@message' => $e->getMessage(),
      ]);
      throw new UserMergeException(
        sprintf('Merge of user %d into %d failed: %s', $from_user_id, $to_user_id, $e->getMessage()),
        0,
        $e
      );
    }
  }

  /**
   * Merge nodes from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   *
   * @return int
   *   Number of nodes migrated.
   */
  private function mergeNodes(User $user_from, User $user_to): int {

    if (!$this->entityTypeManager->hasDefinition('node')) {
      return 0;
    }

    $this->moduleHandler->loadInclude('node', 'inc', 'node.admin');

    $node_storage = $this->entityTypeManager->getStorage('node');
    $nodes = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user_from->id())
      ->execute();

    if (count($nodes) == 0) {
      $this->logger->info("No nodes to migrate");
      return 0;
    }

    $this->logger->info("Migrating nodes");
    $this->logger->info("  Changing ownership of these node titles: ");
    foreach ($nodes as $nid) {
      $node = $node_storage->load($nid);
      if ($node instanceof Node) {
        $this->logger->info("    " . $node->getTitle());
      }
    }

    // Legacy D8/9 helper; still works in D10/11. Candidate for entity-query+save replacement later.
    // @phpstan-ignore-next-line function.notFound
    node_mass_update($nodes, ['uid' => $user_to->id()], NULL, TRUE);

    return count($nodes);
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
   *
   * @return int
   *   Number of nodes updated.
   */
  private function mergeNodeUserReferences(User $user_from, User $user_to): int {

    if (!$this->entityTypeManager->hasDefinition('node')) {
      return 0;
    }

    $this->logger->info("Migrating user references in nodes");

    // Define which content types have which user reference fields.
    $content_type_fields = [
      'match_engagement' => ['field_students', 'field_mentor', 'field_researcher'],
      'mentorship_engagement' => ['field_mentor', 'field_mentee'],
    ];

    $node_storage = $this->entityTypeManager->getStorage('node');
    $count = 0;

    foreach ($content_type_fields as $content_type => $fields) {
      foreach ($fields as $field_name) {
        // Find nodes where this field references the from_user.
        $query = $node_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $content_type)
          ->condition($field_name, $user_from->id());
        $nids = $query->execute();

        if (empty($nids)) {
          continue;
        }

        $this->logger->info("  Updating $field_name in $content_type nodes");
        foreach ($nids as $nid) {
          $node = $node_storage->load($nid);
          if (!$node instanceof Node) {
            continue;
          }
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
            $this->logger->info("    Updated node '{$node->getTitle()}' (nid: $nid)");
            $count++;
          }
        }
      }
    }

    return $count;
  }

  /**
   * Merge event series ownership and other authors from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   *
   * @return int
   *   Number of event series migrated.
   */
  private function mergeEventSeries(User $user_from, User $user_to): int {
    $this->logger->info("Migrating event series");

    // Check if eventseries entity type exists.
    if (!$this->entityTypeManager->hasDefinition('eventseries')) {
      $this->logger->info("  Event series entity type not found, skipping");
      return 0;
    }

    $series_storage = $this->entityTypeManager->getStorage('eventseries');
    $count = 0;

    // Transfer ownership of event series.
    $series_ids = $series_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user_from->id())
      ->execute();

    if (!empty($series_ids)) {
      $this->logger->info("  Transferring ownership of event series");
      foreach ($series_ids as $series_id) {
        $series = $series_storage->load($series_id);
        if ($series instanceof EventInterface) {
          $this->logger->info("    Transferring '{$series->label()}' (id: $series_id)");
          $series->setOwnerId($user_to->id());
          $series->save();
          $count++;
        }
      }
    }

    // Update field_other_authors references.
    $series_with_author = $series_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_other_authors', $user_from->id())
      ->execute();

    if (!empty($series_with_author)) {
      $this->logger->info("  Updating field_other_authors in event series");
      foreach ($series_with_author as $series_id) {
        $series = $series_storage->load($series_id);
        if ($series instanceof EventInterface && $series->hasField('field_other_authors')) {
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
            $this->logger->info("    Updated other authors in '{$series->label()}' (id: $series_id)");
            $count++;
          }
        }
      }
    }

    if (empty($series_ids) && empty($series_with_author)) {
      $this->logger->info("  No event series to migrate");
    }

    return $count;
  }

  /**
   * Merge event instance ownership from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   *
   * @return int
   *   Number of event instances migrated.
   */
  private function mergeEventInstances(User $user_from, User $user_to): int {
    $this->logger->info("Migrating event instances");

    // Check if eventinstance entity type exists.
    if (!$this->entityTypeManager->hasDefinition('eventinstance')) {
      $this->logger->info("  Event instance entity type not found, skipping");
      return 0;
    }

    $instance_storage = $this->entityTypeManager->getStorage('eventinstance');

    $instance_ids = $instance_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user_from->id())
      ->execute();

    if (empty($instance_ids)) {
      $this->logger->info("  No event instances to migrate");
      return 0;
    }

    $this->logger->info("  Transferring ownership of " . count($instance_ids) . " event instances");
    foreach ($instance_ids as $instance_id) {
      $instance = $instance_storage->load($instance_id);
      if ($instance instanceof EventInterface) {
        $instance->setOwnerId($user_to->id());
        $instance->save();
      }
    }
    $this->logger->info("  Transferred ownership of " . count($instance_ids) . " event instances");

    return count($instance_ids);
  }

  /**
   * Merge event registrations from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   *
   * @return int
   *   Number of event registrations migrated.
   */
  private function mergeEventRegistrations(User $user_from, User $user_to): int {
    $this->logger->info("Migrating event registrations");

    // Check if registrant entity type exists.
    if (!$this->entityTypeManager->hasDefinition('registrant')) {
      $this->logger->info("  Registrant entity type not found, skipping");
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('registrant');

    $registrant_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user_id', $user_from->id())
      ->execute();

    if (empty($registrant_ids)) {
      $this->logger->info("  No event registrations to migrate");
      return 0;
    }

    $this->logger->info("  Transferring " . count($registrant_ids) . " event registrations");
    $count = 0;

    foreach ($registrant_ids as $registrant_id) {
      $registrant = $storage->load($registrant_id);
      if ($registrant instanceof ContentEntityInterface) {
        // Check if to_user already has a registration for this event instance.
        $event_instance_id = $registrant->get('eventinstance_id')->target_id;
        $existing = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('user_id', $user_to->id())
          ->condition('eventinstance_id', $event_instance_id)
          ->execute();

        if (!empty($existing)) {
          // To-user is already registered for this instance, so leave this
          // registrant on the from-user rather than creating a duplicate.
          // Because the from-user is blocked (not deleted), these skipped
          // registrants are retained, not orphaned.
          $this->logger->info("    To-user already registered for event instance $event_instance_id, skipping");
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
        $this->logger->info("    Transferred registration for event instance $event_instance_id");
        $count++;
      }
    }

    return $count;
  }

  /**
   * Merge various fields from $user_from to $user_to.
   *
   * @param \Drupal\user\Entity\User $user_from
   *   From user.
   * @param \Drupal\user\Entity\User $user_to
   *   To user.
   */
  private function mergeUserFields(User $user_from, User $user_to): void {
    $this->logger->info("Migrating user fields");

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
        $this->logger->info("  Merging field '$merge_field' $value_text");
        $user_to->get($merge_field)->setValue($from_val);
        $user_to->set($merge_field, $from_val);
      }
    }

    // Migrate the boolean field_is_cc manually.
    if (
      $user_from->get('field_is_cc')->getValue()[0]['value']
      && !$user_to->get('field_is_cc')->getValue()[0]['value']
    ) {
      $this->logger->info("  Setting to-user as a campus champion");
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
        $this->logger->info("  Migrating 'field_carnegie_code' with value '$from_carnegie_code'");
        $user_to->set('field_carnegie_code', $from_carnegie_code);
      }
      else {
        $this->logger->info("  Not Migrating 'field_carnegie_code' with "
          . "value '$from_carnegie_code' because differing institutions ('$from_inst' and '$to_inst')");
      }
    }
    else {
      $this->logger->info("  From user has no carnegie code to migrate.");
    }

    // Per Andrew:  field_region should only be added to, not replaced.
    $this->logger->info("  Migrating region / program fields");
    $from_region = $user_from->get('field_region')->referencedEntities();
    foreach ($from_region as $from_program) {
      $to_region = $user_to->get('field_region')->referencedEntities();
      if (count($to_region) == 0) {
        $this->logger->info("    Adding region / program '"
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
          $this->logger->info(
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
          $this->logger->info(
            "    To-user already a member of program '"
              . $from_program->getName() . "'"
                  );
        }
      }
    }

    // Badges should be combined from both users (multi-value entity reference).
    $this->logger->info("  Migrating user badges");
    $from_badges = $user_from->get('field_user_badges')->referencedEntities();
    foreach ($from_badges as $from_badge) {
      $to_badges = $user_to->get('field_user_badges')->referencedEntities();
      if (count($to_badges) == 0) {
        $this->logger->info("    Adding badge '"
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
          $this->logger->info(
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
          $this->logger->info(
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
   *
   * @return int
   *   Number of roles migrated.
   */
  private function mergeRoles(User $user_from, User $user_to): int {
    $this->logger->info("Migrating roles");

    $roles = $user_from->getRoles();
    $to_roles = $user_to->getRoles();
    $changes = FALSE;
    $count = 0;
    foreach ($roles as $role) {
      if (in_array($role, ['anonymous', 'authenticated', 'administrator'])) {
        $this->logger->info("  Skipping role '$role' - can't be assigned programatically");
      }
      elseif (in_array($role, $to_roles)) {
        $this->logger->info("  To-user already has role '$role'");
      }
      else {
        $this->logger->info("  Migrating role '$role'");
        $user_to->addRole($role);
        $changes = TRUE;
        $count++;
      }
    }
    if ($changes) {
      $user_to->save();
    }

    return $count;
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
   *
   * @return int
   *   Number of webform submissions migrated.
   */
  private function mergeWebformSubmissions(User $user_from, User $user_to): int {
    $this->logger->info("Migrating webform submissions");

    if (!$this->entityTypeManager->hasDefinition('webform_submission')) {
      return 0;
    }

    $ws_storage = $this->entityTypeManager->getStorage('webform_submission');
    $ws_query = $ws_storage->getQuery()
      ->condition('uid', $user_from->id())
      ->accessCheck(FALSE);
    $ws_results = $ws_query->execute();
    if ($ws_results == NULL) {
      $this->logger->info("  From-user has no webform submissions");
      return 0;
    }
    $count = 0;
    foreach ($ws_results as $ws_result) {
      $ws = $ws_storage->load($ws_result);
      if (!$ws instanceof WebformSubmission) {
        continue;
      }
      $ws_id = $ws->getWebform()->id();
      $ws->setOwner($user_to);
      $ws->save();
      $this->logger->info("  Updated ownership of webform submission of type $ws_id");
      $count++;
    }

    return $count;
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
   *
   * @return int
   *   Number of flags migrated.
   */
  private function mergeFlag(string $flag_name, User $user_from, User $user_to): int {
    $this->logger->info("Migrating flags with name '$flag_name'");

    $select = $this->database->select('flagging', 'fl');
    $select->condition('fl.uid', $user_from->id());
    $select->condition('fl.flag_id', $flag_name);
    $select->fields('fl', ['entity_id']);
    $flagged_items = $select->execute()->fetchCol();
    if ($flagged_items == NULL) {
      $this->logger->info("  From-user has no flags with name '$flag_name'");
      return 0;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $count = 0;

    foreach ($flagged_items as $flagged_item) {
      $term = $term_storage->load($flagged_item);
      if (!$term instanceof Term) {
        continue;
      }
      $title = $term->get('name')->value;

      // Check if already flagged. If not, set the flag.
      $flag = $this->flagService->getFlagById($flag_name);
      if (!$flag) {
        $this->logger->info("*** Error, flag '$flag_name' not found, skipping.");
        continue;
      }
      $flag_status = $this->flagService->getFlagging($flag, $term, $user_to);
      if (!$flag_status) {
        $bundles = $flag->getBundles();
        if (!empty($bundles) && !in_array($term->bundle(), $bundles)) {
          $this->logger->info("*** Error, flag '$flag_name' with title '$title' has bundle "
            . $term->bundle() . " which is not in allowed list: {"
            . implode(', ', $bundles) . '} -- skipping this one.');
        }
        else {
          $this->logger->info("  Adding flag $flag_name with title '$title' to to-user");
          $this->flagService->flag($flag, $term, $user_to);
          $count++;
        }
      }
      else {
        $this->logger->info("  To-user already has flag $flag_name with title '$title'");
      }
    }

    return $count;
  }

}
