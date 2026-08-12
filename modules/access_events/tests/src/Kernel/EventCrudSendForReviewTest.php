<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

/**
 * Covers POST /api/2.3/event-series/{eventseries}/send-for-review.
 *
 * send_for_review is the author-facing path to publication: a plain author
 * (authenticated, no editor role) holds create_new_draft and send_for_review
 * but NOT publish, so a draft they create cannot be self-published. This op
 * transitions the series draft|needs_adjustment → ready_for_review so an editor
 * (AG-leader or news_pm, who hold publish) can approve it.
 *
 * This is the first write op where a PLAIN AUTHOR succeeds — unlike delete
 * (archive) and restore (archived_published), whose transitions authenticated
 * does not hold. The op is gated by:
 *  - the coordinator/entity-access helper (userMayManageSeries('update'));
 *  - a source-state guard (only draft/needs_adjustment may send_for_review);
 *  - the send_for_review moderation transition permission.
 *
 * The source-state guard is anchored on the CURRENT state, not the target: two
 * transitions target ready_for_review (send_for_review from draft/
 * needs_adjustment, review_to_review from ready_for_review), so a
 * target-reachability check would be ambiguous. The in_array source check is
 * unambiguous and keeps isTransitionValid from ever being called on a state it
 * cannot send_for_review from — so a published/archived series refuses cleanly
 * (invalid_state, 409), never a 500.
 */
class EventCrudSendForReviewTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'link',
    'datetime',
    'datetime_range',
    'field_inheritance',
    'recurring_events',
    'recurring_events_registration',
    'taxonomy',
    'node',
    'filter',
    'workflows',
    'content_moderation',
    'access_affinitygroup',
    'key',
    'access_events',
    'access_misc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and access_events_entity_access (reads
    // field_other_authors on every series access check). Seed the empty
    // site-level fields those hooks touch, mirroring EventCrudRestoreTest.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1],
      ['eventinstance', 'domain_access', 'string', -1],
      ['eventinstance', 'post_survey_url', 'link', 1],
      ['eventinstance', 'field_post_survey_reminder_sent', 'integer', 1],
      ['eventinstance', 'field_post_survey_sent', 'integer', 1],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
    }

    if (!FieldStorageConfig::loadByName('eventseries', 'field_other_authors')) {
      FieldStorageConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'user'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_other_authors',
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
      FieldStorageConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => 1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }

    // In production every authenticated user holds 'add eventseries entity'.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A plain author sends their own draft → 200, series ready_for_review.
   *
   * The key inversion: an author (authenticated, no editor role) SUCCEEDS here.
   * They hold send_for_review but not publish, so this is their legal next step
   * after create_event produces a draft.
   */
  public function testSendForReviewFromDraftTransitionsToReadyForReview(): void {
    $author = $this->createUser();
    $series = $this->makeCoordinatorSeries($author);
    $response = $this->doCrud('sendForReview', (int) $series->id(), $author, []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame('ready_for_review', $data['moderation_state']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('ready_for_review', $reloaded->get('moderation_state')->value);
  }

  /**
   * A coordinator sends a draft → 200.
   *
   * The coordinator is authorized via the affinity-group grant
   * (userMayManageSeries('update')) and holds send_for_review as an
   * authenticated user.
   */
  public function testSendForReviewByCoordinatorSucceeds(): void {
    $coordinator = $this->createUser();
    $series = $this->makeCoordinatorSeries($coordinator);
    $response = $this->doCrud('sendForReview', (int) $series->id(), $coordinator, []);
    $this->assertSame(200, $response->getStatusCode());
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('ready_for_review', $reloaded->get('moderation_state')->value);
  }

  /**
   * A published series refuses invalid_state (409), not a 500.
   *
   * send_for_review is valid only from draft/needs_adjustment. The
   * source-state guard refuses a published series before isTransitionValid is
   * ever called, so no \InvalidArgumentException (HTTP 500) can be thrown.
   */
  public function testSendForReviewFromPublishedRefusesInvalidState(): void {
    $coordinator = $this->createUser();
    $series = $this->makePublishedCoordinatorSeries($coordinator);
    $response = $this->doCrud('sendForReview', (int) $series->id(), $coordinator, []);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('invalid_state', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('published', $reloaded->get('moderation_state')->value);
  }

  /**
   * A stranger with no edit access is refused (not_coordinator) — gate first.
   *
   * userMayManageSeries('update') gates before the transition is even
   * considered, so a user with no edit permission on the series is refused
   * without touching moderation state.
   */
  public function testSendForReviewRejectsUserLackingEntityAccess(): void {
    $stranger = $this->createUser();
    $series = $this->makeCoordinatorSeries($this->createUser());
    $response = $this->doCrud('sendForReview', (int) $series->id(), $stranger, []);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('not_coordinator', json_decode($response->getContent(), TRUE)['error']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('draft', $reloaded->get('moderation_state')->value);
  }

  /**
   * An author sends a needs_adjustment series → 200, transitions to ready_for_review.
   *
   * send_for_review is valid from both draft and needs_adjustment. This test
   * covers the needs_adjustment → ready_for_review arm, which is untested in
   * the draft path tests above. The series is created draft, published, then
   * set to needs_adjustment (simulating a published series that was moved to
   * needs adjustment via request_adjustment), then send_for_review is called.
   */
  public function testSendForReviewFromNeedsAdjustmentTransitionsToReadyForReview(): void {
    $author = $this->createUser();
    $series = $this->makeCoordinatorSeries($author);
    // Publish the series first so it can be transitioned to needs_adjustment.
    $series->set('moderation_state', 'published')->save();
    // Transition to needs_adjustment (simulating request_adjustment).
    $series->set('moderation_state', 'needs_adjustment')->save();
    // Now send for review from needs_adjustment.
    $response = $this->doCrud('sendForReview', (int) $series->id(), $author, []);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertSame('ready_for_review', $data['moderation_state']);
    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $this->assertSame('ready_for_review', $reloaded->get('moderation_state')->value);
  }

}
