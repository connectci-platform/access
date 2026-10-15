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

    // The like widget, Edit button and flag menu are per-user, so they are lazy
    // builders (see ResourceUserActions) rather than part of this cached page.
    $variant = 'page:' . $domainName;

    // Upvote count.
    $flag_upvote_count = \Drupal::service('flag.count')->getEntityFlagCounts($this->webform_submission);
    $flag_upvote_set = $flag_upvote_count['upvote'] ?? 0;
    $flag_upvote_count = $flag_upvote_set ? $flag_upvote_count['upvote'] : 0;

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
            <div class="prose mb-4">{{ description | raw }}</div>
          </div>
          <div>
            <div class="text-dark-teal bg-light-teal p-5 mb-5 not-prose">
              <div class="flex items-center">
                <div class="text-[32px] text-center w-9 me-2">{{ count }}</div>
                <span>{{ (count|render == "1") ? "Person" : "People" }} found this useful</span>
              </div>
              {{ like_widget }}
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
              {{ user_actions }}
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
              {{ description | raw }}
            </div>
            <div class="col-12 col-md-4">
              <div class="text-dark bg-light p-3 mb-5">
                <div class="d-flex align-items-center">
                  <div class="fs-1 ml-2 ms-2 mr-2 me-2 px30">{{ count }}</div> <span>{{ (count|render == "1") ? "Person" : "People" }} found this useful</span>
                </div>
                <span class="vote-for-ci text-decoration-none ">{{ like_widget }}</span>
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
              {{ user_actions }}
            </div>
          </div>
        </div>
      ';
    }

    $description = '';
    if (isset($data['description_html']['value'])) {
      $description = check_markup($data['description_html']['value'], 'basic_html');
    }
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
        'like_widget' => [
          '#lazy_builder' => [
            'access_cilink.resource_user_actions:likeWidget',
            [(int) $this->sid, $variant],
          ],
          '#create_placeholder' => TRUE,
        ],
        'user_actions' => [
          '#lazy_builder' => [
            'access_cilink.resource_user_actions:userActions',
            [(int) $this->sid, $variant],
          ],
          '#create_placeholder' => TRUE,
        ],
        'count' => $flag_upvote_count,
        'category' => $category,
        'skill_graph' => $skill_graph,
        'affinity_groups' => $affinity_nodes,
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
