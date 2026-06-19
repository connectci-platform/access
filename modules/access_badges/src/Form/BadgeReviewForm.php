<?php

namespace Drupal\access_badges\Form;

use Drupal\access_badges\Plugin\BadgeTools;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Management form for badge assignments needing review.
 */
final class BadgeReviewForm extends FormBase {

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
   * The badge tools service.
   *
   * @var \Drupal\access_badges\Plugin\BadgeTools
   */
  protected $badgeTools;

  /**
   * Constructs a BadgeReviewForm.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, BadgeTools $badge_tools) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->badgeTools = $badge_tools;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('access_badges.badgeTools')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_badges_review_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $rows = $this->getReviewRows();

    if (empty($rows)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No badge assignments need review.') . '</p>',
      ];
      return $form;
    }

    $form['rows'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Email'),
        $this->t('CSV Name'),
        $this->t('Organization'),
        $this->t('Badge'),
        $this->t('Matched User'),
        $this->t('Matched Email'),
        $this->t('Matched Organization'),
        $this->t('Strength'),
        $this->t('Actions'),
      ],
      '#empty' => $this->t('No badge assignments need review.'),
    ];

    foreach ($rows as $index => $row) {
      $form['rows'][$index]['email'] = [
        '#markup' => $row['email'],
      ];
      $form['rows'][$index]['csv_name'] = [
        '#markup' => trim($row['first_name'] . ' ' . $row['last_name']),
      ];
      $form['rows'][$index]['organization'] = [
        '#markup' => $row['organization'],
      ];
      $form['rows'][$index]['badge'] = [
        '#markup' => $row['badge_name'],
      ];
      $form['rows'][$index]['matched_user'] = [
        '#markup' => $row['matched_name'],
      ];
      $form['rows'][$index]['matched_email'] = [
        '#markup' => $row['matched_email'],
      ];
      $form['rows'][$index]['matched_org'] = [
        '#markup' => $row['matched_org'],
      ];
      $form['rows'][$index]['strength'] = [
        '#markup' => $row['strength'],
      ];
      $form['rows'][$index]['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['form--inline']],
        'assign' => [
          '#type' => 'submit',
          '#value' => $this->t('Assign'),
          '#name' => 'assign_' . $row['id'],
          '#submit' => ['::assignSubmit'],
          '#attributes' => ['class' => ['button', 'button--small', 'button--primary']],
          '#row_id' => $row['id'],
        ],
        'dismiss' => [
          '#type' => 'submit',
          '#value' => $this->t('Dismiss'),
          '#name' => 'dismiss_' . $row['id'],
          '#submit' => ['::dismissSubmit'],
          '#attributes' => ['class' => ['button', 'button--small']],
          '#row_id' => $row['id'],
        ],
        'delete' => [
          '#type' => 'submit',
          '#value' => $this->t('Delete'),
          '#name' => 'delete_' . $row['id'],
          '#submit' => ['::deleteSubmit'],
          '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
          '#row_id' => $row['id'],
        ],
      ];
    }

    return $form;
  }

  /**
   * Gets review rows with matched user details.
   *
   * @return array<int, array<string, mixed>>
   *   The review rows.
   */
  protected function getReviewRows(): array {
    $results = $this->database->select('access_badges_pending', 'p')
      ->fields('p')
      ->condition('p.status', 'review')
      ->orderBy('p.created', 'DESC')
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $badge_name = '';
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($row->badge_tid);
      if ($term) {
        $badge_name = $term->label();
      }

      $matched_name = '';
      $matched_email = '';
      $matched_org = '';
      $strength = 'Possible';

      if ($row->matched_uid) {
        $matched_user = $this->entityTypeManager->getStorage('user')->load($row->matched_uid);
        if ($matched_user) {
          $matched_name = $matched_user->getDisplayName();
          $matched_email = $matched_user->getEmail();

          // Get matched user's organization.
          if ($matched_user->hasField('field_access_organization') && !$matched_user->get('field_access_organization')->isEmpty()) {
            $org_entity = $matched_user->get('field_access_organization')->entity;
            $matched_org = $org_entity->label();
            // Determine strength.
            if (!empty($row->organization) && mb_strtolower($matched_org) === mb_strtolower($row->organization)) {
              $strength = 'Recommended';
            }
          }
        }
      }

      $rows[] = [
        'id' => $row->id,
        'email' => $row->email,
        'first_name' => $row->first_name,
        'last_name' => $row->last_name,
        'organization' => $row->organization,
        'badge_name' => $badge_name,
        'badge_tid' => $row->badge_tid,
        'vocabulary' => $row->vocabulary,
        'matched_uid' => $row->matched_uid,
        'matched_name' => $matched_name,
        'matched_email' => $matched_email,
        'matched_org' => $matched_org,
        'strength' => $strength,
      ];
    }

    return $rows;
  }

  /**
   * Gets the row ID from the triggering element.
   *
   * @return int|string|null
   *   The row ID, or NULL if not found.
   */
  protected function getRowIdFromTrigger(FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return $trigger['#row_id'] ?? NULL;
  }

  /**
   * Gets a pending row by ID.
   *
   * @param int|string $id
   *   The pending row ID.
   *
   * @return object|false
   *   The pending row, or FALSE if not found.
   */
  protected function getPendingRow($id) {
    return $this->database->select('access_badges_pending', 'p')
      ->fields('p')
      ->condition('p.id', $id)
      ->execute()
      ->fetchObject();
  }

  /**
   * Assign submit handler.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function assignSubmit(array &$form, FormStateInterface $form_state): void {
    $id = $this->getRowIdFromTrigger($form_state);
    $row = $this->getPendingRow($id);
    if (!$row) {
      $this->messenger()->addError($this->t('Row not found.'));
      return;
    }

    $assigned = $this->badgeTools->assignBadgeToUser($row->matched_uid, $row->badge_tid, $row->vocabulary);
    if ($assigned) {
      $this->database->delete('access_badges_pending')
        ->condition('id', $id)
        ->execute();
      $this->messenger()->addStatus($this->t('Badge assigned and pending row removed.'));
    }
    else {
      $this->database->delete('access_badges_pending')
        ->condition('id', $id)
        ->execute();
      $this->messenger()->addStatus($this->t('User already had this badge. Pending row removed.'));
    }
  }

  /**
   * Dismiss submit handler.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function dismissSubmit(array &$form, FormStateInterface $form_state): void {
    $id = $this->getRowIdFromTrigger($form_state);
    $this->database->update('access_badges_pending')
      ->fields([
        'status' => 'pending',
        'matched_uid' => NULL,
      ])
      ->condition('id', $id)
      ->execute();
    $this->messenger()->addStatus($this->t('Match dismissed. Row returned to pending.'));
  }

  /**
   * Delete submit handler.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function deleteSubmit(array &$form, FormStateInterface $form_state): void {
    $id = $this->getRowIdFromTrigger($form_state);
    $this->database->delete('access_badges_pending')
      ->condition('id', $id)
      ->execute();
    $this->messenger()->addStatus($this->t('Pending row deleted.'));
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Primary submit handled by sub-handlers.
  }

}
