<?php

namespace Drupal\cssn\Controller;

use Drupal\webform\Entity\WebformSubmission;
use Drupal\views\Views;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Html;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ccmnet\Plugin\Util\MentorshipLookup;
use Drupal\cssn\Plugin\Util\EndUrl;
use Drupal\cssn\Plugin\Util\MatchLookup;
use Drupal\cssn\Plugin\Util\ProjectLookup;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Community Persona.
 */
class CommunityPersonaController extends ControllerBase {

  /**
   * List of affinity groups given user has flagged.
   *
   * @return string
   *   List of affinity groups.
   */
  public function affinityGroupList($user, $public = FALSE) {
    $query = \Drupal::database()->select('flagging', 'fl');
    $query->condition('fl.uid', $user->id());
    $query->condition('fl.flag_id', 'affinity_group');
    $query->fields('fl', ['entity_id']);
    $affinity_groups = $query->execute()->fetchCol();
    $affinity_groups = array_unique($affinity_groups);
    $user_affinity_groups = "<ul>";
    if ($affinity_groups == NULL && $public === FALSE) {
      $user_affinity_groups = '<p class="mb-3">' . t('You currently are not connected to any Affinity groups. Click below to explore.') . "</p>";
    }
    if ($affinity_groups == NULL && $public === TRUE) {
      $user_affinity_groups = '<p class="mb-3">' . t('Not connected to any Affinity groups.') . "</p>";
    }
    if ($user_affinity_groups == '<ul>') {
      $user_affinity_groups = '<ul class="grid grid-cols-2 my-3">';
      foreach ($affinity_groups as $affinity_group) {
        $query = \Drupal::database()->select('taxonomy_index', 'ti');
        $query->condition('ti.tid', $affinity_group);
        $query->fields('ti', ['nid']);
        $affinity_group_nid = $query->execute()->fetchCol();

        $ag_node = [];
        foreach ($affinity_group_nid as $nid) {
          $affinity_group_loaded = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
          // Get node type.
          $node_type = $affinity_group_loaded->bundle();
          if ($node_type == 'affinity_group') {
            $ag_node = $affinity_group_loaded;
            break;
          }
        }

        if ($ag_node) {
          $affinity_group_loaded = $ag_node;
          $persona_source = $affinity_group_loaded->get('field_persona_source')->value;
          $env = getenv('PANTHEON_ENVIRONMENT');
          $token = \Drupal::token();
          $domainName = Html::getClass($token->replace(t('[domain:name]')));

          if ($persona_source == 'openondemand' && $domainName != 'open-ondemand' && $env == 'live') {
            $url = Url::fromUri('https://ondemand.connectci.org/node/' . $affinity_group_loaded->id());
          }
          elseif ($persona_source == 'access' && $domainName != 'access' && $env == 'live') {
            $url = Url::fromUri('https://support.access-ci.org/node/' . $affinity_group_loaded->id());
          }
          elseif ($persona_source == 'ccmnet' && $domainName != 'ccmnet' && $env == 'live') {
            $url = Url::fromUri('https://ccmnet.org/node/' . $affinity_group_loaded->id());
          }
          else {
            $url = Url::fromRoute('entity.node.canonical', ['node' => $affinity_group_loaded->id()]);
          }

          $class = ['font-bold', 'underline', 'hover--no-underline', 'hover--text-dark-teal'];
          $project_link = Link::fromTextAndUrl($affinity_group_loaded->getTitle(), $url)->toRenderable();
          $project_link['#attributes'] = ['class' => $class];
          $link = \Drupal::service('renderer')->render($project_link);
          $user_affinity_groups .= "<li>$link</li>";
        }
      }
      $user_affinity_groups .= '</ul>';
    }
    return $user_affinity_groups;
  }

