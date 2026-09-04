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
 * Covers the create endpoint's recurrence-boundary INPUT normalization.
 *
 * A recurrence range boundary is a zone-neutral calendar date, so the
 * controller takes the LITERAL date part of whatever arrives — a bare
 * `YYYY-MM-DD` or any datetime-shaped string — and anchors it `T12:00:00`,
 * never converting through a timezone (an old client's `2026-10-01T00:00:00`
 * means Oct 1 and stays Oct 1). After normalizing both ends it rejects an
 * inverted range with a 422 naming both columns (offset-carrying pairs can
 * flip under date-part extraction), and anything whose first ten characters
 * are not a real calendar date with a 422 naming the field and the expected
 * YYYY-MM-DD format. Both refusals fire on the commit AND preview paths.
 *
 * The controller then marks the series as normalized-by-writer so the bundle
 * class preSave() stores its anchors verbatim — correct under ANY acting-user
 * timezone, pinned here by the Auckland end-to-end create.
 *
 * @group access_events
 */
class EventCrudBoundaryInputTest extends EventKernelTestBase {

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

    // The weekly spawn/preview paths format times via the core html_date /
    // html_time date-format config entities, absent from a minimal kernel env.
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
   * A weekly create body whose rule arrives as a LIST of item arrays.
   *
   * The list-of-items shape is what real MCP payloads carry (and what the
   * daterange field API stores); the flat-assoc shape is exercised separately.
   */
  private function weeklyBody(string $value, string $endValue): array {
    return [
      'title' => 'Boundary Input Event',
      'recur_type' => 'weekly_recurring_date',
      'weekly_recurring_date' => [
        [
          'value' => $value,
          'end_value' => $endValue,
          'time' => '10:00 am',
          'end_time' => '11:00 am',
          'duration' => '60',
          'duration_or_end_time' => 'duration',
          'days' => 'monday',
        ],
      ],
    ];
  }

  /**
   * POSTs the body to the create endpoint's commit path (?confirmed=true).
   */
  private function commit(array $body, User $user): JsonResponse {
    return $this->doCrud('create', NULL, $user, $body, ['confirmed' => 'true']);
  }

  /**
   * POSTs the body to the create endpoint's preview path (?confirmed=false).
   */
  private function preview(array $body, User $user): JsonResponse {
    return $this->doCrud('create', NULL, $user, $body, ['confirmed' => 'false']);
  }

  /**
   * Runs $callable with PHP's default timezone set to $timezone.
   *
   * Mirrors a real request for a user in that zone: the bundle class preSave
   * resolves the saver zone via date_default_timezone_get(), so an
   * un-flagged far-east save would re-derive an anchored date through it.
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
   * Reads the stored bytes of a boundary pair straight from the data table.
   *
   * Direct SQL, not an entity load: the assertions are about what the STORAGE
   * holds byte-for-byte, and an entity reload would tolerate any
   * representation that merely round-trips.
   *
   * @return array{0: string|null, 1: string|null}
   *   [{field}__value, {field}__end_value].
   */
  private function storedPair(int $seriesId, string $field): array {
    $row = \Drupal::database()->select('eventseries_field_data', 'd')
      ->fields('d', [$field . '__value', $field . '__end_value'])
      ->condition('id', $seriesId)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return [$row[$field . '__value'], $row[$field . '__end_value']];
  }

  /**
   * A bare-date boundary pair commits and stores literal noon anchors.
   */
  public function testBareDateCommitAcceptedAndStoredAnchored(): void {
    $user = $this->createUser();
    $response = $this->commit($this->weeklyBody('2999-06-01', '2999-06-29'), $user);

    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    [$storedValue, $storedEnd] = $this->storedPair((int) $data['series_id'], 'weekly_recurring_date');
    $this->assertSame('2999-06-01T12:00:00', $storedValue);
    $this->assertSame('2999-06-29T12:00:00', $storedEnd);
  }

  /**
   * A T00:00:00-shaped boundary means the same literal dates as a bare pair.
   *
   * An old client's `2999-06-01T00:00:00` means June 1 and stays June 1 — the
   * date part is taken literally, never converted through any timezone — so
   * its preview must produce occurrences identical to the bare-date preview.
   */
  public function testMidnightShapedInputPreviewsIdenticallyToBareDate(): void {
    $user = $this->createUser();

    $bare = $this->preview($this->weeklyBody('2999-06-01', '2999-06-29'), $user);
    $this->assertSame(200, $bare->getStatusCode(), $bare->getContent());

    $midnight = $this->preview($this->weeklyBody('2999-06-01T00:00:00', '2999-06-29T00:00:00'), $user);
    $this->assertSame(200, $midnight->getStatusCode(), $midnight->getContent());

    $bareData = json_decode($bare->getContent(), TRUE);
    $midnightData = json_decode($midnight->getContent(), TRUE);
    $this->assertNotEmpty($bareData['occurrences']);
    $this->assertSame($bareData['occurrences'], $midnightData['occurrences']);
  }

