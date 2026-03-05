<?php

namespace Drupal\access_misc\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Render\Renderer;
use Drupal\Component\Utility\Xss;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\Core\File\FileExists;

/**
 * Filter users by flagged skill or interest.
 *
 * @ingroup filter_people_by_tags
 */
class FilterPeopleByTags extends ConfigFormBase {

  /**
   * Store CSV data.
   *
   * @var string
   */
  private $csv;

  /**
   * Variable for the form state.
   *
   * @var array
   */
  private $formState;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Use messenger interface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messengerInterface;

  /**
   * Invoke renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $render;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'filter_people_by_tags_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [
      'filter.people.by.tags.settings',
    ];
  }

  /**
   * Constructs request stuff.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Implement messenger service.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   Invokes renderer.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Invokes entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    MessengerInterface $messenger,
    Renderer $renderer,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
  ) {
    $this->messengerInterface = $messenger;
    $this->render = $renderer;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('messenger'),
      $container->get('renderer'),
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $description['string'] = [
      '#type' => 'inline_template',
      '#template' => '<p>{{ description }}</p>',
      '#context' => [
        'description' => $this->t('This form allows you to search for people by their tags.  You can select multiple tags to search for.  If you select multiple tags, the results will be people who have all of the tags you selected.'),
      ],
    ];

    $form['people_tags_description'] = [
      '#markup' => $this->render->render($description),
    ];

    $term_lookup = $this->database->select('flagging', 'f');
    $term_lookup->fields('f', ['entity_id']);
    $term_lookup->condition('f.flag_id', ['skill', 'interest'], 'IN');
    $term_lookup->distinct();
    $flagged_terms = $term_lookup->execute()->fetchAll();

    $options[0] = '- None -';
    $options[1] = '-- Any --';
    foreach ($flagged_terms as $flagged_term) {
      $tid = $flagged_term->entity_id;
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      $options[$tid] = $term->label();
    }
    asort($options);

    $current_state = $form_state->get('current_state');

    // Get all available roles.
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $role_options[1] = '-- Any --';
    foreach ($roles as $role) {
      $role_options[$role->id()] = $role->label();
    }
    asort($role_options);

    $form['roles'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Roles'),
      '#options' => $role_options,
      '#description' => $this->t('Filter users by role'),
      '#weight' => -1,
    ];

    if ($current_state === NULL) {
      $this->formState = [
        'show_people' => 0,
        'first_tags' => 1,
        'second_tags' => 1,
        'third_tags' => 1,
        'fourth_tags' => 1,
        'fifth_tags' => 1,
      ];
      $form_state->set('current_state', $this->formState);
      $current_state = $form_state->get('current_state');
    }
    $submitted = $current_state['show_people'];

    $form['#tree'] = TRUE;

    $first_tags = $this->addTags($form, $form_state, $options, 'first_tags', 0);

    $form['seperator_or'] = [
      '#markup' => $this->t('OR'),
      '#weight' => 1,
    ];

    $second_tags = $this->addTags($form, $form_state, $options, 'second_tags', 2);

    $form['seperator_or_second'] = [
      '#markup' => $this->t('OR'),
      '#weight' => 3,
    ];

    $third_tags = $this->addTags($form, $form_state, $options, 'third_tags', 4);

    $form['seperator_or_third'] = [
      '#markup' => $this->t('OR'),
      '#weight' => 5,
    ];

    $fourth_tags = $this->addTags($form, $form_state, $options, 'fourth_tags', 6);

    $form['seperator_or_fourth'] = [
      '#markup' => $this->t('OR'),
      '#weight' => 7,
    ];

    $fifth_tags = $this->addTags($form, $form_state, $options, 'fifth_tags', 8);

    $form['submit'] = [
      '#type' => 'submit',
      '#arg' => 'filter',
      '#value' => $this->t('Filter'),
      '#weight' => 9,
    ];

    $form['csv'] = [
      '#type' => 'submit',
      '#arg' => 'csv',
      '#value' => $this->t('Export CSV'),
      '#weight' => 10,
    ];

    if ($submitted) {
      $this->createTable($form, $form_state, $first_tags, $second_tags, $third_tags, $fourth_tags, $fifth_tags);
    }

    // $this->messengerInterface->addMessage($this->render->render($dev_message));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  private function checkTags($filter_group, $entity_ids) {
    $result = FALSE;
    foreach ($filter_group as $filter_item) {
      if ($filter_item === '1') {
        $result = TRUE;
      }
      elseif (in_array($filter_item, array_column($entity_ids, 'entity_id'))) {
        $result = TRUE;
      }
      else {
        $result = FALSE;
        break;
      }
      return $result;
    }

  }

  /**
   * Function to add another tags dropdown section.
   */
  private function addTags(array &$form, $form_state, $options, $section, $weight) {
    $this->formState = $form_state->get('current_state');

    // Gather the number of names in the form already.
    $tags = $this->formState[$section];

    // We have to ensure that there is at least one name field.
    if ($tags === NULL) {
      $this->formState[$section] = 1;
      $form_state->set('current_state', $this->formState);
      $tags = 1;
    }

    $form[$section] = [
      '#type' => 'fieldset',
      '#title' => $this
        ->t('Each of the following tags are an AND inside this group.'),
      '#weight' => $weight,
      '#prefix' => "<div id='tags-fieldset-wrapper-$section'>",
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $tags; $i++) {
      $form[$section]['tag'][$i] = [
        '#type' => 'select',
        '#title' => $this->t('Tags'),
        '#options' => $options,
        '#description' => $this->t('Filter by tags'),
      ];
    }
    $form[$section]['actions'] = [
      '#type' => 'actions',
    ];
    $title_section = preg_replace('/_/', ' ', $section);
    $form[$section]['actions']['add_tag'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add one more in') . ' ' . $title_section,
      '#arg' => $section,
      '#submit' => [
        '::addOne',
      ],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'tags-fieldset-wrapper-' . $section,
      ],
    ];