  /**
   * Link to Affinity page.
   *
   * @return string
   *   Link to affinity page.
   */
  public function buildAffinityLink() {
    $affinity_url = Url::fromUri('internal:/affinity-groups');
    $affinity_link = Link::fromTextAndUrl('All Affinity Groups', $affinity_url);
    $affinity_renderable = $affinity_link->toRenderable();
    $build_affinity_link = $affinity_renderable;
    $build_affinity_link['#attributes']['class'] = ['btn', 'btn-outline-dark', 'btn-md-teal', 'btn-sm', 'py-1', 'px-2', 'm-0'];
    return $build_affinity_link;
  }

  /**
   * Return list of flagged Expertise.
   *
   * @return string
   *   List of expertise.
   */
  public function mySkills($user, $public = FALSE) {
    $term = \Drupal::database()->select('flagging', 'fl');
    $term->condition('fl.uid', $user->id());
    $term->condition('fl.flag_id', 'skill');
    $term->fields('fl', ['entity_id']);
    $flagged_skills = $term->execute()->fetchCol();
    $my_skills = "";
    if ($flagged_skills == NULL && $public === FALSE) {
      $my_skills = '<p class="mb-3">' . t('You currently have not added any skills. Click update expertise to add.') . "</p>";
    }
    if ($flagged_skills == NULL && $public === TRUE) {
      $my_skills = '<p>' . t('No skills added.') . "</p>";
    }
    if ($my_skills == "") {
      foreach ($flagged_skills as $flagged_skill) {
        $term_title = Term::load($flagged_skill)->get('name')->value;
        $my_skills .= "<a class='no-underline font-normal mb-1 me-1 mr-1 px-2 py-1 hover--border-dark-teal border' href='/taxonomy/term/" . $flagged_skill . "'>" . $term_title . "</a>";
      }
    }
    return $my_skills;
  }

  /**
   * Return list of Knowledge Contributions.
   *
   * @return string
   *   List of Knowledge Contributions.
   */
  public function knowledgeBaseContrib($user, $public = FALSE) {
    $ws_query = \Drupal::entityQuery('webform_submission')
      ->condition('uid', $user->id())
      ->condition('uri', '/form/resource')
      ->accessCheck(FALSE);
    $ws_results = $ws_query->execute();
    $ws_link = "<ul>";
    if ($ws_results == NULL && $public === FALSE) {
      $ws_link = '<p class="mb-3">' . t('You currently have not contributed to the Knowledge Base. Click below to contribute.') . "</p>";
    }
    if ($ws_results == NULL && $public === TRUE) {
      $ws_link = '<p>' . t('No contributions to the Knowledge Base.') . "</p>";
    }
    if ($ws_link == "<ul>") {
      $ws_link = "<ul class='list-unstyled list-none mx-0 my-3 p-0'>";
      $n = 1;
      foreach ($ws_results as $ws_result) {
        $stripe_class = $n % 2 == 0 ? 'bg-light bg-light-teal' : '';
        $ws = WebformSubmission::load($ws_result);
        $url = '/knowledge-base/resources/' . $ws->id();
        $ws_data = $ws->getData();
        $ws_link .= '<li class="p-3 ' . $stripe_class . '"><a href=' . $url . ' class="font-bold underline hover--no-underline hover--text-dark-teal">' . $ws_data['title'] . '</a></li>';
        $n++;
      }
      $ws_link .= '</ul>';
    }
    return $ws_link;
  }

  /**
   * Return list of engagements.
   *
   * @return string
   *   List of engagements.
   */
  public function matchList($user, $public = FALSE) {
    $fields = [
      'field_match_interested_users' => 'Interested',
      'field_mentor' => 'Mentor',
      'field_students' => 'Student',
      'field_consultant' => 'Consultant',
      'field_researcher' => 'Researcher',
    ];
    $matches = new MatchLookup($fields, $user->id(), $public);
    // Sort by status.
    $matches->sortStatusMatches();
    $match_list = $matches->getMatchList();
    $match_link = "<ul class='list-unstyled mx-0 my-3 p-0'>";
    if ($match_list == NULL && $public === FALSE) {
      $match_link = '';
    }
    if ($match_list == NULL && $public === TRUE) {
      $match_link = '';
    }
    if ($match_link == "<ul class='list-unstyled mx-0 my-3 p-0'>") {
      $match_link .= $match_list . '</ul>';
    }
    return $match_link;
  }

