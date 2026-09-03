<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\Entity\EventSeriesAccess;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventCreationService;

/**
 * An unchanged edit form is serialize-equal to the stored recurrence config.
 *
 * Contrib's series edit form fires its "instances will be recreated"
 * confirmation step whenever
 * EventCreationService::checkForFormRecurConfigChanges() sees
 * serialize(convertEntityConfigToArray($entity)) differ from
 * serialize(convertFormConfigToArray($form_state)). The entity half of that
 * comparison now flows through the bundle class' boundary getters, so the
 * objects they return must stay bit-for-bit serialize-compatible with what
 * the form half constructs — otherwise every title-only edit of every series
 * would face a phantom "instances will be recreated" confirm. This pins that
 * equality for an UNCHANGED submit on both storage shapes (anchored and
 * legacy wall-clock) and canaries the core internals the equality rides on.
 *
 * @group access_events
 */
class RecurConfigSerializeEqualityTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

  /**
   * DateTimePlus' vestigial input-capture properties.
   *
   * The serialize-equality rides on core never populating these on the date
   * objects either convert path builds (the guard (array)-casts objects, so
   * every property lands in the serialized comparison). If core starts
   * populating them — or renames them, which would make this canary vacuous —
   * the phantom-confirm guard needs re-verification, so assert loudly here
   * rather than letting the equality erode silently.
   */
  private const DATETIMEPLUS_INPUT_PROPS = [
    'inputTimeRaw',
    'inputTimeAdjusted',
    'inputTimeZoneRaw',
    'inputTimeZoneAdjusted',
    'inputFormatRaw',
    'inputFormatAdjusted',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Series/instance saves resolve through access_events_entity_presave
    // (reads domain_access) and the affinity-group node insert hook (reads
    // field_affinity_group). Seed the empty site-level fields those hooks
    // touch, mirroring EventCrudCancelOccurrenceTest.
    $fields = [
      ['eventseries', 'domain_access', 'string', -1, []],
      ['eventinstance', 'domain_access', 'string', -1, []],
      ['eventseries', 'field_other_authors', 'entity_reference', -1, ['target_type' => 'user']],
    ];
    foreach ($fields as [$entityType, $fieldName, $type, $cardinality, $settings]) {
      if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
        FieldStorageConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'type' => $type,
          'cardinality' => $cardinality,
          'settings' => $settings,
        ])->save();
        FieldConfig::create([
          'entity_type' => $entityType,
          'field_name' => $fieldName,
          'bundle' => 'default',
          'label' => $fieldName,
        ])->save();
      }
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

    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A saved daily-rule series whose boundary rows the tests overwrite.
   *
   * The creation-time boundary values are irrelevant (setRawBoundary()
   * replaces them); the non-date recurrence config (time, duration, ...) is
   * what the unchanged-form fixtures echo back.
   */
  private function makeDailySeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $group = $this->createAffinityGroupNode([(int) $coordinator->id()]);

    // The insert hook calculates instances, which formats times via the core
    // html_time/html_date date_format config entities (also needed at
    // comparison time: convertFormConfigToArray() runs the times through
    // convertTimeTo24hourFormat(), which formats with html_time). They ship
    // in system's config/install but not in this minimal kernel env.
    foreach ([
      'html_time' => 'H:i:s',
      'html_date' => 'Y-m-d',
    ] as $formatId => $pattern) {
      if (!\Drupal::entityTypeManager()->getStorage('date_format')->load($formatId)) {
        \Drupal::entityTypeManager()->getStorage('date_format')->create([
          'id' => $formatId,
          'label' => $formatId,
          'locked' => TRUE,
          'pattern' => $pattern,
        ])->save();
      }
    }

    $series = EventSeries::create([
      'title' => 'Daily Serialize-Equality Fixture',
      'body' => 'A daily rule series for the phantom-confirm guard.',
      'recur_type' => 'daily_recurring_date',
      'type' => 'default',
      'field_affinity_group_node' => [$group->id()],
      // A short bounded window keeps the spawned instance count small; the
      // instances themselves play no part in the comparison.
      'daily_recurring_date' => [
        'value' => '2999-03-02T00:00:00',
        'end_value' => '2999-03-06T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 3600,
        'duration_or_end_time' => 'duration',
      ],
    ]);
    $series->save();

    return $series;
  }

  /**
   * Runs $callable as an editor whose timezone is $timezone.
   *
   * Mirrors how a real request serves that user: the account is switched AND
   * PHP's default timezone follows the user's pref, because both convert
   * paths resolve the editor zone via date_default_timezone_get().
   */
  private function asEditorIn(string $timezone, callable $callable) {
    $editor = $this->createUser([], NULL, FALSE, ['timezone' => $timezone]);
    return $this->asActingUser($editor, function () use ($timezone, $callable) {
      $previous = date_default_timezone_get();
      date_default_timezone_set($timezone);
      try {
        return $callable();
      }
      finally {
        date_default_timezone_set($previous);
      }
    });
  }

  /**
   * Anchored rows: an untouched edit form produces zero config diff.
   *
   * The form dates are the stored LITERAL calendar dates — for an anchored
   * row that is what every editor's widget displays (and what an unchanged
   * submit therefore delivers back) regardless of timezone.
   */
  public function testAnchoredFixtureUnchangedFormIsSerializeEqual(): void {
    $series = $this->makeDailySeries();
    $this->setRawBoundary($series, 'daily_recurring_date', '2999-03-02T12:00:00', '2999-03-06T12:00:00');

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      $this->asEditorIn($timezone, function () use ($series, $timezone): void {
        $this->assertUnchangedFormMatchesStoredConfig($series, '2999-03-02', '2999-03-06', $timezone);
      });
    }
  }

  /**
   * Wall-clock (unmigrated/legacy) rows: an untouched form still no-diffs.
   *
   * Here the widget displays the stored UTC instant converted into the
   * editor's zone (stock behavior for non-anchored rows), so the unchanged
   * submit delivers THAT date back — which must land on the same calendar
   * date the hybrid decode's stock branch reads, in every timezone. This is
   * the deploy-window shape: rows the migration has not touched yet.
   */
  public function testWallClockFixtureUnchangedFormIsSerializeEqual(): void {
    $rawStart = '2999-03-02T00:00:00';
    $rawEnd = '2999-03-06T21:15:00';
    $series = $this->makeDailySeries();
    $this->setRawBoundary($series, 'daily_recurring_date', $rawStart, $rawEnd);

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      $this->asEditorIn($timezone, function () use ($series, $rawStart, $rawEnd, $timezone): void {
        // The widget-displayed date for a wall-clock row: stored UTC instant
        // expressed in the editor's zone (e.g. the 21:15 UTC end reads as the
        // NEXT calendar day in Auckland — and so does the entity config).
        $displayed = function (string $raw): string {
          return DrupalDateTime::createFromFormat(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $raw, DateTimeItemInterface::STORAGE_TIMEZONE)
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
        };
        $this->assertUnchangedFormMatchesStoredConfig($series, $displayed($rawStart), $displayed($rawEnd), $timezone);
      });
    }
  }

  /**
   * Asserts an unchanged submit is serialize-equal to the stored config.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The stored fixture series.
   * @param string $startDisplayDate
   *   The Y-m-d the editor's widget displays (and echoes back) for the start.
   * @param string $endDisplayDate
   *   Same for the end boundary.
   * @param string $timezone
   *   The acting editor's timezone, for assertion labels.
   */
  private function assertUnchangedFormMatchesStoredConfig(EventSeries $series, string $startDisplayDate, string $endDisplayDate, string $timezone): void {
    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $storage->resetCache([$series->id()]);
    $loaded = $storage->load($series->id());
    $this->assertInstanceOf(EventSeriesAccess::class, $loaded);

    // Fixture sanity: the display dates fed to the form below are the dates
    // the entity-side getters decode to — otherwise the equality assertions
    // would compare an edit nobody made.
    $this->assertSame($startDisplayDate, $loaded->getDailyStartDate()->format('Y-m-d'), "start decode under $timezone");
    $this->assertSame($endDisplayDate, $loaded->getDailyEndDate()->format('Y-m-d'), "end decode under $timezone");

    $userTz = new \DateTimeZone(date_default_timezone_get());

    // What an untouched widget submit delivers: the daily widget's value and
    // end_value are date-only datetime elements, so form_state carries the
    // displayed calendar date as a DrupalDateTime in the editor's zone
    // (convertFormConfigToArray() consumes only its user-zone Y-m-d); the
    // time/duration elements echo the stored config back verbatim.
    $formState = new FormState();
    $formState->setValues([
      'recur_type' => [['value' => 'daily_recurring_date']],
      'daily_recurring_date' => [
        0 => [
          'value' => DrupalDateTime::createFromFormat('Y-m-d H:i:s', $startDisplayDate . ' 00:00:00', $userTz),
          'end_value' => DrupalDateTime::createFromFormat('Y-m-d H:i:s', $endDisplayDate . ' 00:00:00', $userTz),
          'time' => $loaded->getDailyStartTime(),
          'end_time' => ['time' => $loaded->getDailyEndTime()],
          'duration' => $loaded->getDailyDuration(),
          'duration_or_end_time' => $loaded->getDailyDurationOrEndTime(),
        ],
      ],
    ]);

    /** @var \Drupal\recurring_events\EventCreationService $service */
    $service = \Drupal::service('recurring_events.event_creation_service');
    $entityConfig = $service->convertEntityConfigToArray($loaded);
    $formConfig = $service->convertFormConfigToArray($formState);

    // The load-bearing halves first, for a pinpoint failure: each boundary
    // date object must be bit-for-bit serialize-identical across the two
    // construction paths (different createFromFormat() formats and inputs
    // arriving at the same full object state), and the core internals the
    // equality rides on must be in the verified-unpopulated state.
    foreach (['start_date', 'end_date'] as $key) {
      $label = sprintf('%s under %s', $key, $timezone);
      $this->assertSame(serialize($entityConfig[$key]), serialize($formConfig[$key]), "entity vs form $label");
      $this->assertDateTimePlusInputPropsUnpopulated($entityConfig[$key], "entity $label");
      $this->assertDateTimePlusInputPropsUnpopulated($formConfig[$key], "form $label");
    }

    // The guard's exact mechanism, whole-config: lowercase-sorted
    // (array)-cast then serialize() — see checkForFormRecurConfigChanges().
    $this->assertSame(
      serialize(EventCreationService::convertArrayLowercaseSorted((array) $entityConfig)),
      serialize(EventCreationService::convertArrayLowercaseSorted((array) $formConfig)),
      sprintf('whole recurrence config under %s', $timezone)
    );

    // And the guard itself: FALSE means no confirm step, no recreation.
    $this->assertFalse(
      $service->checkForFormRecurConfigChanges($loaded, $formState),
      sprintf('unchanged form flagged as a recurrence change under %s', $timezone)
    );
  }

  /**
   * Asserts the six vestigial DateTimePlus input* props are all empty.
   *
   * Missing keys fail too: a rename in core would otherwise turn this canary
   * vacuous while the props re-enter the comparison under new names.
   */
  private function assertDateTimePlusInputPropsUnpopulated(DrupalDateTime $date, string $label): void {
    $cast = (array) $date;
    foreach (self::DATETIMEPLUS_INPUT_PROPS as $prop) {
      // Protected properties cast with the "\0*\0" key prefix.
      $key = "\0*\0" . $prop;
      $this->assertArrayHasKey($key, $cast, "$prop missing on $label");
      $this->assertSame('', $cast[$key], "$prop populated on $label");
    }
  }

}