    // If there is more than one name, add the remove button.
    if ($tags > 1) {
      $form[$section]['actions']['remove_tag'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove one in') . ' ' . $title_section,
        '#arg' => $section,
        '#submit' => [
          '::removeCallback',
        ],
        '#ajax' => [
          'callback' => '::addmoreCallback',
          'wrapper' => 'tags-fieldset-wrapper-' . $section,
        ],
      ];
    }
    return $tags;
  }

  /**
   * Create a table of people.
   */
  private function createTable(array &$form, FormStateInterface $form_state, $first_tags, $second_tags, $third_tags, $fourth_tags, $fifth_tags) {
    $selected_role = $form_state->getValue('roles') ? Xss::filter($form_state->getValue('roles')) : '';
    $first_filter_group = [];
    for ($i = 0; $i < $first_tags; $i++) {
      $first_filter_group[] = $form_state->getValue('first_tags', 'tag', 0)['tag'][$i] ? Xss::filter($form_state->getValue('first_tags', 'tag', 0)['tag'][$i]) : '';
    }
    $second_filter_group = [];
    for ($i = 0; $i < $second_tags; $i++) {
      $second_filter_group[] = $form_state->getValue('second_tags', 'tag', 0)['tag'][$i] ? Xss::filter($form_state->getValue('second_tags', 'tag', 0)['tag'][$i]) : '';
    }
    $third_filter_group = [];
    for ($i = 0; $i < $third_tags; $i++) {
      $third_filter_group[] = $form_state->getValue('third_tags', 'tag', 0)['tag'][$i] ? Xss::filter($form_state->getValue('third_tags', 'tag', 0)['tag'][$i]) : '';
    }
    $fourth_filter_group = [];
    for ($i = 0; $i < $fourth_tags; $i++) {
      $fourth_filter_group[] = $form_state->getValue('fourth_tags', 'tag', 0)['tag'][$i] ? Xss::filter($form_state->getValue('fourth_tags', 'tag', 0)['tag'][$i]) : '';
    }
    $fifth_filter_group = [];
    for ($i = 0; $i < $fifth_tags; $i++) {
      $fifth_filter_group[] = $form_state->getValue('fifth_tags', 'tag', 0)['tag'][$i] ? Xss::filter($form_state->getValue('fifth_tags', 'tag', 0)['tag'][$i]) : '';
    }
    $header = [
      'Name',
      'Institution',
      'Email',
      'Roles',
      'Tags',
    ];
    $csv_header = implode(',', $header);
    $rows = [];

    // Query the flagging table for all unique uid's.
    $query = $this->database->select('flagging', 'f');
    $query->fields('f', ['uid']);
    $query->distinct();
    $query->orderBy('f.uid', 'ASC');
    $uids = $query->execute()->fetchAll();
    $csv_rows = '';
    foreach ($uids as $uid) {
      $user = $this->entityTypeManager->getStorage('user')->load($uid->uid);
      $institution = $user->get('field_institution')->getValue();
      $first_name = $user->get('field_user_first_name')->getValue();
      $last_name = $user->get('field_user_last_name')->getValue();
      $email = $user->getEmail();
      $roles = $user->getRoles();
      if (in_array($selected_role, $roles) || $selected_role == 1) {
        // Query the flagging table for entity_id for this uid.
        $query = $this->database->select('flagging', 'f');
        $query->fields('f', ['entity_id']);
        $query->condition('f.uid', $uid->uid);
        $query->orderBy('f.entity_id', 'ASC');
        $entity_ids = $query->execute()->fetchAll();
        $tags = '';
        $first = $this->checkTags($first_filter_group, $entity_ids);
        $second = $this->checkTags($second_filter_group, $entity_ids);
        $third = $this->checkTags($third_filter_group, $entity_ids);
        $fourth = $this->checkTags($fourth_filter_group, $entity_ids);
        $fifth = $this->checkTags($fifth_filter_group, $entity_ids);
        if ($first || $second || $third || $fourth || $fifth) {
          foreach ($entity_ids as $entity_id) {
            $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($entity_id->entity_id);
            $tags .= $term !== NULL ? $term->label() . ", " : '';
          }
          $tags = rtrim($tags, ', ');
          $fname = isset($first_name[0]) && $first_name[0] !== NULL ? $first_name[0]['value'] : '';
          $lname = isset($last_name[0]) && $last_name[0] !== NULL ? $last_name[0]['value'] : '';
          $inst = isset($institution[0]) && $institution[0] !== NULL ? $institution[0]['value'] : '';
          $csv_rows .= "\"$fname $lname\",\"$inst\",\"$email\",\"" . implode(', ', $roles) . "\",\"$tags\" \n";
          $rows[] = [
            'Name' => [
              'data' => [
                '#markup' => "<a href='/community-profile/" . $uid->uid . "'>" . $fname . " " . $lname . "</a>",
              ],
            ],
            'Institution' => [
              'data' => [
                '#markup' => $inst,
              ],
            ],
            'Email' => [
              'data' => [
                '#markup' => $email,
              ],
            ],
            'Roles' => [
              'data' => [
                '#markup' => implode(', ', $roles),
              ],
            ],
            'Tags' => [
              'data' => [
                '#markup' => $tags,
              ],
            ],
          ];
        }
      }
    }
    $html['results'] = [
      '#theme' => 'table',
      '#sticky' => TRUE,
      '#header' => $header,
      '#rows' => $rows,
    ];

    $form['people_results'] = [
      '#markup' => $this->render->render($html),
      '#weight' => 100,
    ];

    $this->csv = $csv_header . "\n" . $csv_rows;
    $this->messengerInterface->addMessage('Users Filtered');
  }

  /**
   * Callback for both ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the names in it.
   */
  public function addmoreCallback(array &$form, FormStateInterface $form_state) {
    $section = $form_state->getTriggeringElement()["#arg"];
    return $form[$section];
  }

  /**
   * Submit handler for the "add-one-more" button.
   *
   * Increments the max counter and causes a rebuild.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $section = $form_state->getTriggeringElement()["#arg"];
    $this->formState = $form_state->get('current_state');
    $this->formState['show_people'] = 0;
    $tags = $this->formState[$section];
    $add_button = $tags + 1;
    $this->formState[$section] = $add_button;
    $form_state->set('current_state', $this->formState);

    // Since our buildForm() method relies on the value of 'num_tags' to
    // generate 'name' form elements, we have to tell the form to rebuild. If we
    // don't do this, the form builder will not call buildForm().
    $form_state
      ->setRebuild();
  }

  /**
   * Submit handler for the "remove one" button.
   *
   * Decrements the max counter and causes a form rebuild.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $section = $form_state->getTriggeringElement()["#arg"];
    $this->formState = $form_state->get('current_state');
    $this->formState['show_people'] = 0;
    $tags = $this->formState[$section];
    if ($tags > 1) {
      $remove_button = $tags - 1;
      $this->formState[$section] = $remove_button;
      $form_state->set('current_state', $this->formState);
    }

    // Since our buildForm() method relies on the value of 'num_tags' to
    // generate 'name' form elements, we have to tell the form to rebuild. If we
    // don't do this, the form builder will not call buildForm().
    $form_state
      ->setRebuild();
  }

  /**
   * Implements form validation.
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $type = $form_state->getTriggeringElement()["#arg"];
    if ($type === 'filter') {
      $this->formState = $form_state->get('current_state');
      $this->formState['show_people'] = TRUE;
      $form_state->set('current_state', $this->formState);
      $form_state->setRebuild();
    }
    if ($type === 'csv') {
      $this->formState = $form_state->get('current_state');
      $headers = [
        'Content-Type' => 'text/csv',
        'Content-Description' => 'File Download',
        'Content-Disposition' => 'attachment; filename=export.csv',
      ];
      // Create comma separated variable from $data.
      $first_tags = $this->formState['first_tags'];
      $second_tags = $this->formState['second_tags'];
      $third_tags = $this->formState['third_tags'];
      $fourth_tags = $this->formState['fourth_tags'];
      $fifth_tags = $this->formState['fifth_tags'];
      $this->createTable($form, $form_state, $first_tags, $second_tags, $third_tags, $fourth_tags, $fifth_tags);
      $csv = $this->csv;

      \Drupal::service('file_system')->saveData($csv, "/tmp/export.csv", FileExists::Replace); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

      $form_state->setResponse(new BinaryFileResponse('/tmp/export.csv', 200, $headers, TRUE));

    }
  }

}
