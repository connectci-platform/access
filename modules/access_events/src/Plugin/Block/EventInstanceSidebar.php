<?php

namespace Drupal\access_events\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Provides a sidebar on Event Instances.
 *
 * @Block(
 *   id = "event_instance_sidebar",
 *   admin_label = "Event Instance Sidebar",
 * )
 */
class EventInstanceSidebar extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $current_path = Xss::filter(\Drupal::service('path.current')->getPath());
    $url = explode('/', $current_path);
    $event_instance_id = is_numeric($url[2]) ? $url[2] : '';
    $event_instance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($event_instance_id);
    $series = $event_instance->getEventSeries();
    $author = $series->getOwner();
    $current_user = \Drupal::currentUser();

    $speakers = $series->get('field_event_speakers')->getValue();

    $contact = $series->get('field_contact')->getValue();
    // If $contact is an email address, create a mailto link.
    foreach ($contact as $key => $value) {
      if (filter_var($value['value'], FILTER_VALIDATE_EMAIL)) {
        $contact[$key]['value'] = Link::fromTextAndUrl($value['value'], Url::fromUri('mailto:' . $value['value']))->toString();
      }
    }
    // If $contact is a url, create a link.
    foreach ($contact as $key => $value) {
      if (filter_var($value['value'], FILTER_VALIDATE_URL)) {
        $contact[$key]['value'] = Link::fromTextAndUrl($value['value'], Url::fromUri($value['value']))->toString();
      }
    }

    $skill_level = $series->get('field_skill_level')->getValue();

    $event_registration = $series->get('event_registration')->getValue();
    $event_registration_on = $event_registration[0]['registration'];

    $registrations_button = NULL;

    if (($author->id() == $current_user->id() || $current_user->hasPermission('administer site configuration')) && $event_registration_on == 1) {
      $registrations_button = "<a href='$current_path/registrations' class='btn btn-primary mb-4'>Registrations</a>";
    }

    $reg_link = NULL;
    $my_registration_status = 0;
    $reg_title = $this->t('Register');

    if ($event_registration_on) {
      // Query registered users.
      $query = \Drupal::database()->select('registrant', 'r');
      $query->fields('r', ['user_id', 'status', 'waitlist']);
      $query->condition('r.eventinstance_id', $event_instance_id);
      $query->condition('r.user_id', $current_user->id());
      $registrants = $query->execute()->fetchAll();

      if ($registrants) {
        $status = $registrants[0]->status;
        $waitlist = $registrants[0]->waitlist;
        $my_registration_status = [
          'status' => $status,
          'waitlist' => $waitlist,
        ];
      } else {
        $reg_link = Link::fromTextAndUrl($reg_title, Url::fromUri("internal:/events/$event_instance_id/registrations/add"));
        $reg_link = $reg_link->toRenderable();
        $reg_link['#options']['query']['destination'] = "/events/$event_instance_id";
        $reg_link['#options']['attributes']['class'][] = 'btn';
        $reg_link['#options']['attributes']['class'][] = 'btn-primary';
        $reg_link['#options']['attributes']['class'][] = 'text-xl';
      }
    }
    else{
      $registration = $series->get('field_registration')->getValue();
      if ($registration) {
        $reg_link = Link::fromTextAndUrl($reg_title, Url::fromUri($registration[0]['uri']));
        $reg_link = $reg_link->toRenderable();
        $reg_link['#options']['attributes']['class'][] = 'btn';
        $reg_link['#options']['attributes']['class'][] = 'btn-primary';
        $reg_link['#options']['attributes']['class'][] = 'text-xl';
      }
    }

    $skill_list = [];
    foreach ($skill_level as $skill) {
      $skill_list[] = $skill['value'];
    }

    $skill_image = \Drupal::service('access_misc.skillLevel')->getSkillsImage($skill_list);

    $event_type = $series->get('field_event_type')->getValue();
    $event_affiliation_id = $series->get('field_affiliation')->getValue();
    $event_affiliation_list = $series->getFieldDefinition('field_affiliation')->getFieldStorageDefinition()->getSetting('allowed_values');

    $event_affiliation = '';
    foreach ($event_affiliation_id as $affiliation) {
      $event_affiliation .= $event_affiliation_list[$affiliation['value']] . '<br />' ?? '';
    }

    $affinity_group = $series->get('field_affinity_group_node')->getValue();

    $affinity_groups = [];
    foreach ($affinity_group as $ag) {
      // Load enitity node by id via $ag.
      $id = $ag['target_id'];
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
      $title = $node->getTitle();
      // Create link to node with title.
      $ag_url = Url::fromRoute('entity.node.canonical', array('node' => $id));
      $ag_link = Link::fromTextAndUrl($title, $ag_url);
      $affinity_groups[] = $ag_link->toRenderable();
    }

    $ical_link = Link::fromTextAndUrl('Download as iCal', Url::fromUri("internal:/events/$event_instance_id/ical"));

    $sidebar['string'] = [
      '#type' => 'inline_template',
      '#template' => '
        {% if registrations_button %}
          <div class="field__items">
            <div class="field__item">{{ registrations_button|raw }}</div>
          </div>
        {% endif %}

        {% if reg_link %}
          <div class="field__items">
            <div class="field__item">{{ reg_link }}</div>
          </div>
        {% endif %}

        {% if my_registration_status %}
          <h3 class="field__label">{{ my_registration_status_title }}</h3>
          <div class="field__items">
            <strong>Approved: </strong>
            {% if my_registration_status.status %}
              <span>Yes</span>
            {% else %}
              <span>No</span>
            {% endif %}
          </div>

          {% if my_registration_status.waitlist %}
            <div class="field__items">
              <strong>Waitlist: </strong>
                <span>Yes</span>
            </div>
          {% endif %}

        {% endif %}

        {% if speakers %}
          <h3 class="field__label">{{ speakers_title }}</h3>
          <div class="field__items">
            {% for speaker in speakers %}
              <div class="field__item">{{ speaker.value }}</div>
            {% endfor %}
          </div>
        {% endif %}

        {% if contacts %}
          <h3 class="field__label">{{ contact_title }}</h3>
          <div class="field__items">
            {% for contact in contacts %}
              <div class="field__item">
                {{ contact.value }}
              </div>
            {% endfor %}
          </div>
        {% endif %}

        {% if skill_image %}
          <h3 class="field__label">{{ skill_level_title }}</h3>
          <div class="field__items">
            <div class="field__item">{{ skill_image | raw }}</div>
          </div>
        {% endif %}

        {% if event_type %}
          <h3 class="field__label">{{ event_type_title }}</h3>
           <div class="field__items">
            {% for type in event_type %}
              <div class="field__item">
                {{ type.value }}
              </div>
            {% endfor %}
          </div>
        {% endif %}

        {% if affinity_groups %}
          <h3 class="field__label">{{ affinity_group_title }}</h3>
           <div class="field__items">
            {{ affinity_groups }}
          </div>
        {% endif %}

        {% if event_affiliation %}
          <h3 class="field__label">{{ event_affiliation_title }}</h3>
           <div class="field__items">
              <div class="field__item">
                {{ event_affiliation|raw }}
              </div>
          </div>
        {% endif %}

        <div class="field__items my-6">
          <div class="field__item">{{ ical_link }}</div>
        </div>',
      '#context' => [
        'registrations_button' => $registrations_button,
        'reg_link' => $reg_link,
        'my_registration_status_title' => t('My Registration Status'),
        'my_registration_status' => $my_registration_status,
        'speakers_title' => t('Speakers'),
        'speakers' => $speakers,
        'contact_title' => t('Contact'),
        'contacts' => $contact,
        'skill_level_title' => t('Skill Level'),
        'skill_image' => $skill_image,
        'event_type_title' => t('Event Type'),
        'event_type' => $event_type,
        'affinity_group_title' => t('Affinity Group'),
        'affinity_groups' => $affinity_groups,
        'event_affiliation_title' => t('Event Affiliation'),
        'event_affiliation' => $event_affiliation,
        'ical_link' => $ical_link->toRenderable(),
      ],
    ];

    return $sidebar;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if ($eid = \Drupal::routeMatch()->getParameter('eventinstance')) {
      return Cache::mergeTags(parent::getCacheTags(), ['eventinstance:' . $eid->id()]);
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

}