  /**
   * An unparseable boundary is a 422 naming the field and the format.
   *
   * "Unparseable" = the first ten characters are not a real calendar date:
   * both a non-date string and a well-shaped-but-impossible date (month 13,
   * day 45) refuse. Fires on BOTH the commit and preview paths.
   */
  public function testUnparseableBoundaryRefusedNamingFieldAndFormat(): void {
    $user = $this->createUser();

    foreach (['October 1', '2999-13-45'] as $bad) {
      foreach (['commit', 'preview'] as $path) {
        $body = $this->weeklyBody($bad, '2999-06-29');
        $response = $path === 'commit' ? $this->commit($body, $user) : $this->preview($body, $user);

        $this->assertSame(422, $response->getStatusCode(), "$path refuses '$bad'");
        $data = json_decode($response->getContent(), TRUE);
        $this->assertSame('validation_error', $data['error']);
        $this->assertStringContainsString('weekly_recurring_date', $data['message'], "$path names the field for '$bad'");
        $this->assertStringContainsString('YYYY-MM-DD', $data['message'], "$path names the format for '$bad'");
      }
    }
  }

  /**
   * A post-normalization inverted range is a 422 naming both columns.
   *
   * Two ways to invert: plainly swapped bare dates, and an offset-carrying
   * pair that is ordered as instants but flips under literal date-part
   * extraction (start June 2 at +14:00 is an earlier instant than end June 1
   * late UTC). Both refuse on BOTH the commit and preview paths.
   */
  public function testInvertedRangeRefusedOnBothPaths(): void {
    $user = $this->createUser();

    $cases = [
      'swapped bare dates' => ['2999-06-29', '2999-06-01'],
      'offset-carrying flip' => ['2999-06-02T00:00:00+14:00', '2999-06-01T23:00:00+00:00'],
    ];
    foreach ($cases as $label => [$value, $endValue]) {
      foreach (['commit', 'preview'] as $path) {
        $body = $this->weeklyBody($value, $endValue);
        $response = $path === 'commit' ? $this->commit($body, $user) : $this->preview($body, $user);

        $this->assertSame(422, $response->getStatusCode(), "$path refuses $label");
        $data = json_decode($response->getContent(), TRUE);
        $this->assertSame('validation_error', $data['error']);
        $this->assertStringContainsString('weekly_recurring_date', $data['message'], "$path names the field ($label)");
        $this->assertStringContainsString('value', $data['message'], "$path names the start column ($label)");
        $this->assertStringContainsString('end_value', $data['message'], "$path names the end column ($label)");
      }
    }
  }

  /**
   * A flat-assoc rule shape (no item wrapper) still normalizes.
   *
   * Some callers send the rule as a single assoc rather than a list of item
   * arrays; the normalization must walk both shapes.
   */
  public function testFlatAssocRuleShapeNormalizes(): void {
    $user = $this->createUser();
    $body = $this->weeklyBody('x', 'x');
    $body['weekly_recurring_date'] = $body['weekly_recurring_date'][0];
    $body['weekly_recurring_date']['value'] = '2999-06-01';
    $body['weekly_recurring_date']['end_value'] = '2999-06-29';

    $response = $this->commit($body, $user);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    [$storedValue, $storedEnd] = $this->storedPair((int) $data['series_id'], 'weekly_recurring_date');
    $this->assertSame('2999-06-01T12:00:00', $storedValue);
    $this->assertSame('2999-06-29T12:00:00', $storedEnd);
  }

  /**
   * An Auckland acting user's create stores the SENT literal dates.
   *
   * End-to-end pin of the writer flag: the controller's anchors must reach
   * storage verbatim. Without the normalized-by-writer flag, the bundle class
   * preSave would treat the anchored values as changed input and re-derive
   * them through the Auckland saver's zone (UTC+12 in June), ratcheting both
   * dates to June 2 / June 30.
   */
  public function testAucklandActingUserCreateStoresTheSentLiteralDate(): void {
    $user = $this->createUser([], NULL, FALSE, ['timezone' => 'Pacific/Auckland']);
    $response = $this->inTimezone(
      'Pacific/Auckland',
      fn (): JsonResponse => $this->commit($this->weeklyBody('2999-06-01', '2999-06-29'), $user),
    );

    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    [$storedValue, $storedEnd] = $this->storedPair((int) $data['series_id'], 'weekly_recurring_date');
    $this->assertSame('2999-06-01T12:00:00', $storedValue);
    $this->assertSame('2999-06-29T12:00:00', $storedEnd);
  }

}
