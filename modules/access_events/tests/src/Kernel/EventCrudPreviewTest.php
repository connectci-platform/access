<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\EffectiveCreationSet;
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
 * gates on the acting user + the entity create permission, runs the
 * pre-compute validator, computes the occurrence dates, and returns them WITHOUT
 * saving. The envelope is {status:"preview", executed:false, timezone,
 * occurrence_count, total_occurrence_count, truncated,
 * occurrences:[{start_date,end_date ISO}]}, plus range:{start_date,end_date
 * bare YYYY-MM-DD} for rule recur_types (omitted for custom, which has no rule
 * boundaries). The timezone/range additions are pinned in
 * EventCrudBoundaryInputTest.
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
    // permission the preview's $series->access('create') check enforces.
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
    // Not truncated: total_occurrence_count is always present and equals the
    // shown count.
    $this->assertSame($data['occurrence_count'], $data['total_occurrence_count']);
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
   * validateConfig() fires BEFORE compute(): a weekly with no days would fatal
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
   * An acting user lacking 'add eventseries entity' is refused 403.
   *
   * setUp() grants the create permission to the authenticated role (as
   * production does), so every fixture user normally passes the
   * $series->access('create') gate. Revoke it for this test to model a user
   * who may act but may NOT create events: the preview must refuse 403
   * forbidden, NOT run compute(). This pins the load-bearing create gate so a
   * regression that drops the access check ships red — the gate is what keeps
   * the (bounded but still repeatable) preview compute off any authenticated
   * user who lacks create rights.
   */
  public function testPreviewWithoutCreatePermissionIs403(): void {
    user_role_revoke_permissions(AccountInterface::AUTHENTICATED_ROLE, ['add eventseries entity']);

    $user = $this->createUser();
    $before = $this->seriesCount();

    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $this->validWeeklyConfig(),
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame($before, $this->seriesCount(), 'A forbidden preview must not persist an eventseries.');
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
   * slots (20 days x 96 slots/day) — above the 1000-row output cap
   * (PREVIEW_OCCURRENCE_CAP) yet below the 5000 estimated-occurrence reject
   * threshold (MAX_ESTIMATED_OCCURRENCES), so validateConfig() lets it through.
   * The preview slices to exactly 1000 rows, reports occurrence_count 1000, and
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
        // 15-minute net step (the minimum consecutive slot floor) — 96 slots
        // per full day.
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
    // total_occurrence_count is the REAL total (~1,920 for this 20-day
    // full-day 15-min consecutive), NOT the post-cap 1000.
    $this->assertGreaterThan(1000, $data['total_occurrence_count']);
    $this->assertSame($this->computeCount($body, $user), $data['total_occurrence_count']);

    $this->assertSame($before, $this->seriesCount(), 'A truncated preview must not persist an eventseries.');
  }

  /**
   * The true occurrence count a body computes, via a direct compute() call.
   *
   * Recomputes the same unsaved series previewOccurrences() builds so the test
   * asserts total_occurrence_count against the actual full set rather than a
   * hard-coded magic number that a contrib edge could drift from.
   */
  private function computeCount(array $body, User $actingUser): int {
    return $this->asActingUser($actingUser, function () use ($body): int {
      $values = [
        'type' => 'default',
        'uid' => (int) \Drupal::currentUser()->id(),
        'moderation_state' => 'draft',
        'recur_type' => $body['recur_type'],
      ];
      if (array_key_exists($body['recur_type'], $body)) {
        $values[$body['recur_type']] = $body[$body['recur_type']];
      }
      $series = \Drupal::entityTypeManager()->getStorage('eventseries')->create($values);
      return count(\Drupal::service('access_events.effective_creation_set')->compute($series));
    });
  }

  /**
   * Preview occurrences come back in strictly chronological order.
   *
   * Contrib builds the set per-token (all Mondays, then all Wednesdays, then
   * all Fridays), so without a sort the preview would be token-grouped and a
   * head-slice would keep whole early weekdays and drop later ones. compute()
   * now sorts ascending by start_date; assert the returned occurrences are
   * strictly increasing.
   */
  public function testPreviewOccurrencesAreChronological(): void {
    $user = $this->createUser();

    $config = $this->validWeeklyConfig();
    // Several weeks, three interleaved weekdays — the per-token grouping that
    // exposes an unsorted set.
    $config['value'] = '2999-01-04T00:00:00';
    $config['end_value'] = '2999-02-28T00:00:00';
    $config['days'] = 'monday,wednesday,friday';
    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $config,
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertGreaterThan(3, count($data['occurrences']), 'Need enough occurrences to expose token grouping.');

    $previous = NULL;
    foreach ($data['occurrences'] as $occurrence) {
      $timestamp = strtotime($occurrence['start_date']);
      if ($previous !== NULL) {
        $this->assertGreaterThan($previous, $timestamp, 'Occurrences must be strictly increasing by start_date.');
      }
      $previous = $timestamp;
    }
  }

  /**
   * A >5000 raw custom-date list is rejected 422 before materialization.
   *
   * The raw-body count check in previewOccurrences() rejects before
   * storage->create()/convert materializes ~2N DrupalDateTime objects.
   */
  public function testPreviewRawCustomDatesOverCapIs422(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $dates = [];
    for ($i = 0; $i < EffectiveCreationSet::MAX_ESTIMATED_OCCURRENCES + 1; $i++) {
      $day = 1 + ($i % 27);
      $month = 1 + intdiv($i, 27);
      $year = 2999 + intdiv($month, 12);
      $month = 1 + ($month % 12);
      $dates[] = [
        'start_date' => sprintf('%04d-%02d-%02dT10:00:00', $year, $month, $day),
        'end_date' => sprintf('%04d-%02d-%02dT11:00:00', $year, $month, $day),
      ];
    }
    $response = $this->previewRequest(['recur_type' => 'custom', 'custom_dates' => $dates], $user);

    $this->assertSame(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('validation_error', $data['error']);
    $this->assertStringContainsString('more than', $data['message']);
    $this->assertSame($before, $this->seriesCount());
  }

  /**
   * A numeric-but-non-integer duration ("1e3") previews, not diverges.
   *
   * "1e3" passes validateConsecutive's (int) floor check but real
   * DateTime::modify('+1e3 seconds') throws (caught → empty compute), a
   * validate/compute divergence. applyRecurDates now casts duration/buffer to
   * a plain int, so validate and compute agree: the preview succeeds with a
   * non-empty occurrence set rather than passing validation then computing
   * empty.
   */
  public function testPreviewConsecutiveScientificDurationNormalized(): void {
    $user = $this->createUser();

    $body = [
      'recur_type' => 'consecutive_recurring_date',
      'consecutive_recurring_date' => [
        'value' => '2999-01-01T00:00:00',
        'end_value' => '2999-01-03T00:00:00',
        'time' => '09:00 AM',
        'end_time' => '05:00 PM',
        // Scientific-notation form: (int) "1e3" === 1000 seconds; raw "1e3"
        // into modify() would throw.
        'duration' => '1e3',
        'duration_units' => 'seconds',
        'buffer' => 0,
        'buffer_units' => 'seconds',
      ],
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('preview', $data['status']);
    $this->assertNotEmpty($data['occurrences'], 'Normalized duration must compute a non-empty set, not diverge to empty.');
  }

  /**
   * Weekly `days` sent as a JSON array is a clean 422, not a 500.
   *
   * A caller sending days as an array instead of a comma-string makes
   * contrib's explode(',', $array) throw a TypeError inside
   * convertEntityConfigToArray(), which validateConfig() calls. The wrapped
   * try/catch turns that into a validation_error 422 rather than an uncaught
   * framework 500.
   */
  public function testPreviewDaysAsArrayIs422NotError(): void {
    $user = $this->createUser();
    $before = $this->seriesCount();

    $config = $this->validWeeklyConfig();
    $config['days'] = ['monday', 'wednesday'];
    $body = [
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => $config,
    ];
    $response = $this->previewRequest($body, $user);

    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('validation_error', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame($before, $this->seriesCount());
  }

}
