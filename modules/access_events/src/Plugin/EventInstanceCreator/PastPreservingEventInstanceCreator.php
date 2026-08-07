<?php

declare(strict_types=1);

namespace Drupal\access_events\Plugin\EventInstanceCreator;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventCreationService;
use Drupal\recurring_events\EventInstanceCreatorBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rebuilds a series' event instances without touching ended ones.
 *
 * Contrib's stock RecreateEventInstanceCreator (recurring_events_eventinstance_
 * recreator, the plugin this site's recurring_events.eventseries.config falls
 * back to when creator_plugin is null — see
 * EventInstanceCreatorPluginManager::createInstance()) reacts to a recur/date
 * config change by calling clearEventInstances() then createInstances(): every
 * instance of the series is deleted and recreated from scratch, PAST instances
 * included. A past instance can carry attendance history — post-event survey
 * state, a user's registration history, the organizer's roster — that a
 * config change (e.g. adding a future date) has no business destroying.
 * clearEventInstances() also invokes recurring_events_registration's
 * pre-instances-deletion hook series-wide, which emails registrants and then
 * unconditionally deletes their registrant rows.
 *
 * This plugin instead partitions the series' current instances by whether
 * they have ENDED (date.end_value <= now): ended instances are left alone
 * entirely — never loaded for deletion, never touched — and only NOT-yet-
 * ended instances are deleted and recreated. This is safe on the well-formed
 * date path because EventSeriesRescheduleBlockConstraint + the
 * access_events_eventseries_presave() backstop already refuse any
 * recur/date-config save while the series has FUTURE registrants (see
 * RegistrantCounter::countFutureForSeries(), which uses the same
 * end_value > now boundary — see hasEnded() for the one place that boundary
 * deliberately diverges, on the NULL/malformed edge cases, and why). So by
 * the time this plugin runs, every WELL-FORMED-DATE instance it is about to
 * delete is guaranteed registrant-free. That guarantee does NOT extend to
 * NULL or malformed end_value instances, since the counter's SQL comparison
 * cannot see them either — which is exactly why the belt below is
 * load-bearing, not decorative. As a belt, a future instance is checked for
 * registrants immediately before deletion; if one is unexpectedly found
 * (whether from a NULL/malformed date or a genuine boundary divergence), the
 * rebuild ABORTS instead of deleting it, on the theory that destroying a
 * registration silently is always worse than a failed rebuild.
 *
 * On the create side, EventCreationService::createInstances() computes the
 * full set of dates for the series' CURRENT config (both the 'custom' branch
 * and every rule-based recur type funnel through it) and fires the
 * recurring_events_event_instances_pre_create alter on that computed set
 * before creating anything — for BOTH branches. createInstances() is also
 * called directly (not via any EventInstanceCreator plugin) from
 * recurring_events_eventseries_insert()/_translation_insert(), i.e. on a
 * brand-new series — a context where past dates legitimately SHOULD
 * materialize (importing/creating an event whose date is already over is
 * valid; nothing is being "preserved" there because nothing existed yet).
 * The alter hook's parameters carry no caller identity, so
 * access_events_recurring_events_event_instances_pre_create_alter() would
 * otherwise filter past dates on every series creation too, silently
 * breaking that path. To scope the filter to ONLY this plugin's own
 * rebuild call, self::$rebuildingSeriesId holds the rebuilt series' id for
 * the duration of the createInstances() call below; the module alter checks
 * it via isRebuilding() and is a no-op for any other series or outside a
 * rebuild. This is the filtered-build approach, not
 * create-then-prune: nothing that would duplicate a preserved past instance
 * is ever created, so there is no transient duplicate to clean up
 * afterward.
 *
 * KNOWN LIMITATION: because past dates are filtered out of the creation set
 * entirely, newly ADDING a past date to a series' config (one that was not
 * already represented by a preserved instance) will not materialize an
 * instance. Only past dates that already have a surviving instance stay
 * represented; a brand-new past date is silently dropped. This is the
 * accepted tradeoff for never re-creating (and so never risking a duplicate
 * of) an existing past instance.
 *
 * PUBLISH SYNC: createInstances() spawns every new instance at 'draft' (the
 * editorial_eventinstance workflow's default), regardless of the series'
 * moderation_state. Contrib's own EventCreationService::updateInstanceStatus()
 * would normally mirror a new instance to its series' state, but it silently
 * no-ops whenever the series and instance workflows differ — which they do on
 * this site (editorial vs. editorial_eventinstance). Left alone, rebuilding a
 * PUBLISHED series would replace its visible occurrences with invisible
 * drafts. processInstances() below explicitly publishes the newly-created
 * instances when the series is published, and only then.
 *
 * @EventInstanceCreator(
 *   id = "access_events_past_preserving_recreator",
 *   description = @Translation("Recreate Event Instances (preserve past)")
 * )
 */
class PastPreservingEventInstanceCreator extends EventInstanceCreatorBase implements ContainerFactoryPluginInterface {

  /**
   * The id of the series actively being rebuilt by processInstances(), if any.
   *
   * Read via isRebuilding() by
   * access_events_recurring_events_event_instances_pre_create_alter() to scope
   * the past-date filter to this plugin's own rebuild call, for THIS series
   * specifically — see the class docblock. A static (not instance) property
   * because the module-level alter function has no reference to this plugin
   * instance, only to the EventSeries being processed; the plugin manager
   * also does not guarantee instance reuse across invocations. Private, with
   * a static reader, so nothing outside this class can set it. Keyed by
   * series id (not a bare bool) so a rebuild of series A can never cause the
   * alter to filter dates on an unrelated series B's createInstances() call —
   * synchronous PHP is single-threaded, but nothing rules out one save
   * triggering another series' rebuild via a hook reentry, and scoping by id
   * closes that hole for free. Synchronous, non-reentrant for a GIVEN series:
   * createInstances() does not itself trigger another processInstances() call
   * for the SAME series, so there is no same-series nesting to account for.
   */
  private static ?int $rebuildingSeriesId = NULL;

  /**
   * TRUE if $series is the one currently being rebuilt by processInstances().
   */
  public static function isRebuilding(EventSeries $series): bool {
    return self::$rebuildingSeriesId !== NULL && self::$rebuildingSeriesId === (int) $series->id();
  }

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EventCreationService $creation_service,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $creation_service);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('recurring_events.event_creation_service'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function processInstances(EventSeries $series) {
    $instances = $series->event_instances->referencedEntities();
    // Captured before anything is deleted or created, so the later publish
    // step can tell "created by THIS rebuild" apart from "already existed"
    // (a preserved past instance, or any instance this rebuild leaves alone).
    $preExistingIds = array_map(fn ($i) => (int) $i->id(), $instances);

    $future = [];
    foreach ($instances as $instance) {
      if (!$this->hasEnded($instance)) {
        $future[] = $instance;
      }
    }

    // Belt: every instance about to be deleted must be registrant-free — the
    // reschedule-block constraint + presave backstop only guarantee this for
    // the population they can see (future registrants at validate/presave
    // time). If a future instance somehow still carries a registrant here,
    // abort the whole rebuild rather than silently destroying it; the series
    // keeps its OLD instances (nothing was deleted yet).
    foreach ($future as $instance) {
      $count = (int) $this->entityTypeManager->getStorage('registrant')->getQuery()
        ->condition('eventinstance_id', $instance->id())
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      if ($count > 0) {
        throw new \RuntimeException(sprintf(
          'Refusing to rebuild event instances for series %d: future instance %d unexpectedly has %d registrant(s). The reschedule-block guard should have prevented this save.',
          $series->id(),
          $instance->id(),
          $count,
        ));
      }
    }

    // Delete only the future instances, directly — NOT clearEventInstances(),
    // whose recurring_events_save_pre_instances_deletion hook is series-scoped
    // and (via recurring_events_registration) emails-then-deletes every
    // registrant on the series, past instances' registrants included.
    foreach ($future as $instance) {
      $instance->delete();
    }

    // createInstances() reads the series' CURRENT config and fires
    // recurring_events_event_instances_pre_create on the computed date set
    // before creating anything; access_events filters that set down to
    // future-only dates while isRebuilding($series) is TRUE for THIS series
    // (see the module alter), so only future instances are (re)created here.
    // Past dates still present in config are filtered out, leaving the
    // preserved past instance as the sole representative of that date — no
    // duplicate is created. Reset in a finally so a thrown exception from
    // createInstances() never leaves the flag stuck on.
    self::$rebuildingSeriesId = (int) $series->id();
    try {
      $this->creationService->createInstances($series);
    }
    finally {
      self::$rebuildingSeriesId = NULL;
    }

    // createInstances() spawns new instances at 'draft' — the
    // editorial_eventinstance workflow's default state — regardless of the
    // series' own moderation_state. Contrib's updateInstanceStatus() would
    // normally sync a new instance to its series' state, but it no-ops here:
    // it only mirrors moderation_state when the series and instance share the
    // SAME workflow id, and on this site they use distinct workflows
    // (editorial for eventseries, editorial_eventinstance for eventinstance)
    // — see EventCreationService::updateInstanceStatus(). Left alone, a
    // rebuild of a PUBLISHED series would replace its visible occurrences
    // with invisible drafts: register()'s published-only gate refuses them,
    // and the "added" occurrence never appears to a visitor. Only a published
    // series' newly-created instances are published here — a rebuild of a
    // draft series must not publish instances the series itself never
    // exposed.
    if ($series->get('moderation_state')->value === 'published') {
      $reloaded = $this->entityTypeManager->getStorage('eventseries')->loadUnchanged($series->id());
      foreach ($reloaded->event_instances->referencedEntities() as $instance) {
        if (in_array((int) $instance->id(), $preExistingIds, TRUE)) {
          // Not created by this rebuild (a preserved past instance, or one
          // this rebuild left untouched) — its own moderation_state stands.
          continue;
        }
        if ($instance->get('moderation_state')->value !== 'published') {
          $instance->set('moderation_state', 'published')->save();
        }
      }
    }
  }

  /**
   * TRUE if the instance's date has already ended (end_value <= now).
   *
   * INVARIANT: the >=/<= split mirrors RegistrantCounter/CancellationNotifier
   * — end_value compared with getRequestTime(), strict on the "still future"
   * side — for every instance with a well-formed end_value. It deliberately
   * DIVERGES on the edge cases (NULL / malformed end_value), and that
   * divergence is exactly why the registrant belt in processInstances() is
   * load-bearing rather than decorative:
   *  - NULL end_value: RegistrantCounter::countFutureForSeries() runs
   *    `end_value > now` in SQL, which is FALSE for NULL — a registrant on a
   *    NULL-end instance is invisible to BOTH the reschedule-block constraint
   *    and the presave backstop, so a recur-config save is never blocked on
   *    their account. This method treats a NULL end_value as NOT ended (see
   *    below), so that instance is queued for deletion regardless — the
   *    belt's registrant check immediately before deletion is the ONLY thing
   *    that stops it from being destroyed silently.
   *  - Malformed end_value (strtotime() fails): treated as NOT ended too, for
   *    the same belt-relies-on-it reason — see below.
   * Diverging on the well-formed boundary itself (e.g. using >= where the
   * counter uses >) would let this plugin delete an instance the block/
   * backstop still considered future; that part must stay identical.
   */
  protected function hasEnded(EventInstance $instance): bool {
    $end = $instance->get('date')->end_value;
    if (!$end) {
      // No end date recorded: treat as NOT ended so it is NOT preserved by
      // default — it participates in the ordinary rebuild path, where the
      // belt (a registrant count immediately before deletion) is what
      // actually protects any registrant attached to it, since
      // RegistrantCounter's future-count query cannot see it either (see
      // above).
      return FALSE;
    }
    $timestamp = strtotime($end . ' UTC');
    if ($timestamp === FALSE) {
      // Malformed/unparseable end_value: explicitly treat as NOT ended
      // (rather than letting FALSE coerce to 0 and compare as "always
      // ended"), for the same reason as the NULL case above — an
      // unparseable date should NOT be silently trusted as "safe to
      // preserve without the belt's registrant check ever running against
      // it". Fall through to the ordinary rebuild path, where the belt is
      // what actually protects a registrant on it.
      return FALSE;
    }
    // date.end_value stores as 'Y-m-d\TH:i:s' UTC (no offset suffix).
    return $timestamp <= $this->time->getRequestTime();
  }

}
