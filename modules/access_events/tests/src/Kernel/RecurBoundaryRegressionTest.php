<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Pins the three live manifestations of the one-day-early recurrence bug.
 *
 * Before range boundaries became zone-neutral calendar dates, a boundary sent
 * as a date was stored as a UTC-midnight instant and re-read through the
 * acting user's timezone, sliding the calendar date one day early for any
 * reader west of UTC. Three symptoms observed on real traffic, each driven
 * through the create controller (preview and commit) exactly as the MCP
 * drives it, each pinned with EXACT dates — never counts alone — and each
 * repeated under a New York AND a Pacific/Auckland acting user:
 * - a daily series starting Oct 1 whose first occurrence came out Sep 30;
 * - a yearly series spanning three calendar years whose endpoint years were
 *   clipped from the occurrence set;
 * - a tight single-day consecutive window that generated zero occurrences.
 *
 * Occurrence instants echo as UTC ATOM strings, so every assertion converts
 * them into the acting user's zone first — string-slicing the UTC instant
 * would itself misreport the local date (10:00 NZDT is 21:00 UTC the previous
 * day) and mask exactly the class of bug this file pins.
 *
 * @group access_events
 */
class RecurBoundaryRegressionTest extends EventKernelTestBase {

  /**
   * The two acting-user zones every repro runs under.
   *
   * New York (west of UTC) is the zone the live bug bit; Auckland (far east,
   * DST-active for the October fixtures) is the opposite-sign extreme that
   * catches an over-correction sliding dates the other way.
   */
  private const ZONES = ['America/New_York', 'Pacific/Auckland'];

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

    // The spawn/preview paths format times via the core html_date / html_time
    // date-format config entities, absent from a minimal kernel env.
    $this->installConfig(['system']);

    // The insert hook auto-spawns instances, whose presave reads domain_access
    // + the post-survey tracking fields on both the series and each instance.
    // Seed the empty site-level fields those hooks touch, mirroring
    // EventCrudCreateTest.
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

    // access_events_entity_access() reads field_other_authors on every
    // eventseries access check, and the controller's $series->access('create')
    // check runs it. Attach it empty.
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

    // In production every authenticated user holds 'add eventseries entity',
    // which governs the entity-type create permission the controller enforces.
    $this->grantPermissions(
      Role::load(AccountInterface::AUTHENTICATED_ROLE),
      ['add eventseries entity'],
    );

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A daily create body over the given boundary pair, 10-11am each day.
   */
  private function dailyBody(string $value, string $endValue): array {
    return [
      'title' => 'Daily Regression Event',
      'recur_type' => 'daily_recurring_date',
      'daily_recurring_date' => [
        [
          'value' => $value,
          'end_value' => $endValue,
          'time' => '10:00 am',
          'end_time' => '11:00 am',
          'duration' => '3600',
          'duration_or_end_time' => 'end_time',
        ],
      ],
    ];
  }

  /**
   * A yearly (every June 1) create body over the given boundary pair.
   */
  private function yearlyBody(string $value, string $endValue): array {
    return [
      'title' => 'Yearly Regression Event',
      'recur_type' => 'yearly_recurring_date',
      'yearly_recurring_date' => [
        [
          'value' => $value,
          'end_value' => $endValue,
          'time' => '10:00 am',
          'end_time' => '11:00 am',
          'duration' => '3600',
          'duration_or_end_time' => 'end_time',
          'type' => 'monthday',
          'day_of_month' => '1',
          'year_interval' => 1,
          'months' => 'Jun',
        ],
      ],
    ];
  }

  /**
   * A tight consecutive body: one day, a 10-11am window, 30-minute slots.
   */
  private function consecutiveBody(string $value, string $endValue): array {
    return [
      'title' => 'Consecutive Regression Event',
      'recur_type' => 'consecutive_recurring_date',
      'consecutive_recurring_date' => [
        [
          'value' => $value,
          'end_value' => $endValue,
          'time' => '10:00 am',
          'end_time' => '11:00 am',
          'duration' => 30,
          'duration_units' => 'minutes',
          'buffer' => 0,
          'buffer_units' => 'minutes',
        ],
      ],
    ];
  }

  /**
   * POSTs the body to the create endpoint's preview path (?confirmed=false).
   */
  private function preview(array $body, User $user): JsonResponse {
    return $this->doCrud('create', NULL, $user, $body, ['confirmed' => 'false']);
  }

  /**
   * POSTs the body to the create endpoint's commit path (?confirmed=true).
   */
  private function commit(array $body, User $user): JsonResponse {
    return $this->doCrud('create', NULL, $user, $body, ['confirmed' => 'true']);
  }

  /**
   * Runs $callable with PHP's default timezone set to $timezone.
   *
   * Mirrors a real request for a user in that zone: production sets the
   * per-request default timezone to the acting user's profile zone, and both
   * the boundary handling and contrib's occurrence math read it.
   */
  private function inTimezone(string $timezone, callable $callable) {
    $previous = date_default_timezone_get();
    date_default_timezone_set($timezone);
    try {
      return $callable();
    }
    finally {
      date_default_timezone_set($previous);
    }
  }

  /**
   * Preview occurrence starts converted into the acting zone.
   *
   * @param array $occurrences
   *   The envelope's occurrences (start_date as a UTC ATOM string).
   * @param string $timezone
   *   The acting user's zone.
   * @param string $format
   *   The date() format to render each local start in.
   *
   * @return string[]
   *   One formatted local start per occurrence, in envelope order.
   */
  private function localStarts(array $occurrences, string $timezone, string $format = 'Y-m-d'): array {
    return array_map(
      fn (array $o): string => (new \DateTimeImmutable($o['start_date']))
        ->setTimezone(new \DateTimeZone($timezone))
        ->format($format),
      $occurrences,
    );
  }