  /**
   * Return list of mentorships.
   *
   * @return string
   *   List of mentorships.
   */
  public function mentorList($user, $public = FALSE) {
    $fields = [
      'field_match_interested_users' => 'Interested',
      'field_mentor' => 'Mentor',
      'field_mentee' => 'Mentee',
      'field_me_ccmnet_leadership' => 'CCMNet Leadership Team Liaison',
    ];
    $mentorships = new MentorshipLookup($fields, $user->id(), $public);
    $mentorship_list = $mentorships->getMentorshipList();
    $mentorship_link = "<ul class='list-unstyled mx-0 my-3 p-0'>";
    if ($mentorship_list == NULL && $public === FALSE) {
      $mentorship_link = '';
    }
    if ($mentorship_list == NULL && $public === TRUE) {
      $mentorship_link = '';
    }
    if ($mentorship_link == "<ul class='list-unstyled mx-0 my-3 p-0'>") {
      $mentorship_link .= $mentorship_list . '</ul>';
    }
    return $mentorship_link;
  }

  /**
   * Return list of engagements.
   *
   * @return string
   *   List of engagements.
   */
  public function mmatchList($user, $public = FALSE) {
    $fields = [
      'field_match_interested_users' => 'Interested',
      'field_mentor' => 'Mentor',
      'field_students' => 'Student',
      'field_consultant' => 'Consultant',
      'field_researcher' => 'Researcher',
    ];
    $matches = new MatchLookup($fields, $user->id(), $public);
    // Sort by status.
    $matches->sortStatusMatches();
    $match_list = $matches->getMatchList();
    $match_link = "<ul class='list-unstyled mx-0 my-3 p-0'>";
    if ($match_list == NULL && $public === FALSE) {
      $match_link = '';
    }
    if ($match_list == NULL && $public === TRUE) {
      $match_link = '';
    }
    if ($match_link == "<ul class='list-unstyled mx-0 my-3 p-0'>") {
      $match_link .= $match_list . '</ul>';
    }
    return $match_link;
  }

  /**
   * Return list of projects.
   *
   * @return string
   *   List of projects.
   */
  public function projectList($user, $public = FALSE) {
    $fields = [
      'email' => 'Project Leader',
      'mentor' => 'Mentor',
      'mentors' => 'Mentor',
      'mentee_s_' => 'Mentee',
      'student' => 'Student-facilitator(s)',
      'students' => 'Student-facilitator(s)',
      'interested_in_project' => 'Interested',
    ];
    $projects = new ProjectLookup($fields, $user->id(), $user->getEmail());
    $projects->sortStatusProjects();
    $project_list = $projects->getProjectList();
    $project_link = "<ul class='list-unstyled list-none mx-0 my-3 p-0'>";
    if ($project_list == NULL) {
      $project_link = 'na';
    }
    if ($project_link == "<ul class='list-unstyled list-none mx-0 my-3 p-0'>") {
      $project_link .= $project_list . '</ul>';
    }
    return $project_link;
  }

  /**
   * Return list of Interests.
   *
   * @return string
   *   List of the person's interest.
   */
  public function buildInterests($user, $public = FALSE) {
    $term_interest = \Drupal::database()->select('flagging', 'fl');
    $term_interest->condition('fl.uid', $user->id());
    $term_interest->condition('fl.flag_id', 'interest');
    $term_interest->fields('fl', ['entity_id']);
    $flagged_interests = $term_interest->execute()->fetchCol();
    $my_interests = "";
    if ($flagged_interests == NULL && $public === FALSE) {
      $my_interests = '<p>' . t('You currently have not added any interests. Click update interests to add.') . "</p>";
    }
    if ($flagged_interests == NULL && $public === TRUE) {
      $my_interests = '<p>' . t('No interests added.') . "</p>";
    }
    if ($my_interests == "") {
      foreach ($flagged_interests as $flagged_interest) {
        $term_title = Term::load($flagged_interest)->get('name')->value;
        $my_interests .= "<a class='no-underline font-normal mb-1 me-1 mr-1 px-2 py-1 hover--border-dark-teal border' href='/taxonomy/term/" . $flagged_interest . "'>" . $term_title . "</a>";
      }
    }
    return $my_interests;
  }

