<?php

namespace Drupal\access_badges\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Management form for pending badge assignments.
 */
final class BadgePendingForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a BadgePendingForm.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_badges_pending_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Filter by badge.
    $badge_options = $this->getPendingBadgeOptions('pending');
    if (!empty($badge_options)) {
      $form['filter'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['form--inline']],
      ];
      $form['filter']['badge_filter'] = [
        '#type' => 'select',
        '#title' => $this->t('Filter by badge'),
        '#options' => ['' => $this->t('- All -')] + $badge_options,
        '#default_value' => $form_state->getValue('badge_filter', ''),
      ];
      $form['filter']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Filter'),
        '#submit' => ['::filterSubmit'],
      ];
    }

    $badge_filter = $form_state->getValue('badge_filter', '');
    $rows = $this->getPendingRows('pending', $badge_filter);

    $form['table'] = [
      '#type' => 'tableselect',
      '#header' => [
        'email' => $this->t('Email'),
        'first_name' => $this->t('First Name'),
        'last_name' => $this->t('Last Name'),
        'organization' => $this->t('Organization'),
        'badge' => $this->t('Badge'),
        'created' => $this->t('Date Added'),
        'operations' => $this->t('Operations'),
      ],
      '#options' => $rows,
      '#empty' => $this->t('No pending badge assignments.'),
    ];

    if (!empty($rows)) {
      $form['actions']['bulk_delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete Selected'),
        '#submit' => ['::bulkDeleteSubmit'],
      ];
    }

    return $form;
  }

  /**
   * Gets pending rows from the database.
   *
   * @return array<int|string, mixed>
   *   The table rows keyed by row ID.
   */
  protected function getPendingRows(string $status, string $badge_filter = ''): array {
    $query = $this->database->select('access_badges_pending', 'p')
      ->fields('p')
      ->condition('p.status', $status)
      ->orderBy('p.created', 'DESC');

    if (!empty($badge_filter)) {
      $query->condition('p.badge_tid', $badge_filter);
    }

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $row) {
      $badge_name = $this->getBadgeName($row->badge_tid);
      $rows[$row->id] = [
        'email' => $row->email,
        'first_name' => $row->first_name,
        'last_name' => $row->last_name,
        'organization' => $row->organization,
        'badge' => $badge_name,
        'created' => $this->dateFormatter->format($row->created, 'short'),
        'operations' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Delete'),
            '#url' => Url::fromRoute('access_badges.pending_delete', ['id' => $row->id]),
            '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
          ],
        ],
      ];
    }

    return $rows;
  }

  /**
   * Gets badge term options that exist in pending rows.
   *
   * @return array<int|string, mixed>
   *   The badge options keyed by term ID.
   */
  protected function getPendingBadgeOptions(string $status): array {
    $tids = $this->database->select('access_badges_pending', 'p')
      ->fields('p', ['badge_tid'])
      ->condition('p.status', $status)
      ->distinct()
      ->execute()
      ->fetchCol();

    $options = [];
    foreach ($tids as $tid) {
      $options[$tid] = $this->getBadgeName($tid);
    }
    asort($options);
    return $options;
  }

  /**
   * Gets a badge term name by ID.
   *
   * @param int|string $tid
   *   The taxonomy term ID.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The badge term label, or a placeholder if the term does not exist.
   */
  protected function getBadgeName($tid) {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    return $term ? $term->label() : $this->t('Unknown (@tid)', ['@tid' => $tid]);
  }

  /**
   * Filter submit handler.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function filterSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Bulk delete submit handler.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function bulkDeleteSubmit(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter($form_state->getValue('table', []));
    if (empty($selected)) {
      $this->messenger()->addWarning($this->t('No rows selected.'));
      return;
    }

    $this->database->delete('access_badges_pending')
      ->condition('id', array_keys($selected), 'IN')
      ->execute();

    $this->messenger()->addStatus($this->t('Deleted @count pending row(s).', [
      '@count' => count($selected),
    ]));
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Primary submit handled by sub-handlers.
  }

}
