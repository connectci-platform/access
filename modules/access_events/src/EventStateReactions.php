<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Reacts to eventseries/eventinstance moderation-state changes on save.
 *
 * A single orchestrated save can trigger a notification, a flag write, and
 * (for a disallowed transition) a refusal, all keyed off the same
 * original -> current moderation_state diff. This service is the one place
 * that diff is computed and acted on, so the presave/update hooks in
 * access_events.module stay thin delegations into here.
 *
 * Parent-state reads for the occurrence-publish-under-unpublished-event
 * refusal are deliberately loadUnchanged() + a binary isPublished() check —
 * never getEventSeries()/->entity, which can resolve a cached or
 * in-request-modified copy of the parent rather than its last-saved state.
 */
class EventStateReactions {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RegistrantCounter $registrantCounter,
    protected CancellationNotifier $notifier,
    protected StateChangeCollector $collector,
    protected TimeInterface $time,
  ) {}

  /**
   * The occurrence-publish-under-unpublished-event refusal + the
   * cancellation-email reaction's flag write, running at presave (before the
   * row is written).
   *
   * The occurrence-publish-under-unpublished-event refusal throws here (in
   * addition to the constraint) so a bare save that skips validate() is
   * refused too, not just editor form submissions.
   */
  public function instancePresave(EventInstance $entity): void {
    if (!$this->reacts($entity)) {
      return;
    }
    [$from, $to] = $this->transition($entity);

    if ($to === 'published' && $from !== 'published') {
      $this->assertParentPublished($entity);
    }

    if ($from === 'published' && $to !== 'published') {
      $seriesId = (int) $entity->get('eventseries_id')->target_id;
      if (!$this->collector->isSweeping($seriesId)) {
        $entity->set('individually_cancelled', TRUE);
      }
    }
  }

  /**
   * The cancellation-email and reinstatement notifications + outcome
   * recording, running after the row is saved.
   */
  public function instanceUpdate(EventInstance $entity): void {
    $this->collector->resetOwn('eventinstance', (int) $entity->id());

    if (!$this->reacts($entity)) {
      return;
    }
    [$from, $to] = $this->transition($entity);

    if ($from === 'published' && $to !== 'published') {
      $this->reactToInstanceCancelled($entity);
    }
    elseif ($to === 'published' && $from !== 'published') {
      $this->reactToInstanceReinstated($entity);
    }
    elseif ($from === 'published' && $to === 'published') {
      // Neither a cancel nor a reinstate — the only other case a live-to-live
      // resave needs to react to is a date move. See
      // reactToInstanceModified()'s own docblock for the reason this is a
      // separate branch rather than folded into the transition ifs above:
      // it isn't a transition at all, it's a same-state resave.
      $this->reactToInstanceModified($entity);
    }
  }

  /**
   * Series-level reactions: the non-published rebuild trigger, then the
   * cancel sweep / restore sweep.
   *
   * Order is load-bearing: resetOwn() first so a retried/rolled-back save's
   * stale accumulation never leaks into this save's outcomes; the rebuild
   * trigger next so a composed cancel+date-change save regenerates the
   * instance set BEFORE the sweep walks it (the sweep must see the new
   * instances, not the ones about to be replaced); the transition reactions
   * last.
   */
  public function seriesUpdate(EventSeries $entity): void {
    $seriesId = (int) $entity->id();
    $this->collector->resetOwn('eventseries', $seriesId);

    if (!$this->reacts($entity)) {
      return;
    }

    $this->maybeRebuildInstances($entity);

    [$from, $to] = $this->transition($entity);

    if ($from === 'published' && $to !== 'published') {
      $this->sweepCancel($entity);
    }
    elseif ($to === 'published' && $from !== 'published') {
      $this->sweepRestore($entity);
    }
  }

  /**
   * The non-published rebuild trigger.
   *
   * recurring_events' own eventseries_update() (access_events runs AFTER it
   * — see access_events_module_implements_alter()) already rebuilds a
   * series' instances on a recur/date-config change whenever the series
   * isPublished() OR is unmoderated. This is the complementary half: a
   * MODERATED series that is NOT published (draft, archived, needs_
   * adjustment) gets no rebuild from contrib at all, so a date change made
   * while cancelled/still-drafting would otherwise leave the old instance
   * set stale. Invokes the active EventInstanceCreator plugin directly
   * (access_events_recurring_events_event_instance_creator_plugin_alter()
   * swaps in the past-preserving plugin unconditionally), the same call
   * contrib's own hook makes.
   */
  private function maybeRebuildInstances(EventSeries $entity): void {
    if ($entity->isPublished()) {
      return;
    }
    /** @var \Drupal\recurring_events\EventCreationService $creationService */
    $creationService = \Drupal::service('recurring_events.event_creation_service');
    if (!$creationService->checkForOriginalRecurConfigChanges($entity, $entity->original)) {
      return;
    }
    $pluginManager = \Drupal::service('plugin.manager.event_instance_creator');
    $config = \Drupal::config('recurring_events.eventseries.config');
    $activePlugin = $pluginManager->createInstance($config->get('creator_plugin'), []);
    \Drupal::moduleHandler()->alter('recurring_events_event_instance_creator_plugin', $activePlugin, $pluginManager, $entity);
    $activePlugin->processInstances($entity);
  }

  /**
   * The cancel sweep: archives every published, not-verifiably-past instance
   * of the series.
   *
   * A fresh entity query by eventseries_id — never the computed
   * event_instances field, which can reflect a stale/in-request-modified set
   * rather than what is actually in storage right now (e.g. right after
   * maybeRebuildInstances() has just replaced it). beginSweep()/endSweep()
   * bracket the loop so each instance's own presave (instancePresave()) can
   * tell "this publish/archive is part of a series-wide sweep" apart from an
   * individual cancelOccurrence acting alone — see StateChangeCollector's
   * isSweeping() docblock. The finally guarantees the marker is cleared even
   * when an instance save throws mid-sweep, so a rolled-back cancel never
   * leaves a later, unrelated save mistaking itself for part of this sweep.
   */
  private function sweepCancel(EventSeries $entity): void {
    $seriesId = (int) $entity->id();
    $this->collector->beginSweep($seriesId);
    try {
      foreach ($this->publishedNotPastInstances($seriesId) as $instance) {
        $instance->setRevisionLogMessage('Archived by series cancellation.');
        $instance->set('moderation_state', 'archived');
        $instance->save();
      }
    }
    finally {
      $this->collector->endSweep();
    }
  }

  /**
   * The restore sweep: publishes every archived, unflagged,
   * not-verifiably-past instance.
   *
   * Plain saves — NEVER validate() before these saves. The needs_adjustment
   * → published transition instancePresave()/the occurrence-publish-
   * under-unpublished-event refusal relies on is a bare-save path
   * (validate() would run the content_moderation transition-access
   * constraint, which a plain series-restore sweep is not a user-initiated
   * moderation transition and has no business being gated by).
   */
  private function sweepRestore(EventSeries $entity): void {
    $seriesId = (int) $entity->id();
    $this->collector->beginSweep($seriesId);
    try {
      foreach ($this->archivedNotPastInstances($seriesId) as $instance) {
        if ((bool) $instance->get('individually_cancelled')->value) {
          continue;
        }
        $instance->setRevisionLogMessage('Published by series restore.');
        $instance->set('moderation_state', 'published');
        $instance->save();
      }
    }
    finally {
      $this->collector->endSweep();
    }
  }

  /**
   * Loads the series' default-revision, published, not-verifiably-past
   * instances via a fresh entity query.
   *
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   */
  private function publishedNotPastInstances(int $seriesId): array {
    return $this->notPastInstancesInState($seriesId, 'published');
  }

  /**
   * Loads the series' default-revision, archived, not-verifiably-past
   * instances via a fresh entity query.
   *
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   */
  private function archivedNotPastInstances(int $seriesId): array {
    return $this->notPastInstancesInState($seriesId, 'archived');
  }

  /**
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   */
  private function notPastInstancesInState(int $seriesId, string $state): array {
    // moderation_state is a content_moderation COMPUTED field (sourced from a
    // separate content_moderation_state revision entity, never stored on the
    // eventinstance base table), so it cannot appear in an entity-query
    // condition — only eventseries_id (a real base field) can. Filter by
    // state and by not-verifiably-past in PHP after loading.
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    $ids = $storage->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return [];
    }
    $now = $this->time->getRequestTime();
    $instances = [];
    foreach ($storage->loadMultiple($ids) as $instance) {
      if ($instance->get('moderation_state')->value !== $state) {
        continue;
      }
      $end = $instance->get('date')->end_value;
      if (RegistrantCounter::endIsNotVerifiablyPast($end, $now)) {
        $instances[] = $instance;
      }
    }
    return $instances;
  }

  /**
   * The cancellation-email reaction: enqueues the cancellation notice and
   * records the outcome.
   */
  private function reactToInstanceCancelled(EventInstance $entity): void {
    $instanceId = (int) $entity->id();
    $seriesId = (int) $entity->get('eventseries_id')->target_id;
    $hasRecipients = $this->registrantCounter->countNotPastForInstance($instanceId) > 0;

    $notified = $this->notifier->enqueueGated($entity, CancellationNotifier::KEY);

    foreach ([['eventinstance', $instanceId], ['eventseries', $seriesId]] as [$type, $id]) {
      $this->collector->record($type, $id, 'notified', $notified);
      $this->collector->record($type, $id, 'instances_archived');
      if ($notified === 0 && $hasRecipients) {
        $this->collector->flag($type, $id, 'notifications_disabled');
      }
    }
  }

  /**
   * The reinstatement reaction: enqueues the reinstatement notice and
   * records the outcome.
   */
  private function reactToInstanceReinstated(EventInstance $entity): void {
    $instanceId = (int) $entity->id();
    $seriesId = (int) $entity->get('eventseries_id')->target_id;

    $notified = $this->notifier->enqueueGated($entity, CancellationNotifier::REINSTATE_KEY);

    foreach ([['eventinstance', $instanceId], ['eventseries', $seriesId]] as [$type, $id]) {
      $this->collector->record($type, $id, 'notified', $notified);
      $this->collector->record($type, $id, 'instances_published');
    }
  }

  /**
   * The reschedule-notice reaction: enqueues a reschedule notice when a LIVE
   * occurrence's date moves.
   *
   * This is the module's replacement for contrib's own
   * recurring_events_registration_entity_update() (unimplemented via
   * access_events_module_implements_alter()), which notified on ANY
   * eventinstance save with a changed date field and a future NEW end date —
   * no published check, no default-revision check, nothing stopping a date
   * edit on a still-drafting or already-cancelled occurrence from emailing
   * registrants about an event they cannot see. This method only reacts when
   * ALL of:
   *  - the saved revision is the default one, not syncing, not a translation
   *    (reacts() above already gates instanceUpdate() as a whole on this);
   *  - both the before AND after moderation_state are 'published' — a
   *    published->published resave is exactly a live-to-live content edit,
   *    never a transition, which is why this lives in instanceUpdate()'s
   *    from===to==='published' branch rather than alongside the cancel/
   *    reinstate transition ifs;
   *  - the date field actually changed (contrib's own field-value compare,
   *    reused so what counts as "changed" stays identical to before).
   * An archived->published resave with a date change (a restore that also
   * moves the date in one save) does NOT reach this method at all — it lands
   * in reactToInstanceReinstated() instead, by construction of the from/to
   * branches in instanceUpdate() above, so a restore never double-fires both
   * the reinstatement AND a modification notice for the same save.
   */
  private function reactToInstanceModified(EventInstance $entity): void {
    if (serialize($entity->get('date')->getValue()) === serialize($entity->original->get('date')->getValue())) {
      return;
    }

    $instanceId = (int) $entity->id();
    $seriesId = (int) $entity->get('eventseries_id')->target_id;
    $oldEnd = $entity->original->get('date')->end_value;
    $newEnd = $entity->get('date')->end_value;

    $notified = $this->notifier->enqueueModificationGated($entity, $oldEnd, $newEnd);

    foreach ([['eventinstance', $instanceId], ['eventseries', $seriesId]] as [$type, $id]) {
      $this->collector->record($type, $id, 'notified', $notified);
    }
  }

  /**
   * The registration-requires-published-occurrence gate: whether a NEW
   * registrant may be created against this instance.
   *
   * Loaded fresh via storage (never a possibly-forward in-memory copy), and
   * checked as a literal moderation_state string compare — mirroring
   * EventDetailApiController::register()'s own Guard 2b exactly, NOT
   * assertParentPublished()'s binary isPublished(). isPublished() reads the
   * base `status` field, which content_moderation's EntityOperations::
   * entityPresave() only syncs from moderation_state when moderation_state
   * was explicitly set on that save — an instance that has a workflow
   * attached but was created without ever assigning moderation_state (a real
   * shape: RegistrationApiTest's draft-instance fixture is exactly this)
   * would otherwise read isPublished() === TRUE off the field's raw default,
   * which is wrong for an instance still sitting in its unassigned initial
   * state. The literal string compare has no such staleness risk. A bundle
   * with NO moderation_state field at all (no content moderation workflow
   * attached — RegistrationApiTest's plain KernelTestBase fixtures without a
   * workflow attached are this) has no draft concept, so it is treated as
   * always registrable — also matching Guard 2b, which only refuses when the
   * instance DOES carry moderation_state and it is NOT published, never when
   * the field is simply absent.
   *
   * @param int $instanceId
   *   The eventinstance entity id from the registrant's eventinstance_id
   *   reference.
   *
   * @return bool
   *   TRUE if a new registrant may be created against this instance.
   */
  public function instanceIsRegistrable(int $instanceId): bool {
    $instance = $this->entityTypeManager->getStorage('eventinstance')->loadUnchanged($instanceId);
    if (!$instance instanceof EventInstance) {
      return FALSE;
    }
    if (!$instance->hasField('moderation_state')) {
      return TRUE;
    }
    return $instance->get('moderation_state')->value === 'published';
  }

  /**
   * The occurrence-publish-under-unpublished-event refusal: refuses
   * publishing an occurrence while its parent series is not published
   * (binary isPublished(), never a string state compare).
   */
  private function assertParentPublished(EventInstance $entity): void {
    $seriesId = (int) $entity->get('eventseries_id')->target_id;
    $series = $this->entityTypeManager->getStorage('eventseries')->loadUnchanged($seriesId);
    if ($series instanceof EventSeries && !$series->isPublished()) {
      throw new \RuntimeException('This occurrence cannot be published while the event itself is not published. Restore the event (which republishes its occurrences), or publish the event first.');
    }
  }

  /**
   * Whether this entity's save is one this service should react to at all.
   *
   * Skips non-default revisions/translations, syncing saves (reverts,
   * rebuilds), and insert paths (no ->original to diff against).
   */
  private function reacts(ContentEntityInterface $entity): bool {
    return $entity->isDefaultRevision() && $entity->isDefaultTranslation()
      && !$entity->isSyncing() && isset($entity->original);
  }

  /**
   * The [from, to] moderation_state pair for this save.
   *
   * @return array{0: string, 1: string}
   */
  private function transition(ContentEntityInterface $entity): array {
    return [$entity->original->get('moderation_state')->value, $entity->get('moderation_state')->value];
  }

}