  /**
   * Return bio and bio summary.
   *
   * @return array
   *   Summary and full bio.
   */
  public function userBio($uid) {

    // Load the user using the current user id.
    $user_entity = \Drupal::entityTypeManager()->getStorage('user')->load($uid);

    // User Bio.
    if ($user_entity->get('field_user_bio')->value == NULL) {
      $bio = "";
    }
    else {
      $bio = $user_entity->get('field_user_bio')->value;
    }
    // Trim $bio to 450 characters.
    $bio_summary = $bio;
    if (strlen($bio) > 450) {
      $more = "<div class='mt-4 inline-block bg-light-teal'>
                  <button id='bio-more' onclick='bioMore()' style='border-width: 0 !important;' class='btn btn-link btn-sm text-dark-teal p-3' type='button' aria-expanded='false' aria-controls='full-bio'>
                    <i class='bi-chevron-down' aria-hidden='true'></i> More
                  </button>
                </div>";
      $bio_summary = substr($bio, 0, 450) . "... $more";
      $less = "<div class='mt-4 inline-block bg-light-teal'>
                  <button id='bio-less' onclick='bioLess()' style='border-width: 0 !important;' class='btn btn-link btn-sm text-dark-teal p-3' type='button' aria-expanded='true' aria-controls='full-bio'>
                    <i class='bi-chevron-up' aria-hidden='true'></i> Less
                  </button>
                </div>";
      $bio .= $less;
    }

    return [$bio_summary, $bio];
  }

