<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Anchor-aware prefill of the recurrence date widgets.
 *
 * The contrib recurrence widgets prefill their date sub-elements from the
 * stored instant (core converts it into the editor's zone), so an anchored
 * boundary (`{date}T12:00:00` UTC) renders as NEXT-DAY for a UTC+12..+14
 * editor — and re-submitting that rendered date would either trip the
 * reschedule guard (registered series) or ratchet the stored boundary one
 * day per save. The access_events widget alter fixes the prefill READ: when
 * the raw column value carries the anchor signature, the date sub-element's
 * default becomes the literal stored date in the editor's zone. Wall-clock
 * (unmigrated legacy) rows keep contrib's stock prefill untouched.
 *
 * The elements under test are built by driving the REAL widget plugin
 * through WidgetBase::form() (formSingleElement() → the field-widget alter
 * hooks), so these tests exercise the shipped alter exactly as an entity
 * form build would — including the hook names' binding to the five contrib
 * plugin ids — without form-building the whole eventseries form (see
 * EventDeleteGuardFormAlterTest for why the suite avoids that).
 *
 * @group access_events
 */
class RecurBoundaryWidgetPrefillTest extends EventKernelTestBase {

  use RecurBoundaryFixtureTrait;

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
   * Runs $callable as a user in $timezone with $roles (mirrors a request).
   *
   * The account is switched (as asActingUser does) AND PHP's default
   * timezone follows the user's pref, because the widget element's
   * #date_timezone, the prefill alter, and preSave's recovery all resolve
   * the zone via date_default_timezone_get().
   */
  private function asSaverIn(string $timezone, callable $callable, array $roles = []) {
    $saver = $this->createUser([], NULL, FALSE, [
      'timezone' => $timezone,
      'roles' => $roles,
    ]);
    return $this->asActingUser($saver, function () use ($timezone, $callable) {
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
   * Reads the stored bytes of a boundary pair straight from the data table.
   *
   * @return array{0: string|null, 1: string|null}
   *   [{field}__value, {field}__end_value] of the DEFAULT revision.
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
   * A PUBLISHED weekly-rule series whose boundary pair is deterministically
   * anchored `2999-01-04T12:00:00` / `2999-01-10T12:00:00`.
   *
   * The anchor is planted through a real entity save of bare `YYYY-MM-DD`
   * values — bare dates anchor LITERALLY in every saver zone, so the fixture
   * bytes do not depend on the phpunit environment's ambient timezone.
   */
  private function makePublishedAnchoredSeries(): EventSeries {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);

    $item = $series->get('weekly_recurring_date')->first();
    $item->set('value', '2999-01-04');
    $item->set('end_value', '2999-01-10');
    $series->save();
    $series->set('moderation_state', 'published')->save();

    $this->assertSame(
      ['2999-01-04T12:00:00', '2999-01-10T12:00:00'],
      $this->storedPair((int) $series->id(), 'weekly_recurring_date'),
      'fixture: the stored boundary pair is anchored on the intended dates',
    );
    return $series;
  }

  /**
   * Attaches a published registrable instance + one registrant to $series.
   *
   * Same pattern as EventSeriesRescheduleBlockTest: the registrable instance
   * is built by the base helper and repointed at the target series, so
   * countNotPastForSeries() sees a future registrant on it.
   */
  private function attachRegistrant(EventSeries $series): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('eventseries_id', $series->id())->save();
    $this->registerUser($this->createUser(), $instance);
  }

  /**
   * Asserts zero violations, naming any that were raised.
   *
   * One known artifact is excluded: contrib defines the computed
   * event_instances reference with no explicit cardinality (so it defaults
   * to 1), and core's Count constraint therefore flags ANY series holding
   * two or more instances at validate(). That fires regardless of what the
   * boundary columns hold, so it is orthogonal to the prefill under test;
   * every other violation (reschedule-block, moderation, field-level) still
   * fails the assertion.
   */
  private function assertViolationFree($violations, string $message): void {
    $raised = [];
    foreach ($violations as $violation) {
      if ($violation->getPropertyPath() === 'event_instances') {
        continue;
      }
      $raised[] = $violation->getPropertyPath() . ': ' . (string) $violation->getMessage();
    }
    $this->assertSame([], $raised, $message);
  }

  /**
   * Builds the delta-0 widget element for $field through WidgetBase::form().
   *
   * Instantiates the real contrib widget plugin (plugin id == field name for
   * all five recurrence widgets) and drives its form() on a FRESHLY loaded
   * series' item list, which runs formSingleElement() and with it BOTH
   * field_widget_single_element alter hooks — the shipped prefill alter
   * included. The fresh load matters twice over: the item list's computed
   * start_date/end_date objects are shared and createDefaultValue() mutates
   * them, and the entity cache may hold pre-setRawBoundary values.
   */
  private function buildRecurWidgetElement(int $seriesId, string $field = 'weekly_recurring_date'): array {
    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $storage->resetCache([$seriesId]);
    $items = $storage->load($seriesId)->get($field);

    $widget = \Drupal::service('plugin.manager.field.widget')->createInstance($field, [
      'field_definition' => $items->getFieldDefinition(),
      'settings' => [],
      'third_party_settings' => [],
    ]);

    $form = ['#parents' => []];
    $complete = $widget->form($items, $form, new FormState());
    $this->assertArrayHasKey(0, $complete['widget']);
    return $complete['widget'][0];
  }

  /**
   * Same element, built by calling the widget's formElement() DIRECTLY.
   *
   * formElement() is what formSingleElement() wraps; calling it without the
   * wrapper skips the field-widget alter hooks, yielding the genuine
   * stock-contrib element the alter would have received — the unaltered
   * baseline the wall-clock test compares against.
   */
  private function buildStockBaselineElement(int $seriesId, string $field = 'weekly_recurring_date'): array {
    $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
    $storage->resetCache([$seriesId]);
    $items = $storage->load($seriesId)->get($field);

    $widget = \Drupal::service('plugin.manager.field.widget')->createInstance($field, [
      'field_definition' => $items->getFieldDefinition(),
      'settings' => [],
      'third_party_settings' => [],
    ]);

    $form = ['#parents' => []];
    $element = [
      '#field_parents' => [],
      '#required' => FALSE,
      '#delta' => 0,
      '#weight' => 0,
    ];
    return $widget->formElement($items, 0, $element, $form, new FormState());
  }

  /**
   * Formats a widget date sub-element's default for comparison.
   */
  private function defaultValueSignature(array $element, string $key): string {
    $default = $element[$key]['#default_value'] ?? NULL;
    $this->assertInstanceOf(DrupalDateTime::class, $default, "$key has a datetime default");
    return $default->format('Y-m-d H:i:s') . ' ' . $default->getTimezone()->getName();
  }

  /**
   * An anchored row prefills the LITERAL stored date in every editor zone.
   *
   * Stored `2999-01-04T12:00:00` is 07:00 Jan 4 in New York but already
   * 01:00 Jan 5 in Auckland (NZDT, UTC+13) — stock instant conversion
   * renders next-day for the far-east editor. The alter must hand both
   * editors the date the row actually stores.
   */
  public function testAnchoredRowPrefillsLiteralDateInEveryTimezone(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);
    $this->setRawBoundary($series, 'weekly_recurring_date', '2999-01-04T12:00:00', '2999-01-10T12:00:00');
    $id = (int) $series->id();

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      $element = $this->asSaverIn($timezone, fn () => $this->buildRecurWidgetElement($id));

      $start = $element['value']['#default_value'] ?? NULL;
      $end = $element['end_value']['#default_value'] ?? NULL;
      $this->assertInstanceOf(DrupalDateTime::class, $start);
      $this->assertInstanceOf(DrupalDateTime::class, $end);
      $this->assertSame('2999-01-04', $start->format('Y-m-d'), "start date renders literally under $timezone");
      $this->assertSame('2999-01-10', $end->format('Y-m-d'), "end date renders literally under $timezone");
      // The default must live in the editor's zone: the datetime element
      // formats it via #date_timezone, and a zone mismatch would re-shift
      // the very date the literal prefill just pinned.
      $this->assertSame($timezone, $start->getTimezone()->getName());
      $this->assertSame($timezone, $end->getTimezone()->getName());
    }
  }

  /**
   * A wall-clock (unmigrated legacy) row keeps contrib's stock prefill.
   *
   * The planted pair `2999-01-05T02:00:00` / `2999-01-11T02:00:00` is what a
   * NY editor's 21:00 EST save of Jan 4 / Jan 10 stored. It carries no
   * anchor signature, so the shipped alter must leave the element exactly as
   * stock contrib built it — compared here against a genuine unaltered
   * baseline (formElement() called without the alter-hook wrapper), in a
   * near zone and a far-east zone.
   */
  public function testWallClockRowKeepsStockPrefill(): void {
    $coordinator = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $series = $this->makeCoordinatorRuleSeries($coordinator);
    $this->setRawBoundary($series, 'weekly_recurring_date', '2999-01-05T02:00:00', '2999-01-11T02:00:00');
    $id = (int) $series->id();

    foreach (['America/New_York', 'Pacific/Auckland'] as $timezone) {
      [$altered, $baseline] = $this->asSaverIn($timezone, fn () => [
        $this->buildRecurWidgetElement($id),
        $this->buildStockBaselineElement($id),
      ]);

      foreach (['value', 'end_value'] as $key) {
        $this->assertSame(
          $this->defaultValueSignature($baseline, $key),
          $this->defaultValueSignature($altered, $key),
          "$key keeps the stock prefill for a wall-clock row under $timezone",
        );
      }
    }

    // Pin the baseline itself so the comparison can't pass vacuously: stock
    // semantics convert the stored UTC instant into the editor's zone —
    // 02:00 UTC is 21:00 the previous evening in New York (EST, UTC-5).
    $baseline = $this->asSaverIn('America/New_York', fn () => $this->buildStockBaselineElement($id));
    $this->assertSame(
      '2999-01-04 21:00:00 America/New_York',
      $this->defaultValueSignature($baseline, 'value'),
    );
  }

  /**
   * Far-east round trip THROUGH the shipped alter is a byte-level no-op.
   *
   * An Auckland editor opens a REGISTERED anchored series and saves without
   * touching the dates. The widget re-submits whatever the prefill rendered:
   * per core Datetime::valueCallback() a date-only element (#date_time_element
   * 'none') posts just the date string, which parses in the editor's zone at
   * the submit-moment wall clock; DateRangeWidgetBase::massageFormValues()
   * then converts that instant to a UTC storage string. With the literal
   * prefill, preSave's recovery inverts that conversion back to the SAME
   * calendar date and re-anchors — stored bytes unchanged, no reschedule
   * refusal. Without it, the rendered next-day date either ratchets the
   * boundary or (registered, as here) refuses the save outright.
   */
  public function testFarEastResubmitThroughAlterKeepsAnchorBytes(): void {
    $series = $this->makePublishedAnchoredSeries();
    $this->attachRegistrant($series);
    $id = (int) $series->id();

    $violations = $this->asSaverIn('Pacific/Auckland', function () use ($id) {
      $element = $this->buildRecurWidgetElement($id);

      // Simulate the widget's re-submission of its own prefill: the date-only
      // input posts $default->format(#date_date_format) and no time, so the
      // element's value object is the posted date at the current wall clock
      // in #date_timezone (Datetime::valueCallback), which massageFormValues
      // stores as a UTC Y-m-d\TH:i:s string.
      $submitted = [];
      foreach (['value', 'end_value'] as $key) {
        $default = $element[$key]['#default_value'];
        $this->assertInstanceOf(DrupalDateTime::class, $default);
        $posted = $default->format('Y-m-d');
        $submitted[$key] = DrupalDateTime::createFromFormat('Y-m-d', $posted, 'Pacific/Auckland')
          ->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
          ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
      }

      $storage = \Drupal::entityTypeManager()->getStorage('eventseries');
      $storage->resetCache([$id]);
      $loaded = $storage->load($id);
      $item = $loaded->get('weekly_recurring_date')->first();
      $item->set('value', $submitted['value']);
      $item->set('end_value', $submitted['end_value']);
      $violations = $loaded->validate();
      // A next-day prefill would also make this save throw (the presave
      // mirror refuses recur changes on a registered series), so reaching
      // the assertions below is itself part of the pin.
      $loaded->save();
      return $violations;
    }, ['news_pm']);

    $this->assertViolationFree($violations, 'no reschedule-block (or any other) violation for an untouched far-east resubmit');
    $this->assertSame(
      ['2999-01-04T12:00:00', '2999-01-10T12:00:00'],
      $this->storedPair($id, 'weekly_recurring_date'),
      'the stored anchors survive the Auckland round trip byte-for-byte',
    );
  }

}
