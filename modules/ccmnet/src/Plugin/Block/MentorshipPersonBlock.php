<?php

namespace Drupal\ccmnet\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\user\Entity\User;

/**
 * Provides a 'MentorshipPerson' Block.
 *
 * @Block(
 *   id = "mentorship_person_block",
 *   admin_label = @Translation("Mentorship Person")
 * )
 */
class MentorshipPersonBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    // Note: title from layout builder block placement used here.
    $isMentor = $this->configuration['label'] == 'Mentor' ? TRUE : FALSE;
    $personFieldName = $isMentor ? 'field_mentor' : 'field_mentee';

    $node_param = \Drupal::routeMatch()->getParameter('node');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

    // Need this for using layout builder.
    if (empty($node_param) || empty($node_param->id())) {
      return [
        '#markup' => $this->t('No node.'),
      ];
    }
    $node = $node_storage->load($node_param->id());

    $userName = '';
    $userImage = '';
    $institution = '';
    $personA = $node->get($personFieldName)->getValue();

    if (empty($personA) || empty([$personA][0])) {
      return [];
    }
    else {
      $title = $isMentor ? 'Mentor' : 'Mentee';
      $title .= isset($personA[1]) ? 's' : '';
      $display = "<h2>$title</h2>";
      foreach ($personA as $person) {
        $personId = $person['target_id'];
        // Load user from user id mentee.
        $user = User::load($personId);  // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass

        // Get user profile picure image.
        $userImage = $user->get('user_picture');

        if ($userImage->entity !== NULL) {
          $userImage = $userImage->entity->getFileUri();
          $userImage = \Drupal::service('file_url_generator')->generateAbsoluteString($userImage);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
          $alt = $user->getDisplayName() . ' profile picture';
        }
        else {
          $userImage = '/themes/nect-theme/img/user-picture.svg';
          $alt = 'Default profile picture';
        }
        $userImage = '<img alt="' . $alt . '" src="' . $userImage . '" />';

        // Show access organization if set and not "Other"; otherwise,
        // use institution field.
        $orgArray = $user->get('field_access_organization')->getValue();
        $institution = '';

        if (!empty($orgArray) && !empty($orgArray[0])) {
          $nodeId = $orgArray[0]['target_id'];
          if (!empty($nodeId)) {
            $orgNode = \Drupal::entityTypeManager()->getStorage('node')->load($nodeId);  // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
            if ($orgNode) {
              $orgTitle = $orgNode->getTitle();
              // If organization is "Other", use the institution field instead.
              if ($orgTitle === 'Other') {
                $institution = $user->get('field_institution')->value;
              }
              else {
                $institution = $orgTitle;
              }
            }
          }
        }

        // Fallback to institution field if no organization or if
        // organization loading failed.
        if (empty($institution)) {
          $institution = $user->get('field_institution')->value;
        }
        $userName = $user->getDisplayName();
        $userUrl = "/community-persona/$personId";

        $display .= '<div class="d-flex justify-content-start mentorship-person mb-3">' .
          '<div class="mentorship-person-picture p-0">' . $userImage . '</div>' .
          '<div class="col d-flex  flex-column justify-content-start">' .
          '<div><strong><a href="' . $userUrl . '">' . $userName . '</a></strong></div><div>' . $institution . '</div></div></div>';
      }

    }

    return [
      '#markup' => $this->t($display), // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    ];
  }

}
