<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers POST /api/2.3/events?confirmed=false — the no-write occurrence preview.
 *
 * An absent or falsey `confirmed` query param routes createEvent() to a
 * no-write preview: it builds the SAME unsaved series the commit path would,
 * gates on the acting user + the entity create permission (A3), runs the D3
 * pre-compute validator, computes the occurrence dates, and returns them WITHOUT
 * saving. The envelope is exactly {status:"preview", executed:false,
 * occurrence_count, truncated, occurrences:[{start_date,end_date ISO}]}.
 *
 * The critical invariant these tests pin: a preview writes NOTHING — the
 * eventseries storage count is identical before and after every preview.
 */
class EventCrudPreviewTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // compute() delegates a rule recur_type to its contrib field plugin's
    // calculateInstances(), which formats times via the core html_date /
    // html_time date_format config entities. Those ship in system's
    // config/install but are absent from this minimal kernel env — install
    // them so a weekly preview can materialize its dates (same reason
    // EventCrudCreateTest::testCreateEventWithRuleRecurTypePassesValidationGate
    // installs system config).
    $this->installConfig(['system']);

    // access_events_entity_access() reads field_other_authors on every
    // eventseries access check (no hasField guard), and the preview's
    // $series->access('create') check runs it. Attach it empty.
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

    // In production every authenticated user holds 'add eventseries entity'
    // (user.role.authenticated.yml), which governs the entity-type create
    // permission the preview's $series->access('create') check enforces (A3).
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * POSTs a create body with ?confirmed=false baked into the URL.
   *
   * The confirmed flag MUST be in the URL, not passed as Request::create()'s
   * $parameters: for a POST that arg lands in the request (POST) bag, not
   * ->query, so the controller's $request->query->get('confirmed') would miss
   * it and route to the commit path instead of preview (the same query-in-URL
   * gotcha doOccurrence() documents for its force/confirmed gates).
   *
   * @param array $body
   *   The decoded JSON create body.
   * @param \Drupal\user\Entity\User $actingUser
   *   The acting user whose uid is bound to acting_user_uid.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The controller's response.
   */
  private function previewRequest(array $body, User $actingUser): JsonResponse {
    $path = '/api/2.3/events?' . http_build_query(['confirmed' => 'false']);
    $request = Request::create($path, 'POST', [], [], [], [], json_encode($body));
    $request->attributes->set('acting_user_uid', (int) $actingUser->id());

    return $this->asActingUser(
      $actingUser,
      fn () => \Drupal\access_events\Controller\EventCrudApiController::create(\Drupal::getContainer())
        ->createEvent($request),
    );
  }

  /**
   * Counts persisted eventseries entities.
   */
  private function seriesCount(): int {
    return (int) \Drupal::entityQuery('eventseries')->accessCheck(FALSE)->count()->execute();
  }

  /**
   * A well-formed weekly recur config over a bounded, future window.
   */
  private function validWeeklyConfig(): array {
    return [
      'value' => '2999-01-04T00:00:00',
      'end_value' => '2999-01-31T00:00:00',
      'time' => '10:00 AM',
      'end_time' => '11:00 AM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => 'monday,wednesday',
    ];
  }

  /**
   * Case 1 — happy path: a valid weekly previews its future occurrences.
   *
   * 200, status:"preview", executed:false, truncated:false, a non-empty
   * occurrence list with occurrence_count === count(occurrences), each carrying
   * ISO start_date/end_date — and NOTHING persisted.
   */
  public function testPreviewHappyPath(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $this->validWeeklyConfig(),
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame('preview', $data['status']);
    $this->assertFalse($data['executed']);
    $this->assertFalse($data['truncated']);
    $this->assertNotEmpty($data['occurrences']);
    $this->assertSame(count($data['occurrences']), $data['occurrence_count']);
    foreach ($data['occurrences'] as $occurrence) {
      $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $occurrence['start_date']);
      $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $occurrence['end_date']);
    }

    // The critical invariant: a preview writes nothing.
    $this->assertSame($before, $this->seriesCount(), 'A preview must not persist an eventseries.');
  }

  /**
   * Case 2 — valid-but-empty: an all-past config previews zero, not a 422.
   *
   * Every occurrence is filtered out as past, which is legitimate (not
   * malformed) — the validator returns NULL and the preview reports an empty
   * set with occurrence_count 0, NOT a validation error.
   */
  public function testPreviewValidButEmpty(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $config = $this->validWeeklyConfig();
    $config['value'] = '2000-01-03T00:00:00';
    $config['end_value'] = '2000-01-31T00:00:00';
    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $config,
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $this->assertSame(0, $data['occurrence_count']);
    $this->assertSame([], $data['occurrences']);
    $this->assertFalse($data['truncated']);

    $this->assertSame($before, $this->seriesCount(), 'A preview must not persist an eventseries.');
  }

  /**
   * Case 3 — malformed: an empty weekly days list is a 422 validation_error.
   *
   * The D3 validator fires BEFORE compute(): a weekly with no days would fatal
   * WeeklyRecurringDate::calculateInstances(), so validateConfig() returns a
   * string the preview turns into a 422 — and nothing is saved.
   */
  public function testPreviewMalformedIs422(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $config = $this->validWeeklyConfig();
    $config['days'] = '';
    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $config,
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('validation_error', json_decode($response->getContent(), TRUE)['error']);

    $this->assertSame($before, $this->seriesCount(), 'A refused preview must not persist an eventseries.');
  }

  /**
   * A missing recur_type is a 422 validation_error (preview's own gate).
   */
  public function testPreviewMissingRecurTypeIs422(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $response = $this->previewRequest(['title' => 'No Recur'], $user);

    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('validation_error', json_decode($response->getContent(), TRUE)['error']);

    $this->assertSame($before, $this->seriesCount());
  }

  /**
   * An unauthenticated (no acting user) preview is refused 403.
   */
  public function testPreviewNoActingUserIs403(): void {
    $path = '/api/2.3/events?' . http_build_query(['confirmed' => 'false']);
    $request = Request::create($path, 'POST', [], [], [], [], json_encode([
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $this->validWeeklyConfig(),
    ]));
    // No acting_user_uid attribute set → uid 0.
    $response = \Drupal\access_events\Controller\EventCrudApiController::create(\Drupal::getContainer())
      ->createEvent($request);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Case 4 — the invariant, isolated: two previews in a row persist nothing.
   *
   * Beyond the per-case before/after checks, this pins the invariant on its own:
   * a valid preview followed by a re-preview leaves the storage count unchanged
   * from the start.
   */
  public function testPreviewWritesNothing(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $this->validWeeklyConfig(),
    ];
    $this->previewRequest($body, $user);
    $this->previewRequest($body, $user);

    $this->assertSame($before, $this->seriesCount(), 'No preview may persist an eventseries.');
  }

  /**
   * Case 5 — truncation: a >1000-occurrence compute is capped and flagged.
   *
   * A full-day 15-minute consecutive over a ~20-day window computes ~1,920 real
   * slots (20 days x 96 slots/day) — comfortably above D4's 1000-row output cap
   * yet below D3's 5000 estimate cap (so validateConfig() lets it through). The
   * preview slices to exactly 1000 rows, reports occurrence_count 1000, and
   * flags truncated:true. This is a genuine >1000 compute (not a mock), kept
   * cheap by a bounded window so the kernel run stays fast — still nothing is
   * persisted.
   */
  public function testPreviewTruncatesAboveCap(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $body = [
      'recur_type' => 'consecutive_recurring_date',
      'consecutive_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        // ~20-day window: 20 x 96 = ~1,920 slots (> 1000, < 5000).
        'end_value' => '2999-01-21T00:00:00',
        'time' => '12:00 AM',
        'end_time' => '11:59 PM',
        // 15-minute net step (the D3 floor) — 96 slots per full day.
        'duration' => 15,
        'duration_units' => 'minutes',
        'buffer' => 0,
        'buffer_units' => 'minutes',
      ],
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $this->assertTrue($data['truncated']);
    $this->assertSame(1000, $data['occurrence_count']);
    $this->assertCount(1000, $data['occurrences']);

    $this->assertSame($before, $this->seriesCount(), 'A truncated preview must not persist an eventseries.');
  }

}
