<?php

namespace Drupal\access_match_engagement\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Match.
 */
class MatchController extends ControllerBase {

  /**
   * Build content to display on page.
   */
  public function interestedContent() {
    $nid = \Drupal::routeMatch()->getRawParameter('node');
    // Load entity node using node id.
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if ($node->getType() == 'match_engagement' || $node->getType() == 'mentorship_engagement') {
      $current_user = \Drupal::currentUser()->id();
      $interested_users = $node->get('field_match_interested_users')->getValue();
      if (array_search($current_user, array_column($interested_users, 'target_id')) !== FALSE) {
        foreach ($interested_users as $key => $interested_user) {
          if ($interested_user['target_id'] == $current_user) {
            unset($interested_users[$key]);
          }
        }
        $node->set('field_match_interested_users', $interested_users);
        $node->save();
        \Drupal::messenger()->addStatus(t("You have been removed from the interested list"));
      }
      else {
        $interested_users[] = ['target_id' => $current_user];
        // Get current user.
        $current_user = \Drupal::currentUser();
        \Drupal::logger('access_match_engagement')->notice('User @current_user added to interested list', ['@current_user' => $current_user->getAccountName()]);
        if ($node->getType() == 'match_engagement') {
          $config = \Drupal::configFactory()->getEditable('access_match_engagement.settings');
          $config->set('interested', 1);
          $config->save();
        }
        if ($node->getType() == 'mentorship_engagement') {
          $interested_list = \Drupal::state()->get('access_mentorship_interested');
          $create_list = [];
          if (!empty($interested_list) && $interested_list !== '0') {
            $decoded = json_decode($interested_list, TRUE);
            if ($decoded !== null && is_array($decoded)) {
              $create_list = $decoded;
            }
          }
          if (!in_array($nid, $create_list)) {
            $create_list[] = $nid;
          }
          $interested_list = json_encode($create_list);
          \Drupal::state()->set('access_mentorship_interested', $interested_list);
        }
        // Update node field.
        $node->set('field_match_interested_users', $interested_users);
        $node->save();
        \Drupal::messenger()->addStatus($this->t("You have been added to the interested list"));
      }
    }
    \Drupal::service('page_cache_kill_switch')->trigger();
    // Redirect to node.
    $url = Url::fromRoute('entity.node.canonical', ['node' => $nid]);
    return new RedirectResponse($url->toString());
  }

}