  /**
   * Build content to display on page.
   */
  public function communityPersona() {
    // My Affinity Groups.
    $current_user = \Drupal::currentUser();

    // User Bio.
    $user_bio = $this->userBio($current_user->id());
    $bio_summary = $user_bio[0];
    $bio = $user_bio[1];

    // List of affinity groups.
    $user_affinity_groups = $this->affinityGroupList($current_user);
    // Affinity link.
    $build_affinity_link = $this->buildAffinityLink();
    // My Interests.
    $my_interests = $this->buildInterests($current_user);
    // Edit interests link.
    $edit_interest_url = Url::fromUri('internal:/community-persona/add-interest');
    $edit_interest_link = Link::fromTextAndUrl('Update interests', $edit_interest_url);
    $edit_interest_renderable = $edit_interest_link->toRenderable();
    $edit_interest_renderable['#attributes']['class'] = ['btn', 'btn-primary', 'btn-sm', 'py-1', 'px-2'];
    // My Expertise.
    $my_skills = $this->mySkills($current_user);
    // Link to add Skills/Expertise.
    $edit_skill_url = Url::fromUri('internal:/community-persona/add-skill');
    $edit_skill_link = Link::fromTextAndUrl('Update skills', $edit_skill_url);
    $edit_skill_renderable = $edit_skill_link->toRenderable();
    $edit_skill_renderable['#attributes']['class'] = ['btn', 'btn-primary', 'btn-sm', 'py-1', 'px-2'];
    // My Knowledge Base Contributions.
    $ws_link = $this->knowledgeBaseContrib($current_user);

    // Link to add Knowledge Base Contribution webform.
    $webform_url = Url::fromUri('internal:/form/resource');
    $webform_link = Link::fromTextAndUrl('Add Resource', $webform_url);
    $webform_renderable = $webform_link->toRenderable();
    $build_webform_link = $webform_renderable;
    $build_webform_link['#attributes']['class'] = ['btn', 'btn-outline-dark', 'btn-md-teal', 'btn-sm', 'py-1', 'px-2', 'm-0'];
    // My Match Engagements.
    $match_link = $this->matchList($current_user);
    // Link to see all Match Engagements.
    $match_engage_url = Url::fromUri('https://support.access-ci.org/engagements');
    $match_engage_link = Link::fromTextAndUrl('See engagements', $match_engage_url);
    $match_engage_renderable = $match_engage_link->toRenderable();
    $build_match_engage_link = $match_engage_renderable;
    $build_match_engage_link['#attributes']['class'] = ['btn', 'btn-outline-dark', 'btn-md-teal', 'btn-sm', 'py-1', 'px-2', 'm-0'];
    // Mentorships.
    $mentorships = $this->mentorList($current_user);
    // My Projects.
    $projects = $this->projectList($current_user);

    // Events user is registered for.
    $user_event_registrations = '';
    $total_items = 0;

    // Only show registrations for authenticated users
    if (!$current_user->isAnonymous()) {
      $view = Views::getView('recurring_events_registrations');
      $view->setDisplay('user_event_registrations');
      $view->setArguments([$current_user->id()]);
      $user_event_registrations = $view->buildRenderable('user_event_registrations');
      // Get the total items in the view.
      $view->execute();
      $total_items = $view->total_rows;
    }

    $persona_page['string'] = [
      '#type' => 'inline_template',
      '#attached' => [
        'library' => [
          'cssn/cssn_library',
        ],
      ],
      '#template' => '
        {% set skill_margin = "mb-3" %}
        {% if bio %}
        {% set skill_margin = "my-3" %}
        <div class="border border-secondary border-md-teal mb-3 mb-6">
          <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
            <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ bio_title }}</h2>
          </div>
          <div class="d-flex flex flex-wrap p-3">
            <div id="bio-summary" aria-hidden="false">
              {{ bio_summary |raw }}
            </div>
            <div id="full-bio" class="sr-only" aria-hidden="true">
              {{ bio |raw }}
            </div>
          </div>
        </div>
        {% endif %}
        <div class="border border-secondary border-md-teal {{ skill_margin }} mb-6">
          <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
            <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ me_title }}</h2>
          </div>
          <div class="d-flex flex flex-wrap p-3">
            {{ my_skills|raw }}
          </div>
          <div class="p-3 pt-0">{{ edit_skill_link }}</div>
        </div>
        <div class="border border-secondary border-md-teal my-3 mb-6">
          <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
            <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ mi_title }}</h2>
          </div>
          <div class="d-flex flex flex-wrap p-3">
            {{ my_interests|raw }}
          </div>
          <div class="p-3 pt-0">{{ edit_interest_link }}</div>
        </div>
        <div class="border border-secondary border-md-teal my-3 mb-6">
          <h2 class="h4 text-lg font-bold leading-5 text-white py-2 px-3 m-0 bg-dark bg-md-teal p-4">{{ ag_title }}</h2>
            <div class="p-3">
              <p>{{ ag_intro }}</p>
              {{ user_affinity_groups|raw }}
              {{ affinity_link }}
            </div>
        </div>
        <div class="border border-secondary border-md-teal my-3 mb-6">
          <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
            <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ ws_title }}</h2>
          </div>
          <div class="p-3">
            {{ ws_links|raw }}
            {{ request_webform_link }}
          </div>
        </div>

        {% if match_links != "" %}
          <div class="border border-secondary border-md-teal my-3 mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ match_title }}</h2>
            </div>
            <div class="p-3">
              {{ match_links|raw }}
              {{ request_match_link }}
            </div>
          </div>
        {% endif %}

        {% if mentorships != "" %}
          <div class="border border-secondary border-md-teal my-3 mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ mentorships_title }}</h2>
            </div>
            <div class="p-3">
              {{ mentorships|raw }}
            </div>
          </div>
        {% endif %}

        {% if projects != "na" %}
          <div class="border border-secondary border-md-teal my-3 mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ project_title }}</h2>
            </div>
            <div class="p-3">
              {{ projects|raw }}
            </div>
          </div>
        {% endif %}

        {% if user_event_total_items >= 1 %}
          <div class="border border-secondary border-md-teal my-3 mb-6 prose max-w-full">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ user_event_title }}</h2>
            </div>
            <div class="p-3">
              {{ user_event_registrations }}
            </div>
          </div>
        {% endif %}
        ',
      '#context' => [
        'bio_title' => t('Bio'),
        'bio_summary' => $bio_summary,
        'bio' => $bio,
        'ag_title' => t('My Affinity Groups'),
        'ag_intro' => t('Connect with researchers of common interests.'),
        'user_affinity_groups' => $user_affinity_groups,
        'affinity_link' => $build_affinity_link,
        'mi_title' => t('My Interests'),
        'my_interests' => $my_interests,
        'edit_interest_link' => $edit_interest_renderable,
        'me_title' => t('My Skills'),
        'my_skills' => $my_skills,
        'edit_skill_link' => $edit_skill_renderable,
        'match_title' => t('My MATCH Engagements'),
        'match_links' => $match_link,
        'mentorships_title' => t('My Mentorships'),
        'mentorships' => $mentorships,
        'request_match_link' => $build_match_engage_link,
        'project_title' => t('My Projects'),
        'projects' => $projects,
        'ws_title' => t('My Knowledge Base Contributions'),
        'ws_links' => $ws_link,
        'request_webform_link' => $build_webform_link,
        'user_event_title' => t('My Event Registrations'),
        'user_event_registrations' => $user_event_registrations,
        'user_event_total_items' => $total_items,
      ],
    ];

    // Deny any page caching on the current request.
    \Drupal::service('page_cache_kill_switch')->trigger();

    return $persona_page;
  }

  /**
   * Build public version of community persona page.
   */
  public function communityPersonaPublic() {
    // Get last item in url.
    $end_url = new EndUrl();
    $user_id = $end_url->getUrlEnd();
    $should_user_load = FALSE;
    // Get current user id.
    $current_user = \Drupal::currentUser();
    // Redirect to to profile if public persona page is for current user.
    if ($current_user->id() == $user_id) {
      $url = Url::fromUri('internal:/community-persona');
      $response = new RedirectResponse($url->toString());
      $response->send();
    }
    if (is_numeric($user_id)) {
      $user = User::load($user_id);
      // Don't show profile for people who haven't joined a region/program.
      if ($user !== NULL && count($user->field_region->getValue()) > 0) {
        $should_user_load = TRUE;
      }
      else {
        $should_user_load = FALSE;
      }
    }
    if ($should_user_load) {
      $user_first_name = $user->get('field_user_first_name')->value;
      $user_last_name = $user->get('field_user_last_name')->value;

      // User Bio.
      $user_bio = $this->userBio($user->id());
      $bio_summary = $user_bio[0];
      $bio = $user_bio[1];

      // List of affinity groups.
      $user_affinity_groups = $this->affinityGroupList($user, TRUE);
      // My Interests.
      $my_interests = $this->buildInterests($user, TRUE);
      // My Expertise.
      $my_skills = $this->mySkills($user, TRUE);
      // My Knowledge Base Contributions.
      $ws_link = $this->knowledgeBaseContrib($user, TRUE);
      // My Match Engagements.
      $match_link = $this->matchList($user, TRUE);
      // Mentorships.
      $mentorships = $this->mentorList($user, TRUE);
      // My Projects.
      $projects = $this->projectList($user, TRUE);

      $persona_page['#title'] = "$user_first_name $user_last_name";
      $persona_page['string'] = [
        '#type' => 'inline_template',
        '#attached' => [
          'library' => [
            'cssn/cssn_library',
          ],
        ],
        '#template' => '
          {% set skill_margin = "mb-3" %}
          {% if bio %}
          {% set skill_margin = "my-3" %}
          <div class="border border-secondary border-md-teal mb-3 mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 text-white m-0">{{ bio_title }}</h2>
            </div>
            <div class="d-flex flex flex-wrap p-3">
              <div id="bio-summary" aria-hidden="false">
                {{ bio_summary |raw }}
              </div>
              <div id="full-bio" class="sr-only" aria-hidden="true">
                {{ bio |raw }}
              </div>
            </div>
          </div>
          {% endif %}
          <div class="border border-secondary border-md-teal {{ skill_margin }} mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 text-white m-0">{{ me_title }}</h2>
            </div>
            <div class="d-flex flex flex-wrap p-3">
              {{ my_skills|raw }}
            </div>
          </div>
          <div class="border border-secondary border-md-teal my-3 mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 text-white m-0">{{ mi_title }}</h2>
            </div>
            <div class="d-flex flex flex-wrap p-3">
              {{ my_interests|raw }}
            </div>
          </div>
          <div class="border border-secondary border-md-teal my-3 mb-6">
            <h2 class="h4 text-lg font-bold leading-5 text-white py-2 px-3 m-0 bg-dark bg-md-teal p-4">{{ ag_title }}</h2>
              <div class="p-3">
                {{ user_affinity_groups|raw }}
              </div>
          </div>
          <div class="border border-secondary border-md-teal my-3 mb-6">
            <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
              <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ ws_title }}</h2>
            </div>
            <div class="p-3">
              {{ ws_links|raw }}
            </div>
          </div>

          {% if match_links != "" %}
            <div class="border border-secondary border-md-teal my-3 mb-6">
              <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
                <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ match_title }}</h2>
              </div>
              <div class="p-3">
                {{ match_links|raw }}
              </div>
            </div>
          {% endif %}

          {% if mentorships != "" %}
            <div class="border border-secondary border-md-teal my-3 mb-6">
              <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
                <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ mentorship_title }}</h2>
              </div>
              <div class="p-3">
                {{ mentorships|raw }}
              </div>
            </div>
          {% endif %}

          {% if projects != "na" %}
            <div class="border border-secondary border-md-teal my-3 mb-6">
              <div class="text-white py-2 px-3 bg-dark bg-md-teal text-2xl p-4 d-flex flex align-items-center justify-content-between">
                <h2 class="h4 text-lg font-bold leading-5 m-0 text-white">{{ project_title }}</h2>
              </div>
              <div class="p-3">
                {{ projects|raw }}
              </div>
            </div>
          {% endif %}
          ',
        '#context' => [
          'bio_title' => t('Bio'),
          'bio_summary' => $bio_summary,
          'bio' => $bio,
          'ag_title' => t('Affinity Groups'),
          'user_affinity_groups' => $user_affinity_groups,
          'mi_title' => t('Interests'),
          'my_interests' => $my_interests,
          'me_title' => t('Skills'),
          'my_skills' => $my_skills,
          'ws_title' => t('Knowledge Base Contributions'),
          'ws_links' => $ws_link,
          'match_title' => t('MATCH Engagements'),
          'match_links' => $match_link,
          'mentorship_title' => t('Mentorships'),
          'mentorships' => $mentorships,
          'project_title' => t('Projects'),
          'projects' => $projects,
        ],
        '#cache' => [
          'tags' => ['community_persona'],
        ],
      ];
      return $persona_page;
    }
    else {
      return [
        '#type' => 'markup',
        '#title' => 'User not found',
        '#cache' => [
          'tags' => ['community_persona'],
        ],
        '#markup' => t('No public profile available for this person.'),
      ];
    }
  }

  /**
   * Callback for setting the route title.
   *
   * Set the route title to the user's name if it is a public persona page.
   *
   * @return string
   *   Title to use for the route.
   */
  public function titleCallback() {
    // It's a public persona page if the url has the uid at the end.
    $end_url = new EndUrl();
    $user_id = $end_url->getUrlEnd();
    if (is_numeric($user_id)) {
      // Load the user using the user id.
      $user = User::load($user_id);
      if ($user !== NULL) {
        $user_first_name = $user->get('field_user_first_name')->value;
        $user_last_name = $user->get('field_user_last_name')->value;
        return "$user_first_name $user_last_name";
      }
    }
  }

}
