<?php

namespace Drupal\access_cilink\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Match.
 */
class CiLinkController extends ControllerBase {

  /**
   * Variable for title.
   */
  protected $title;

  /**
   * Number for webform submission id.
   */
  protected $sid;

  /**
   * Object with webform submission.
   */
  protected $webform_submission;

  /**
   *
   */
  public function __construct() {
    $url = \Drupal::request()->getRequestUri();
    $url_chunked = explode('/', $url);
    if (is_numeric(end($url_chunked))) {
      $this->sid = end($url_chunked);
    }

    if ($this->sid) {
      $this->webform_submission = \Drupal::entityTypeManager()->getStorage('webform_submission')->load($this->sid);
    }
    else {
      $this->webform_submission = 0;
    }

    if ($this->webform_submission) {
      $title = $this->webform_submission->getData('title');
      $this->title = $title['title'];
    }
    else {
      $this->title = 'KB Resource';
    }
  }

  /**
   * Build content to display on page.
   */
  public function cilinks() {
    if (!$this->webform_submission) {
      return [
        '#markup' => $this->t('No KB Resource found.'),
      ];
    }
    $data = $this->webform_submission->getData();

    // Get domain.
    $domain = \Drupal::config('domain.settings');
    $token = \Drupal::token();
    $domainName = Html::getClass($token->replace(t('[domain:name]')));

    // Get tags.
    $terms = explode(',', $data['terms']);
    $ci_tagged = '';
    foreach ($terms as $term) {
      // Remove leading space.
      $term = $term ? Xss::filter(ltrim($term)) : '';
      $tagged = explode(' ', $term);
      $tag_link = Link::fromTextAndUrl($tagged[0], Url::fromUri('internal:/tags/' . $tagged[0]))->toString();
      $ci_tagged .= $tag_link->__toString() . ' ';
    }

    // Get links to resource.
    $link_data = '';
    foreach ($data['link_to_resource'] as $link) {
      $title = $link['title'] ? Xss::filter($link['title']) : '';
      $link = $link['url'] ? Xss::filter($link['url']) : '';
      if ($title && empty($link)) {
        $link = $title;
      }
      $options = [
        'attributes' => ['class' => ['text-dark-teal', 'text-lg', 'no-underline', 'hover--underline', 'font-semibold']],
      ];
      $external_link = Link::fromTextAndUrl($title, Url::fromUri($link, $options))->toString();
      $link_data .= '<li class="not-prose list-image-link">' . $external_link->__toString() . '</li>';
    }

    // Skill Level.
    $skill_level = $data['skill_level'];
    $skill_graph = '';
    if (is_array($skill_level)) {
      if (in_array(304, $skill_level) && in_array(305, $skill_level) && !in_array(306, $skill_level)) {
        $skill_graph = '<img src="/themes/contrib/asp-theme/images/icons/SL-beginner-medium.png" alt="Beginner and Intermediate"/>';
      }
      elseif (!in_array(304, $skill_level) && in_array(305, $skill_level) && in_array(306, $skill_level)) {
        $skill_graph = '<img src="/themes/contrib/asp-theme/images/icons/SL-medium-advanced.png" alt="Intermediate and Advanced"/>';
      }
      elseif (in_array(304, $skill_level) && in_array(305, $skill_level) && in_array(306, $skill_level)) {
        $skill_graph = '<img src="/themes/contrib/asp-theme/images/icons/SL-all.png" alt="Beginner, Intermediate, and Advanced"/>';
      }
      elseif (in_array(304, $skill_level)) {
        $skill_graph = '<img src="/themes/contrib/asp-theme/images/icons/SL-beginner.png" alt="Beginner"/>';
      }
      elseif (in_array(305, $skill_level)) {
        $skill_graph = '<img src="/themes/contrib/asp-theme/images/icons/SL-medium.png" alt="Intermediate"/>';
      }
      elseif (in_array(306, $skill_level)) {
        $skill_graph = '<img src="/themes/contrib/asp-theme/images/icons/SL-advanced.png" alt="Advanced"/>';
      }
    }

    // Affinity groups.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'affinity_group')
      ->condition('field_resources_entity_reference', $this->sid)
      ->accessCheck(TRUE);
    $nids = $query->execute();
    $affinity_nodes = '';
    if ($nids) {
      $affinity_nodes = '<h3>Affinity Groups</h3>';
    }
    foreach ($nids as $nid) {
      // Get node title.
      $node = Node::load($nid);
      $title = $node->getTitle();
      $affinity_nodes .= '<a href="/node/' . $nid . '">' . $title . '</a>, ';
    }
    $affinity_nodes = rtrim($affinity_nodes, ', ');

    // Check if user is logged in.
    $user = \Drupal::currentUser();
    $options = [
      'query' => ['destination' => \Drupal::request()->getRequestUri()],
      'attributes' => ['class' => ['text-dark-teal', 'no-underline', 'hover--underline']],
    ];
    $login = Link::fromTextAndUrl($this->t('Login to vote'), Url::fromUri('internal:/user/login', $options))->toString();

    // Upvote widget.
    $flag_upvote = \Drupal::service('flag.link_builder')->build('webform_submission', $this->sid, 'upvote', 'full');
    $flag_upvote = \Drupal::service('renderer')->renderPlain($flag_upvote);
    $flag_upvote_count = \Drupal::service('flag.count')->getEntityFlagCounts($this->webform_submission);
    $flag_upvote_set = $flag_upvote_count['upvote'] ?? 0;
    $flag_upvote_count = $flag_upvote_set ? $flag_upvote_count['upvote'] : 0;

    // Edit Button.
    // Get the webform ID from the submission to properly build the edit route
    $webform_id = $this->webform_submission->getWebform()->id();
    $edit_url = Url::fromRoute('entity.webform_submission.edit_form', [
      'webform' => $webform_id,
      'webform_submission' => $this->sid
    ]);
    // Set the options on the URL object before creating the link
    $edit_url->setOption('attributes', [
      'class' => [
        'btn',
        'btn-sm',
        'btn-secondary-outline',
        'py-0',
        'px-4',
        'w-full',
        'btn-md-teal',
      ],
    ]);
    $edit_link = Link::fromTextAndUrl($this->t('Edit'), $edit_url)->toString();

    // Flags.
    $flag_classes = 'no-underline text-dark-teal hover--underline';
    $flag_outdated = \Drupal::service('flag.link_builder')->build('webform_submission', $this->sid, 'outdated', 'full');
    $flag_outdated['#attributes']['class'][] = $flag_classes;
    $flag_outdated = \Drupal::service('renderer')->renderPlain($flag_outdated);
    $flag_not_useful = \Drupal::service('flag.link_builder')->build('webform_submission', $this->sid, 'not_useful', 'full');
    $flag_not_useful['#attributes']['class'][] = $flag_classes;
    $flag_not_useful = \Drupal::service('renderer')->renderPlain($flag_not_useful);
    $flag_inaccurate = \Drupal::service('flag.link_builder')->build('webform_submission', $this->sid, 'inaccurate', 'full');
    $flag_inaccurate['#attributes']['class'][] = $flag_classes;
    $flag_inaccurate = \Drupal::service('renderer')->renderPlain($flag_inaccurate);

    // Check if the user is logged in.
    $user = \Drupal::currentUser();
    $authenticated = $user->isAuthenticated();

    // Use TailwindCSS classes for ACCESS Support
    // or use Bootstrap classes for other sites.
    $template = '';
    if ($domainName == 'access-support') {
      $template = '
        <div class="grid grid-cols-1 md--grid-cols-4 md--grid-cols-2 gap-5 mb-10">
          <div class="col-1 col-span-3 row-span-2">
            <div class="my-2 [&_a]--inline-block [&>*]--me-2 [&>*]--mb-2 [&>*]--border [&>*]--border-solid [&>*]--border-black [&>*]--px-2 [&>*]--py-1 [&_a]--font-normal [&>*]--no-underline hover--[&>*]--border-dark-teal">
              {{ tags | raw }}
            </div>
            <ul class="list-none ps-0">
              {{ links | raw }}
            </ul>
            <p>{{ description }}</p>
          </div>
          <div>
            <div class="text-dark-teal bg-light-teal p-5 mb-5 not-prose">
              <div class="flex items-center">
                <div class="text-[32px] text-center w-9 me-2">{{ count }}</div>
                <span>{{ (count|render == "1") ? "Person" : "People" }} found this useful</span>
              </div>
              {{ flag_upvote }}
              {% if user is same as(0) %}
                <div class="flex">
                  <i class="fa-light fa-right-to-bracket text-[32px] w-9 me-2"></i>
                  {{ user_login | raw }}
                </div>
              {% endif %}
              </div>
              <div class="grid grid-cols-2 gap-5">
                <div>
                    <h3>Category</h3>
                    {{ category }}
                </div>
                <div>
                  <h3>Skill Level</h3>
                  <div class="not-prose">{{ skill_graph | raw }}</div>
                </div>
              </div>
              {% if affinity_groups %}
                {{ affinity_groups | raw }}
              {% endif %}
              {% if authenticated %}
                <div class="mt-5">
                  {{ edit_link | raw }}
                </div>
              {% endif %}
              {% if user > 0 %}
                <details class="border border-solid border-dark-teal open:bg-white duration-300 relative">
                  <summary class="ps-5 pe-10 py-2 text-dark-teal leading-5 cursor-pointer uppercase">{{ report }}</summary>
                  <div class="bg-white px-5">
                    <ul class="dropdown-menu">
                      <li>{{ outdated | raw }}</li>
                      <li>{{ not_useful | raw }}</li>
                      <li>{{ inaccurate | raw }}</li>
                    </ul>
                  </div>
                </details>
              {% endif %}
            </div>
          </div>
        </div>
      ';
    }
    else {
      $template = '
        <div class="container mb-5">
          <div class="row">
            <div class="col-12 col-md-8">
              <div class="square-tags my-2">
                {{ tags | raw }}
              </div>
              <ul class="list-group list-unstyled py-3">
                {{ links | raw }}
              </ul>
              <p>{{ description }}</p>
            </div>
            <div class="col-12 col-md-4">
              <div class="text-dark bg-light p-3 mb-5">
                <div class="d-flex align-items-center">
                  <div class="fs-1 ml-2 ms-2 mr-2 me-2 px30">{{ count }}</div> <span>{{ (count|render == "1") ? "Person" : "People" }} found this useful</span>
                </div>
                <span class="vote-for-ci text-decoration-none ">{{ flag_upvote }}</span>
                {% if user is same as(0) %}
                  <div class="d-flex">
                    <div class="px46"><i class="fa-light fa-right-to-bracket ms-2 ml-2"></i></div> {{ user_login | raw }}
                  </div>
                {% endif %}
              </div>
              <div class="d-flex justify-content-between">
                <div class="mb-3">
                   <h4>Category</h4>
                   {{ category }}
                </div>
                 <div class="mb-3">
                  <h4>Skill Level</h4>
                  {{ skill_graph | raw }}
                </div>
              </div>
              <div class="mt-3">
                {{ affinity_groups | raw }}
              </div>
              {% if authenticated %}
                <div class="mt-5">
                  {{ edit_link | raw }}
                </div>
              {% endif %}
              {% if user > 0 %}
                <div class="dropdown mt-3">
                  <a class="btn btn-outline-dark btn-outline-primary btn-sm dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    {{ report }}
                  </a>
                  <ul class="dropdown-menu">
                    <li>{{ outdated | raw }}</li>
                    <li>{{ not_useful | raw }}</li>
                    <li>{{ inaccurate | raw }}</li>
                  </ul>
                </div>
              {% endif %}
            </div>
          </div>
        </div>
      ';
    }

    $description = $data['description'] ? Xss::filter($data['description']) : '';
    $category = $data['category'] ? Xss::filter($data['category']) : '';

    $cilink_page['string'] = [
      '#type' => 'inline_template',
      '#attached' => [
        'library' => [
          'access_cilink/cilink_view',
          'access_misc/copyclip',
        ],
      ],
      '#template' => $template,
      '#context' => [
        'tags' => $ci_tagged,
        'description' => $description,
        'links' => $link_data,
        'flag_upvote' => $flag_upvote,
        'count' => $flag_upvote_count,
        'user_login' => $login,
        'category' => $category,
        'skill_graph' => $skill_graph,
        'affinity_groups' => $affinity_nodes,
        'user' => $user->id(),
        'report' => $this->t('Flag this resource'),
        'outdated' => $flag_outdated,
        'not_useful' => $flag_not_useful,
        'inaccurate' => $flag_inaccurate,
        'authenticated' => $authenticated,
        'edit_link' => $edit_link,
      ],
      // Add cache tags to invalidate cache when the webform submission changes.
      '#cache' => [
        'tags' => ['webform_submission:' . $this->sid],
      ],
    ];
    return $cilink_page;
  }

  /**
   * Redirect to knowledge-base page.
   */
  public function redirectToResource() {
    $response = new RedirectResponse('/knowledge-base/resources/' . $this->sid);
    return $response->send();
  }

  /**
   * Redirect to cilinks page.
   */
  public function cilink() {
    $response = new RedirectResponse('/ci-links/' . $this->sid);
    return $response->send();
  }

  /**
   * Display title.
   */
  public function getTitle() {
    return $this->title;
  }

}
