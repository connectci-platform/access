<?php

namespace Drupal\access_affinitygroup\Commands;

use Drupal\access_affinitygroup\Plugin\AllocationsUsersImport;
use Drupal\access_affinitygroup\Plugin\ConstantContactApi;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\user\Entity\User;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile for Affinity Groups.
 *
 * @package Drupal\access_affinitygroup\Commands
 */
class AffinityGroupCommands extends DrushCommands {

  /**
   * Add existing Affinity Group members to Constant Contact lists.
   *
   * Save all Affinity Groups to trigger the creation of the
   * associated Constant Contact list. Then add all existing members of
   * the group to that Constant Contact list.
   *
   * @command access_affinitygroup:initConstantContact
   * @aliases initConstantContact
   * @usage access_affinitygroup:initConstantContact
   */
  public function initConstantContact() {
    // Get all the Affinity Groups.
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'affinity_group')
      ->accessCheck(FALSE)
      ->execute();
    $nodes = Node::loadMultiple($nids);
    $cca = new ConstantContactApi();

    foreach ($nodes as $node) {
      $this->output()->writeln($node->getTitle());
      // If there isn't a Constant Contact list_id,
      // trigger save to generate Constant Contact list.
      $list_id = $node->get('field_list_id')->value;
      $this->output()->writeln($list_id);
      if (!$list_id || strlen($list_id) < 1) {
        $node->save();
        $list_id = $node->get('field_list_id')->value;
      }

      // Get the Users who have flagged the associated term.
      $term = $node->get('field_affinity_group');
      $flag_service = \Drupal::service('flag');
      $flags = $flag_service->getAllEntityFlaggings($term->entity);
      foreach ($flags as $flag) {
        $uid = $flag->get('uid')->target_id;
        $this->output()->writeln($uid);
        $user = User::load($uid);

        $first_name = $user->get('field_user_first_name')->getString();
        $last_name = $user->get('field_user_last_name')->getString();

        // CC names can only be 50 chars.
        $first_name = substr($first_name, 0, 49);
        $last_name = substr($last_name, 0, 49);

        $this->output()->writeln($first_name);
        $this->output()->writeln($last_name);

        // Get the Constant Contact id for the User.
        $env = $cca->getEnvironment();

        $field_val = $user->get('field_constant_contact_id')->getValue();
        if (!empty($field_val) && $field_val != 0) {
          $cc_id = $field_val[0]['value'];
          $cc_id = json_decode($cc_id, TRUE);
          $cc_id = $cc_id[$env];
          $this->output()->writeln($first_name . ' ' . $last_name . ' already has cc id: ' . $cc_id);
        }
        else {
          // User did not already have the CC id.
          // Check if they are already in CC.
          $resp = $cca->apiCall('/contacts?status=all&email=' . $user->getEmail());
          if ($resp->contacts) {
            $cc_id = $resp->contacts[0]->contact_id;
            $this->output()->writeln($cc_id);
          }
          else {
            // Try to add to CC.
            $cc_id = $cca->addContact($first_name, $last_name, $user->getEmail());
            // Delay for api limit.
            usleep(500);
          }
          $this->output()->writeln($cc_id);

          $user_cc_id = [
            'test' => '',
            'openondemand' => '',
            'support' => '',
          ];
          $user_cc_id[$env] = $cc_id;
          $user_cc_id = json_encode($user_cc_id);

          $user->set('field_constant_contact_id', $user_cc_id);
          $user->save();
          $this->output()->writeln('Added ' . $first_name . ' ' . $last_name);
        }
        $post_data = [
          'source' => [
            'contact_ids' => [$cc_id],
          ],
          'list_ids' => [$list_id],
        ];
        // $this->output()->writeln(var_dump($post_data));
        $cca->apiCall('/activities/add_list_memberships', json_encode($post_data), 'POST');
        // Delay for api limit.
        usleep(500);
      }
    }
  }

  /**
   * @command access_affinitygroup:showAffinityGroups
   * @param   $agName
   *   default: show all, otherwise, only show affinity group
   *   with this title (case-sensitive)
   * @option  uidonly
   *         Only show user ids in list, not names or cc ids
   * @option  headonly
   *          Don't show members; just list the groups
   * @aliases showAffinityGroups
   * @usage   access_affinitygroup:showAffinityGroups
   */
  public function showAffinityGroups(string $agName = '', $options = ['uidonly' => FALSE, 'headonly' => FALSE]) {
    $uidOnly = $options['uidonly'];
    $headOnly = $options['headonly'];

    $cca = new ConstantContactApi();

    // Get all the Affinity Groups.
    $agCount = 0;
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'affinity_group')
      ->accessCheck(FALSE)
      ->execute();
    $nodes = Node::loadMultiple($nids);

    foreach ($nodes as $node) {
      if (strlen($agName) > 0 && $agName <> $node->getTitle()) {
        continue;
      }
      $agCount += 1;
      $uCount = 0;

      $this->output()->writeln('');
      $this->output()->writeln('***********************************************');
      $this->output()->writeln('');
      $this->output()->writeln($agCount . '. ' . $node->getTitle());
      $this->output()->writeln('');

      // If there isn't a Constant Contact list_id,
      // trigger save to generate Constant Contact list.
      $list_id = $node->get('field_list_id')->value;

      if (!$list_id || strlen($list_id) < 1) {
        $this->output()->writeln('NO list id');
      }
      else {
        $this->output()->writeln('list id: ' . $list_id);
      }

      $agCat = $node->get('field_affinity_group_category')->value;
      if (empty($agCat)) {
        $agCat = 'NO category';
      }

      // Logo.
      if (!empty($node->get('field_image')->entity)) {
        $uri = $node->get('field_image')->entity->getFileUri();
      }
      else {
        $uri = 'NO image uri';
      }

      $this->output()->writeln($agCat . ' /  ' . $uri);

      // Get the Users who have flagged the associated term.
      $term = $node->get('field_affinity_group');
      $userIds = $this->getUserIdsFromFlags($term->entity);
      $this->output()->writeln('Members count: ' . count($userIds));

      if ($headOnly) {
        continue;
      }

      foreach ($userIds as $uid) {

        $uCount += 1;
        $this->output()->writeln('-- ' . $uCount . '. ' . $uid);
        if (!$uidOnly) {
          $user = User::load($uid);

          $first_name = $user->get('field_user_first_name')->getString();
          $last_name = $user->get('field_user_last_name')->getString();

          // CC names can only be 50 chars.
          $first_name = substr($first_name, 0, 49);
          $last_name = substr($last_name, 0, 49);

          $this->output()->writeln($first_name);
          $this->output()->writeln($last_name);

          // Get the Constant Contact id for the User.
          $field_val = $user->get('field_constant_contact_id')->getValue();
          if (!empty($field_val) && $field_val != 0) {
            // Get the Constant Contact id for the User.
            $env = $cca->getEnvironment();

            $cc_id = $field_val[0]['value'];
            $cc_id = json_decode($cc_id, TRUE);
            if (isset($cc_id[$env])) {
              $cc_id = $cc_id[$env];
            }
            else {
              $cc_id = 'NO cc id for ' . $env;
            }

          }
          $this->output()->writeln('cc id: ' . $cc_id);
        }
      }
    }
  }

  /**
   * Returns the user ids that have flagged an affinity group.
   * Necessary to bypass the part of the flagging service
   * $flag_service->getAllEntityFlaggings($term->entity)
   * which loads all the flags at once. That function crashes when run to get
   * the user flags on the ACCESS Support affinity group, with over 20k members.
   * Here we do the same, except we don't load all the flags at once, and just
   * assemble a list of the user ids which is the only part of the flag we need.
   * term: taxonomy term entity for the affinity group.
   */
  public function getUserIdsFromFlags(EntityInterface $term) {

    $entityTypeManager = \Drupal::service('entity_type.manager');
    $query = $entityTypeManager->getStorage('flagging')->getQuery();
    $query->accessCheck();

    $query->condition('entity_type', $term->getEntityTypeId())
      ->condition('entity_id', $term->id());

    $ids = $query->execute();
    $userIds = [];
    foreach ($ids as $flagId) {
      $flagging = $entityTypeManager->getStorage('flagging')->load($flagId);
      $userIds[] = $flagging->get('uid')->first()->getValue()['target_id'];
    }
    return ($userIds);
  }

  /**
   * @command access_affinitygroup:showNews
   *
   * @aliases showNews
   * @usage   access_affinitygroup:showNews
   */
  public function showNews() {
    // Get all the News.
    $nCount = 0;

    // ->condition('status', 1)
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'access_news')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();
    $nodes = Node::loadMultiple($nids);

    $dtLastWeek = new \DateTime('-7 days');
    $dtYesterday = new \DateTime('yesterday');

    $fmDate1 = DrupalDateTime::createFromDateTime($dtLastWeek)->format(DateTimeItemInterface::DATE_STORAGE_FORMAT);
    $fmDate2 = DrupalDateTime::createFromDateTime($dtYesterday)->format(DateTimeItemInterface::DATE_STORAGE_FORMAT);

    $this->output()->writeln('from: ' . $fmDate1);
    $this->output()->writeln('to:   ' . $fmDate2);

    // $a = $ddtA->format(DateTimeItemInterface::DATE_STORAGE_FORMAT);
    $nids = \Drupal::entityQuery('node')
      ->condition('field_published_date.value', $fmDate1, '>=')
      ->condition('field_published_date.value', $fmDate2, '<=')
      ->condition('type', 'access_news')
      ->accessCheck(FALSE)
      ->execute();

    $nodes = Node::loadMultiple($nids);

    $nCount = 0;
    foreach ($nodes as $node) {
      $nCount += 1;
      $this->output()->writeln('--------------------------------------------------');
      $this->output()->writeln($nCount . '. ' . $node->getTitle());
      $this->output()->writeln($node->get('field_published_date')->value);

      $view_builder = \Drupal::entityTypeManager()->getViewBuilder('node');
      $renderArray = $view_builder->view($node, 'newsBody');
      // $renderArray = $view_builder->view($node);
      $display = \Drupal::service('renderer')->renderPlain($renderArray);
      $this->output()->writeln($display);

      $this->output()->writeln('---');

      $getFields = $node->getFields();
      $bodyArray = $getFields['body']->getValue();
      $body = $bodyArray[0]['value'];
      $display = check_markup($body, 'basic_html');
      $this->output()->writeln($display);

      $newsUrl = $node->toUrl()->setAbsolute()->toString();
    }
  }

  /**
   * @command access_affinitygroup:showEventsI
   *
   * @aliases showEventsI
   * @usage   access_affinitygroup:showEventsI
   *
   * List all event instances
   * Modify entityQuery call to specify conditions
   */
  public function showEventsI() {
    $timezone = new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    $dtCurrent = new \DateTime('today', $timezone);
    $dtNextMonth = new \DateTime('today+1 month + 2 day', $timezone);

    $fmDate1 = DrupalDateTime::createFromDateTime($dtCurrent)
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $fmDate2 = DrupalDateTime::createFromDateTime($dtNextMonth)
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);

    $this->output()->writeln($fmDate1);
    $this->output()->writeln($fmDate2);

    // Get all event instances (published: status=1)
    // in the date range.
    $eCount = 0;
    $eids = \Drupal::entityQuery('eventinstance')
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('date.value', $fmDate1, '>=')
      ->condition('date.value', $fmDate2, '<')
      ->sort('date.value', 'ASC')
      ->execute();

    $eventNodes = EventInstance::loadMultiple($eids);

    foreach ($eventNodes as $enode) {

      $fields = $enode->getFields();
      $titleArray = $fields['title']->getValue();
      $title = $titleArray[0]['value'];
      $eCount += 1;

      $this->output()->writeln($eCount . '. ' . $title);
      // $this->output()->writeln('status:' . $enode->get('status')->value);
      $this->output()->writeln('date:' . $enode->get('date')->value);
      $this->output()->writeln('');

      // $enode->get('date')->value is in UTC 2023-02-13T19:33:00  (this was input as 2:33 pm NY time)
      // Get the custom view display eventinstance.email_summary
      $view_builder = \Drupal::entityTypeManager()->getViewBuilder('eventinstance');
      $renderArray = $view_builder->view($enode, 'rollup_list');
      $display = \Drupal::service('renderer')->renderPlain($renderArray);

      // $this->output()->writeln($display);
      // TEMP
    }
  }

  /**
   * @command access_affinitygroup:showEventsS
   *
   * @aliases showEventsS
   * @usage   access_affinitygroup:showEventsS
   *
   * Get all eventseries.
   */
  public function showEventsS() {
    $eCount = 0;

    $nids = \Drupal::entityQuery('eventseries')
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->execute();

    $nodes = EventSeries::loadMultiple($nids);

    foreach ($nodes as $node) {

      $eCount += 1;

      $fields = $node->getFields();
      $titleArray = $fields['title']->getValue();
      $title = $titleArray[0]['value'];

      $this->output()->writeln($eCount . '. ' . $title);
      $this->output()->writeln('status:' . $node->get('status')->value);
      // $this->output()->writeln($node->get('body')->summary);  // show just summary,
      // $this->output()->writeln($node->get('body')->value);
      $view_builder = \Drupal::entityTypeManager()->getViewBuilder('eventinstance');
      // $renderArray = $view_builder->view($node);
      // $this->output()->writeln('c-----------');
      $body = '';
      // $body = \Drupal::service('renderer')->renderPlain($renderArray);
      // $this->output()->writeln('d-----------');
      // $this->output()->writeln($body);
      // $this->output()->writeln('e-----------');
      // $this->output()->writeln($node->getCreated());
      // $this->output()->writeln($node->get('status')->value);
      //               $this->output()->writeln($node->get('body')->summary);  // show just summary,
      // which only has something if spefically set. otherwise we need to override with a trunc of body.
      // $this->output()->writeln($node->get('body')->summary);   // show whole body
      //             $this->output()->writeln($node->get('field_published_date')->value);
    }
  }

  /**
   * @command access_affinitygroup:newsRollup
   *
   * @aliases newsRollup
   * @usage   access_affinitygroup:newsRollup
   */
  public function newsRollup() {
    $retval = weeklyNewsReport(TRUE);
    $this->output()->writeln($retval);
  }

  /**
   * @command access_affinitygroup:importAllocations
   *
   * @aliases importAllocations
   * @usage   access_affinitygroup:importAllocations
   * Runs the function the cron would run, using settings
   * set at the constant contact admin form.
   */
  public function importAllocations() {
    $aui = new AllocationsUsersImport();
    $aui->runCronSlice();
  }

  /**
   * @command access_affinitygroup:syncAGandCC
   * @aliases syncAGandCC
   * @usage   access_affinitygroup:syncAGandCC
   */
  public function syncAGandCC() {
    $aui = new AllocationsUsersImport();
    $aui->syncAGandCC(25, 27);
    // $aui->syncAGandCC();
  }

  /**
   * @command access_affinitygroup:allocCleanObs
   * @aliases allocCleanObs
   * @usage   access_affinitygroup:allocCleanObs
   */
  public function allocCleanObs() {
    $aui = new AllocationsUsersImport();
    $aui->cleanObsoleteAllocations(1, 2);
    // $aui->cleanObsoleteAllocations();
  }

}