  /**
   * Live repro 1: a daily series sent as Oct 1-5 occurs Oct 1-5, not Sep 30.
   *
   * The original bug made the first occurrence land one local day early
   * (Sep 30 for a New York user). Pin the FULL local date list on the preview
   * path, then commit the same body and pin the spawned instances' local
   * start datetimes — both halves of the controller path, under both zones.
   */
  public function testDailyOctoberFirstStartsOctoberFirst(): void {
    $expectedDates = ['2999-10-01', '2999-10-02', '2999-10-03', '2999-10-04', '2999-10-05'];

    foreach (self::ZONES as $timezone) {
      $user = $this->createUser([], NULL, FALSE, ['timezone' => $timezone]);
      $body = $this->dailyBody('2999-10-01', '2999-10-05');

      $response = $this->inTimezone($timezone, fn (): JsonResponse => $this->preview($body, $user));
      $this->assertSame(200, $response->getStatusCode(), $response->getContent());
      $data = json_decode($response->getContent(), TRUE);
      $this->assertSame(
        ['start_date' => '2999-10-01', 'end_date' => '2999-10-05'],
        $data['range'],
        "The resolved range is the sent literal dates under $timezone",
      );
      $this->assertSame(
        $expectedDates,
        $this->localStarts($data['occurrences'], $timezone),
        "A daily series sent as Oct 1-5 previews Oct 1 through Oct 5 — never a Sep 30 start — under $timezone",
      );

      $commitResponse = $this->inTimezone($timezone, fn (): JsonResponse => $this->commit($body, $user));
      $this->assertSame(200, $commitResponse->getStatusCode(), $commitResponse->getContent());
      $commitData = json_decode($commitResponse->getContent(), TRUE);

      $storedStarts = [];
      foreach ($commitData['instance_ids'] as $instanceId) {
        $instance = \Drupal::entityTypeManager()->getStorage('eventinstance')->load($instanceId);
        $storedStarts[] = (new \DateTimeImmutable('@' . $instance->get('date')->start_date->getTimestamp()))
          ->setTimezone(new \DateTimeZone($timezone))
          ->format('Y-m-d H:i');
      }
      sort($storedStarts);
      $this->assertSame(
        array_map(fn (string $d): string => "$d 10:00", $expectedDates),
        $storedStarts,
        "Committed instances start 10:00 local on Oct 1 through Oct 5 under $timezone",
      );
    }
  }

  /**
   * Live repro 2: a yearly span across three years keeps BOTH endpoint years.
   *
   * A June-1 yearly sent as 2999-06-01 through 3001-06-01 covers exactly
   * three calendar years. The original bug slid a boundary a day early
   * (May 31), clipping an endpoint occurrence off the set — so the assertion
   * is the exact three-date list, with the first and last year present, not
   * a count.
   */
  public function testYearlySpanKeepsBothEndpointYears(): void {
    foreach (self::ZONES as $timezone) {
      $user = $this->createUser([], NULL, FALSE, ['timezone' => $timezone]);
      $body = $this->yearlyBody('2999-06-01', '3001-06-01');

      $response = $this->inTimezone($timezone, fn (): JsonResponse => $this->preview($body, $user));
      $this->assertSame(200, $response->getStatusCode(), $response->getContent());
      $data = json_decode($response->getContent(), TRUE);
      $this->assertSame(
        ['start_date' => '2999-06-01', 'end_date' => '3001-06-01'],
        $data['range'],
        "The resolved range is the sent literal dates under $timezone",
      );
      $this->assertSame(
        ['2999-06-01', '3000-06-01', '3001-06-01'],
        $this->localStarts($data['occurrences'], $timezone),
        "A three-year June-1 yearly keeps both endpoint occurrences under $timezone",
      );
    }
  }

  /**
   * Live repro 3: a tight single-day consecutive window is non-empty.
   *
   * A one-day window (value == end_value) with a 10-11am slot grid. The
   * original bug slid a boundary early enough to empty the window, silently
   * generating zero occurrences. Pin the exact local slot list: contrib's
   * slot loop admits every slot whose START is within the window, so a 30-
   * minute grid over 10:00-11:00 is the 10:00, 10:30 and 11:00 starts, all
   * on the sent day.
   */
  public function testTightConsecutiveWindowProducesTheSentDaysSlots(): void {
    foreach (self::ZONES as $timezone) {
      $user = $this->createUser([], NULL, FALSE, ['timezone' => $timezone]);
      $body = $this->consecutiveBody('2999-10-01', '2999-10-01');

      $response = $this->inTimezone($timezone, fn (): JsonResponse => $this->preview($body, $user));
      $this->assertSame(200, $response->getStatusCode(), $response->getContent());
      $data = json_decode($response->getContent(), TRUE);
      $this->assertNotEmpty(
        $data['occurrences'],
        "A tight consecutive window computes occurrences — the original produced zero — under $timezone",
      );
      $this->assertSame(
        ['2999-10-01 10:00', '2999-10-01 10:30', '2999-10-01 11:00'],
        $this->localStarts($data['occurrences'], $timezone, 'Y-m-d H:i'),
        "Every slot lands on the sent day under $timezone",
      );
    }
  }

}
