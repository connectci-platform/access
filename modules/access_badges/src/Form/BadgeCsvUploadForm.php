<?php

namespace Drupal\access_badges\Form;

use Drupal\access_badges\Service\CsvProcessor;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CSV upload form for bulk badge assignment.
 */
class BadgeCsvUploadForm extends FormBase {

  /**
   * The CSV processor service.
   *
   * @var \Drupal\access_badges\Service\CsvProcessor
   */
  protected $csvProcessor;

  /**
   * Constructs a BadgeCsvUploadForm.
   */
  public function __construct(CsvProcessor $csv_processor) {
    $this->csvProcessor = $csv_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('access_badges.csv_processor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_badges_csv_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Vocabulary'),
      '#options' => [
        'badges' => $this->t('Badges'),
        'open_ondemand_badges' => $this->t('Open OnDemand Badges'),
      ],
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateBadgeOptions',
        'wrapper' => 'badge-term-wrapper',
      ],
    ];

    $vocabulary = $form_state->getValue('vocabulary', 'badges');
    $form['badge_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Badge'),
      '#options' => $this->getBadgeOptions($vocabulary),
      '#required' => TRUE,
      '#prefix' => '<div id="badge-term-wrapper">',
      '#suffix' => '</div>',
      '#validated' => TRUE,
    ];

    $form['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV File'),
      '#description' => $this->t('Upload a .csv file (max 1 MB). Must contain an "email" column header.'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload and Process'),
    ];

    $results = $form_state->get('csv_results');
    if ($results) {
      $form['results'] = $this->buildResultsDisplay($results);
    }

    return $form;
  }

  /**
   * AJAX callback to update badge options.
   */
  public function updateBadgeOptions(array &$form, FormStateInterface $form_state) {
    return $form['badge_tid'];
  }

  /**
   * Gets badge term options for a vocabulary.
   */
  protected function getBadgeOptions($vocabulary) {
    $options = [];
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary]);
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    asort($options);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $files = $this->getRequest()->files->get('files');
    $file = $files['csv_file'] ?? NULL;

    if (!$file || !$file->isValid()) {
      $form_state->setErrorByName('csv_file', $this->t('Please upload a valid CSV file.'));
      return;
    }

    $extension = strtolower($file->getClientOriginalExtension());
    if ($extension !== 'csv') {
      $form_state->setErrorByName('csv_file', $this->t('Only .csv files are allowed.'));
      return;
    }

    if ($file->getSize() > 1048576) {
      $form_state->setErrorByName('csv_file', $this->t('File must be under 1 MB.'));
      return;
    }

    $handle = fopen($file->getRealPath(), 'r');
    if (!$handle) {
      $form_state->setErrorByName('csv_file', $this->t('Could not read the uploaded file.'));
      return;
    }
    $header_map = $this->csvProcessor->parseHeaders($handle);
    fclose($handle);

    if ($header_map === FALSE) {
      $form_state->setErrorByName('csv_file', $this->t('CSV must contain an "email" column header.'));
      return;
    }

    $form_state->set('csv_file_path', $file->getRealPath());
    $form_state->set('csv_header_map', $header_map);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_path = $form_state->get('csv_file_path');
    $header_map = $form_state->get('csv_header_map');
    $badge_tid = $form_state->getValue('badge_tid');
    $vocabulary = $form_state->getValue('vocabulary');

    $handle = fopen($file_path, 'r');
    // Skip header row.
    fgetcsv($handle);
    $row_count = 0;
    while (fgetcsv($handle) !== FALSE) {
      $row_count++;
    }
    fclose($handle);

    if ($row_count > 100) {
      $this->processBatch($file_path, $header_map, $badge_tid, $vocabulary, $form_state);
    }
    else {
      $results = $this->processSync($file_path, $header_map, $badge_tid, $vocabulary);
      $form_state->set('csv_results', $results);
      $form_state->setRebuild();
    }
  }

  /**
   * Processes CSV rows synchronously.
   */
  protected function processSync($file_path, $header_map, $badge_tid, $vocabulary) {
    $results = [
      'assigned' => [],
      'already_assigned' => [],
      'possible_matches' => [],
      'pending' => [],
      'duplicate_pending' => [],
    ];

    $handle = fopen($file_path, 'r');
    // Skip header row.
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
      $data = $this->csvProcessor->extractRowData($row, $header_map);
      if (empty($data['email'])) {
        continue;
      }
      $result = $this->csvProcessor->processRow($data, $badge_tid, $vocabulary);
      $results[$result['type']][] = $result;
    }
    fclose($handle);

    return $results;
  }

  /**
   * Launches batch processing for large CSVs.
   */
  protected function processBatch($file_path, $header_map, $badge_tid, $vocabulary, FormStateInterface $form_state) {
    $temp_path = \Drupal::service('file_system')->getTempDirectory() . '/badge_csv_' . time() . '.csv';
    copy($file_path, $temp_path);

    $batch = [
      'title' => $this->t('Processing CSV...'),
      'operations' => [
        [
          [static::class, 'batchProcess'],
          [$temp_path, $header_map, $badge_tid, $vocabulary],
        ],
      ],
      'finished' => [static::class, 'batchFinished'],
    ];

    batch_set($batch);
  }

  /**
   * Batch operation callback.
   */
  public static function batchProcess($file_path, $header_map, $badge_tid, $vocabulary, &$context) {
    $csv_processor = \Drupal::service('access_badges.csv_processor');

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['file_path'] = $file_path;
      $context['results']['assigned'] = [];
      $context['results']['already_assigned'] = [];
      $context['results']['possible_matches'] = [];
      $context['results']['pending'] = [];
      $context['results']['duplicate_pending'] = [];

      $handle = fopen($file_path, 'r');
      // Skip header row.
      fgetcsv($handle);
      $count = 0;
      while (fgetcsv($handle) !== FALSE) {
        $count++;
      }
      fclose($handle);
      $context['sandbox']['total'] = $count;
    }

    $handle = fopen($file_path, 'r');
    // Skip header row.
    fgetcsv($handle);

    // Skip to current position.
    for ($i = 0; $i < $context['sandbox']['progress']; $i++) {
      fgetcsv($handle);
    }

    // Process up to 25 rows per batch run.
    $processed = 0;
    while ($processed < 25 && ($row = fgetcsv($handle)) !== FALSE) {
      $data = $csv_processor->extractRowData($row, $header_map);
      if (!empty($data['email'])) {
        $result = $csv_processor->processRow($data, $badge_tid, $vocabulary);
        $context['results'][$result['type']][] = $result;
      }
      $context['sandbox']['progress']++;
      $processed++;
    }
    fclose($handle);

    if ($context['sandbox']['total'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      $assigned_count = count($results['assigned'] ?? []);
      $pending_count = count($results['pending'] ?? []);
      $possible_count = count($results['possible_matches'] ?? []);

      \Drupal::messenger()->addStatus(t('@assigned users assigned badges, @possible possible matches found, @pending added to pending.', [
        '@assigned' => $assigned_count,
        '@possible' => $possible_count,
        '@pending' => $pending_count,
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during CSV processing.'));
    }

    if (!empty($results['file_path'])) {
      @unlink($results['file_path']);
    }
  }

  /**
   * Builds the results display render array.
   */
  protected function buildResultsDisplay(array $results) {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-csv-results']],
    ];

    $assigned = $results['assigned'] ?? [];
    if (!empty($assigned)) {
      $items = [];
      foreach ($assigned as $row) {
        $items[] = $this->t('@name (@email)', [
          '@name' => $row['name'],
          '@email' => $row['email'],
        ]);
      }
      $build['assigned'] = [
        '#type' => 'details',
        '#title' => $this->t('@count users assigned', ['@count' => count($assigned)]),
        '#open' => TRUE,
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    $already = $results['already_assigned'] ?? [];
    if (!empty($already)) {
      $items = [];
      foreach ($already as $row) {
        $items[] = $this->t('@name (@email) — already assigned', [
          '@name' => $row['name'],
          '@email' => $row['email'],
        ]);
      }
      $build['already_assigned'] = [
        '#type' => 'details',
        '#title' => $this->t('@count already assigned (skipped)', ['@count' => count($already)]),
        '#open' => FALSE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    $possible = $results['possible_matches'] ?? [];
    if (!empty($possible)) {
      $build['possible'] = [
        '#type' => 'details',
        '#title' => $this->t('@count possible matches', ['@count' => count($possible)]),
        '#open' => TRUE,
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'table' => $this->buildPossibleMatchesTable($possible),
      ];
    }

    $pending = $results['pending'] ?? [];
    if (!empty($pending)) {
      $items = [];
      foreach ($pending as $row) {
        $items[] = $row['email'];
      }
      $build['pending'] = [
        '#type' => 'details',
        '#title' => $this->t('@count added to pending', ['@count' => count($pending)]),
        '#open' => TRUE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    $duplicate = $results['duplicate_pending'] ?? [];
    if (!empty($duplicate)) {
      $items = [];
      foreach ($duplicate as $row) {
        $items[] = $row['email'];
      }
      $build['duplicate_pending'] = [
        '#type' => 'details',
        '#title' => $this->t('@count duplicate pending rows (skipped)', ['@count' => count($duplicate)]),
        '#open' => FALSE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    return $build;
  }

  /**
   * Builds table for possible matches with action buttons.
   */
  protected function buildPossibleMatchesTable(array $possible_matches) {
    $header = [
      $this->t('CSV Email'),
      $this->t('CSV Name'),
      $this->t('Matched User'),
      $this->t('Matched Email'),
      $this->t('Organization'),
      $this->t('Strength'),
      $this->t('Actions'),
    ];

    $badge_tid = $this->getRequest()->get('badge_tid') ?: 0;
    $vocabulary = $this->getRequest()->get('vocabulary') ?: 'badges';

    $rows = [];
    foreach ($possible_matches as $match) {
      foreach ($match['candidates'] as $candidate) {
        $rows[] = [
          $match['email'],
          trim($match['first_name'] . ' ' . $match['last_name']),
          $candidate['name'],
          $candidate['email'],
          $candidate['organization'],
          $candidate['strength'],
          [
            'data' => [
              '#type' => 'container',
              'assign' => [
                '#type' => 'link',
                '#title' => $this->t('Assign'),
                '#url' => Url::fromRoute('access_badges.assign_action', [
                  'uid' => $candidate['uid'],
                  'badge_tid' => $badge_tid,
                  'vocabulary' => $vocabulary,
                  'email' => $match['email'],
                ]),
                '#attributes' => [
                  'class' => ['button', 'button--small'],
                ],
              ],
              'pending' => [
                '#type' => 'link',
                '#title' => $this->t('Send to Pending'),
                '#url' => Url::fromRoute('access_badges.pending_action', [
                  'email' => $match['email'],
                  'first_name' => $match['first_name'],
                  'last_name' => $match['last_name'],
                  'organization' => $match['organization'] ?? '',
                ]),
                '#attributes' => [
                  'class' => ['button', 'button--small'],
                ],
              ],
            ],
          ],
        ];
      }
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No possible matches.'),
    ];
  }

}
