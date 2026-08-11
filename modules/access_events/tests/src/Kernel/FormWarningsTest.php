<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\access_events\FormWarnings;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Tests the FormWarnings computation service: recipient counts and wording
 * for the leaving-published / arriving-published edge warnings, and the
 * gates-off string variants. Form rendering itself is live-verified, not
 * covered here.
 *
 * @coversDefaultClass \Drupal\access_events\FormWarnings
 * @group access_events
 */
class FormWarningsTest extends EventKernelTestBase {

  protected FormWarnings $formWarnings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->formWarnings = \Drupal::service('access_events.form_warnings');
  }

  /**
   * Leaving published on an instance counts only that instance's own
   * not-verifiably-past registrants, with notifications enabled.
   */
  public function testLeavingPublishedInstanceWarningCountsOwnRegistrantsOnly(): void {
    $this->enableEventNotifications();

    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r1'), $instance);
    $this->registerUser($this->createUser([], 'r2'), $instance);

    $warning = (string) $this->formWarnings->leavingPublishedWarning($instance);

    $this->assertStringContainsString('2 registrants', $warning);
    $this->assertStringContainsString('cancels the event', $warning);
  }

  /**
   * Leaving published on a series sums registrants across its PUBLISHED
   * instances only — an already-archived instance's registrants are NOT
   * counted, since a series-level cancel only archives its published,
   * not-past instances (see EventStateReactions::sweepCancel()); an instance
   * that is already archived does not transition on this save, so its
   * registrants are never enqueued for the cancellation notice.
   */
  public function testLeavingPublishedSeriesWarningExcludesArchivedInstanceRegistrants(): void {
    $this->enableEventNotifications();

    $published = $this->createRegistrableInstance();
    $series = $published->getEventSeries();
    $this->registerUser($this->createUser([], 'live'), $published);

    // A second, separately-archived instance on the SAME series, with its
    // own registrant. This instance will NOT be touched by a series-level
    // cancel (it is already off), so its registrant must be excluded from
    // the prospective-recipient count even though
    // RegistrantCounter::countNotPastForSeries() (a state-blind count used
    // elsewhere for the schedule-rebuild-destroys-registrants question) would
    // still see it.
    $archived = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'],
    ]);
    $archived->save();
    $archived->set('moderation_state', 'archived')->save();
    // registerUserOnDraftInstance(), not registerUser(): the instance is
    // archived (not published), and the registration-requires-published-
    // occurrence gate refuses a new registrant against a non-published
    // instance. This models the legitimate "registered while briefly live,
    // then pulled back" data shape without tripping that gate.
    $this->registerUserOnDraftInstance($this->createUser([], 'dark'), $archived);

    $warning = (string) $this->formWarnings->leavingPublishedWarning($series);

    // Only the published instance's registrant is a prospective recipient of
    // the cancellation email — the archived instance's registrant is
    // excluded, proving the scope narrowing this warning applies on top of
    // countNotPastForSeries()'s state-blind population.
    $this->assertStringContainsString('1 registrant.', $warning);
    $this->assertStringNotContainsString('2 registrants', $warning);
  }

  /**
   * Leaving published with the notification gate off states plainly that
   * registrants will NOT be emailed, rather than claiming a send.
   */
  public function testLeavingPublishedWarningGatesOffStatesNoEmail(): void {
    // enableEventNotifications() is deliberately NOT called — the default
    // kernel config leaves email_notifications unset/false.
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r1'), $instance);

    $warning = (string) $this->formWarnings->leavingPublishedWarning($instance);

    $this->assertStringContainsString('will NOT be emailed', $warning);
    $this->assertStringNotContainsString('registrants.', $warning);
  }

  /**
   * Arriving-published counts on a series: publishable = the effective
   * (future-only) creation-set size; skipped_past = how many of the
   * series' CURRENTLY scheduled instances already elapsed while the
   * series sat unpublished and so will not be part of that set.
   */
  public function testArrivingPublishedSeriesCountsSkippedPastElapsedDate(): void {
    $this->enableEventNotifications();

    // A custom series with two dates: one already elapsed, one future.
    // Left in DRAFT (never published) — its instances are born draft/dark
    // and the elapsed one demonstrates the skipped-past count.
    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);

    $counts = $this->formWarnings->arrivingPublishedCounts($series);

    $this->assertSame(1, $counts['publishable']);
    $this->assertSame(1, $counts['skipped_past']);
  }

  /**
   * Arriving-published on a series with no elapsed dates reports zero
   * skipped_past — this is the FIRST-publish path (a brand-new series,
   * never previously published), where every configured date is still in
   * the future.
   */
  public function testArrivingPublishedSeriesFirstPublishNoSkippedPast(): void {
    $this->enableEventNotifications();

    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);

    $counts = $this->formWarnings->arrivingPublishedCounts($series);

    $this->assertSame(2, $counts['publishable']);
    $this->assertSame(0, $counts['skipped_past']);
  }

  /**
   * Arriving-published recipients on a series count only the registrants a
   * series restore would actually email: those on archived, not-individually-
   * cancelled, not-past instances — the population sweepRestore() republishes.
   */
  public function testArrivingPublishedSeriesCountsRecipients(): void {
    $this->enableEventNotifications();

    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    // registerUserOnDraftInstance models a registrant sitting on the
    // instance regardless of its current moderation state, so the fixture
    // does not depend on the instance being live at registration time.
    $this->registerUserOnDraftInstance($this->createUser([], 'r1'), $instance);
    // The instance must be archived to be a restore recipient: a series
    // restore only republishes (and emails) instances it finds still archived
    // and not individually cancelled.
    $instance->set('moderation_state', 'archived')->save();

    $counts = $this->formWarnings->arrivingPublishedCounts($series);

    $this->assertSame(1, $counts['recipients']);
  }

  /**
   * A published instance is NOT counted as an arriving-published recipient: a
   * series restore only re-emails instances it republishes from archived, so
   * an already-live instance's registrant gets no reinstatement email and must
   * not inflate the count.
   */
  public function testArrivingPublishedSeriesExcludesPublishedInstanceRecipients(): void {
    $this->enableEventNotifications();

    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $this->registerUserOnDraftInstance($this->createUser([], 'r1'), $instance);
    // Instance stays in its published (live) state — not a restore recipient.

    $counts = $this->formWarnings->arrivingPublishedCounts($series);

    $this->assertSame(0, $counts['recipients']);
  }

  /**
   * An individually-cancelled archived instance is NOT counted as an
   * arriving-published recipient: a series restore skips flagged instances, so
   * their registrants never receive the reinstatement email.
   */
  public function testArrivingPublishedSeriesExcludesIndividuallyCancelledRecipients(): void {
    $this->enableEventNotifications();

    $instance = $this->createRegistrableInstance();
    $series = $instance->getEventSeries();
    $this->registerUserOnDraftInstance($this->createUser([], 'r1'), $instance);
    $instance->set('moderation_state', 'archived');
    $instance->set('individually_cancelled', TRUE);
    $instance->save();

    $counts = $this->formWarnings->arrivingPublishedCounts($series);

    $this->assertSame(0, $counts['recipients']);
  }

  /**
   * Arriving-published on a single occurrence: publishable is always 1
   * (one occurrence, not a series-wide set) and skipped_past is always 0
   * (an instance has no "skipped past dates" concept of its own).
   */
  public function testArrivingPublishedInstanceCountsAreSingular(): void {
    $this->enableEventNotifications();

    $instance = $this->createRegistrableInstance();
    $instance->set('moderation_state', 'archived')->save();

    $counts = $this->formWarnings->arrivingPublishedCounts($instance);

    $this->assertSame(1, $counts['publishable']);
    $this->assertSame(0, $counts['skipped_past']);
  }

  /**
   * Arriving-published wording on a series with skipped-past dates surfaces
   * the skipped count explicitly, not just the publishable count.
   */
  public function testArrivingPublishedSeriesWarningStringMentionsSkippedCount(): void {
    $this->enableEventNotifications();

    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00'],
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);

    $warning = (string) $this->formWarnings->arrivingPublishedWarning($series);

    $this->assertStringContainsString('republishes', $warning);
    $this->assertStringContainsString('1 already elapsed', $warning);
  }

  /**
   * Arriving-published with the notification gate off states plainly that
   * registrants will NOT be emailed, on both series and instance forms.
   */
  public function testArrivingPublishedWarningGatesOffStatesNoEmail(): void {
    // No enableEventNotifications() call — gate stays closed.
    $instance = $this->createRegistrableInstance();
    $instance->set('moderation_state', 'archived')->save();

    $warning = (string) $this->formWarnings->arrivingPublishedWarning($instance);

    $this->assertStringContainsString('would NOT be emailed', $warning);
  }

  /**
   * compute() on an unsaved series with no recurrence type set returns an
   * empty set rather than throwing — this is the add-form shape (a brand-new
   * EventSeries, recur_type not yet chosen), which previously reached
   * convertEntityConfigToArray()'s field-plugin lookup with an empty type
   * and threw a PluginNotFoundException.
   */
  public function testComputeOnUnsavedSeriesWithNoRecurTypeReturnsEmpty(): void {
    $series = EventSeries::create([
      'title' => 'Brand New Event',
      'type' => 'default',
    ]);

    $effectiveCreationSet = \Drupal::service('access_events.effective_creation_set');
    $this->assertSame([], $effectiveCreationSet->compute($series));
  }

  /**
   * compute() on a custom series carrying a custom_dates row with a NULL
   * start date returns the set WITHOUT that row and does not throw. Real
   * content violates the assumption every custom_dates row carries a start:
   * the date-list widget accepts empty/partial rows, so a saved series can
   * hold a row whose start_date is NULL — the shape that fataled the live
   * eventseries EDIT form. A row without a start date generates no occurrence
   * and is simply omitted; the valid row survives.
   */
  public function testComputeOnCustomSeriesWithNullStartRowOmitsRowDoesNotThrow(): void {
    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);

    // Append a malformed custom_date row with only an end value (no start) —
    // the exact production shape a partial widget row produces. Set in memory
    // (not re-saved): saving re-runs contrib's own instance creation, which is
    // itself unguarded against this row; compute() reads the live field values
    // off the entity, so setting them directly reproduces the read path the
    // EDIT form exercises.
    $series->set('custom_date', [
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ['value' => NULL, 'end_value' => '2999-03-01T12:00:00'],
    ]);

    $effectiveCreationSet = \Drupal::service('access_events.effective_creation_set');
    $result = $effectiveCreationSet->compute($series);

    // Only the well-formed row survives; the null-start row is omitted.
    $this->assertCount(1, $result);
    foreach ($result as $row) {
      $this->assertInstanceOf(DrupalDateTime::class, $row['start_date']);
      $this->assertInstanceOf(DrupalDateTime::class, $row['end_date']);
    }
  }

  /**
   * compute() on a custom series whose only custom_dates row has a NULL end
   * date omits that row too. The returned tuple must never carry a null
   * end_date downstream (filterFuture()/the flagged-date filter/contrib's
   * createInstances() all dereference both ends), so a row missing its end is
   * dropped at the single row-validity choke point rather than kept.
   */
  public function testComputeOnCustomSeriesWithNullEndRowOmitsRow(): void {
    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);

    $series->set('custom_date', [
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ['value' => '2999-04-01T10:00:00', 'end_value' => NULL],
    ]);

    $effectiveCreationSet = \Drupal::service('access_events.effective_creation_set');
    $result = $effectiveCreationSet->compute($series);

    $this->assertCount(1, $result);
    foreach ($result as $row) {
      $this->assertInstanceOf(DrupalDateTime::class, $row['end_date']);
    }
  }

  /**
   * compute() on a rule-based (weekly) series whose recurrence config is
   * missing returns [] and does not throw. A half-configured rule makes
   * contrib's WeeklyRecurringDate::calculateInstances() dereference an empty
   * days list / null start-end date range and throw a TypeError — an
   * unconfigured recurrence has no effective set for warning purposes, so
   * compute() fails safe to [] rather than fataling on the form.
   */
  public function testComputeOnWeeklySeriesWithMissingConfigReturnsEmptyDoesNotThrow(): void {
    // A weekly recur_type whose date range and days list were never populated
    // — the times are present but the start/end dates are null and days is
    // empty, the partial shape a half-filled recurrence form leaves behind.
    // The series is saved as custom first (so the insert hook does not try to
    // spawn rule instances from the partial config), then switched to weekly
    // in memory. WeeklyRecurringDate::calculateInstances() foreach-es the
    // empty days string and passes the null dates into its non-nullable
    // typehints, throwing — compute() must swallow that and return [].
    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);
    $series->set('recur_type', 'weekly_recurring_date');
    $series->set('custom_date', []);
    $series->set('weekly_recurring_date', [
      'value' => NULL,
      'end_value' => NULL,
      'time' => '10:00 AM',
      'end_time' => '11:00 AM',
      'duration' => 3600,
      'duration_or_end_time' => 'end_time',
      'days' => '',
    ]);

    $effectiveCreationSet = \Drupal::service('access_events.effective_creation_set');

    $this->assertSame([], $effectiveCreationSet->compute($series));
  }

  /**
   * compute() on a well-formed weekly series returns real date rows — the
   * regression guard proving the defensive wrapper around the rule-based
   * branch did not swallow valid rule computation.
   */
  public function testComputeOnValidWeeklySeriesReturnsRealRows(): void {
    $series = $this->makeValidWeeklySeries();

    $effectiveCreationSet = \Drupal::service('access_events.effective_creation_set');
    $result = $effectiveCreationSet->compute($series);

    $this->assertNotEmpty($result);
    foreach ($result as $row) {
      $this->assertInstanceOf(DrupalDateTime::class, $row['start_date']);
      $this->assertInstanceOf(DrupalDateTime::class, $row['end_date']);
    }
  }

  /**
   * arrivingPublishedCounts() on the same no-recur-type unsaved series does
   * not throw, and reports zero publishable dates.
   */
  public function testArrivingPublishedCountsOnUnsavedSeriesWithNoRecurTypeDoesNotThrow(): void {
    $series = EventSeries::create([
      'title' => 'Brand New Event',
      'type' => 'default',
    ]);

    $counts = $this->formWarnings->arrivingPublishedCounts($series);

    $this->assertSame(0, $counts['publishable']);
  }

  /**
   * The choosing-Draft-on-dark warning is a fixed, non-computed string.
   */
  public function testDraftOnDarkWarningText(): void {
    $warning = (string) $this->formWarnings->draftOnDarkWarning();

    $this->assertSame('This occurrence will no longer be republished by series restore.', $warning);
  }

  /**
   * The schedule-change disclosure fires when a future occurrence sits at a
   * date the series' current recur config would NOT produce — a directly-
   * added occurrence, in this case, added straight against the series
   * rather than via its custom_date config. EffectiveCreationSet::compute()
   * only reflects custom_date, so this instance's start is outside its
   * returned set.
   */
  public function testScheduleChangeWarningDetectsDivergedOccurrence(): void {
    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
    ]);

    // A second future instance on the SAME series, at a date that is not
    // (and never was) in the series' custom_date config — models a directly-
    // added occurrence a schedule rebuild would silently remove.
    $diverged = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'],
    ]);
    $diverged->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($series->id());
    $warning = $this->formWarnings->scheduleChangeWarning($reloaded);

    $this->assertNotNull($warning);
    $this->assertStringContainsString('regenerates', (string) $warning);
  }

  /**
   * The schedule-change disclosure does NOT fire when every future occurrence
   * matches the series' current recur config exactly, and none is
   * individually cancelled — a schedule rebuild would recreate the same
   * dates, so there is nothing to disclose.
   */
  public function testScheduleChangeWarningNullWhenNoDivergence(): void {
    $series = $this->makeUnpublishedCustomSeriesWithDates([
      ['value' => '2999-01-01T10:00:00', 'end_value' => '2999-01-01T12:00:00'],
      ['value' => '2999-02-01T10:00:00', 'end_value' => '2999-02-01T12:00:00'],
    ]);

    $warning = $this->formWarnings->scheduleChangeWarning($series);

    $this->assertNull($warning);
  }

  /**
   * A user holding only news_pm's grant set sees archived_archived among
   * the valid transitions on an already-archived instance — the logic half
   * of the standalone moderation widget's "Keep archived" option. Rendering
   * that option in the widget itself is live-verified, not covered here.
   */
  public function testNewsPmCanKeepArchivedInstanceArchived(): void {
    $instance = $this->createRegistrableInstance();
    $instance->set('moderation_state', 'archived')->save();

    $account = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);

    $validTransitions = \Drupal::service('content_moderation.state_transition_validation')
      ->getValidTransitions($instance, $account);

    $this->assertArrayHasKey('archived_archived', $validTransitions);
  }

  /**
   * Creates a DRAFT (never-published) custom eventseries with the given
   * custom_date ranges, leaving its spawned instances at their machinery-
   * assigned birth state (archived, since the series is not published — see
   * access_events_recurring_events_event_instance_alter()).
   *
   * @param array<array{value: string, end_value: string}> $dates
   */
  protected function makeUnpublishedCustomSeriesWithDates(array $dates): \Drupal\recurring_events\Entity\EventSeries {
    $series = \Drupal\recurring_events\Entity\EventSeries::create([
      'title' => 'Unpublished Multi-Date Event',
      'body' => 'A series with mixed past/future dates, never published.',
      'recur_type' => 'custom',
      'type' => 'default',
      'custom_date' => $dates,
    ]);
    // The insert hook spawns one instance per custom_date. Left in the
    // workflow's default (draft) state — this series has never been
    // published, modeling the FIRST-publish / long-dormant-series path.
    $series->save();

    return $series;
  }

  /**
   * Creates a saved weekly rule-based series with a VALID recurrence config.
   *
   * The regression fixture for the rule-based branch: a fully-populated
   * weekly_recurring_date over a one-week bounded window, whose
   * calculateInstances() produces real date rows. Mirrors
   * EventKernelTestBase::makeCoordinatorRuleSeries()'s date_format seeding —
   * a rule series' instance pipeline formats times via the core html_time /
   * html_date formats, which are not present in this minimal kernel env.
   */
  protected function makeValidWeeklySeries(): EventSeries {
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
      'title' => 'Valid Weekly Event',
      'body' => 'A rule-based weekly series with fully-populated config.',
      'recur_type' => 'weekly_recurring_date',
      'type' => 'default',
      'weekly_recurring_date' => [
        'value' => '2999-01-04T00:00:00',
        'end_value' => '2999-01-10T00:00:00',
        'time' => '10:00 AM',
        'end_time' => '11:00 AM',
        'duration' => 3600,
        'duration_or_end_time' => 'end_time',
        'days' => 'monday,wednesday',
      ],
    ]);
    $series->save();

    return $series;
  }

}
