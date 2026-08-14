<?php

namespace Drupal\access_events\Controller;

use Drupal\access_affinitygroup\Access\CoordinatorAccess;
use Drupal\access_events\EventAccessHelper;
use Drupal\access_events\EventDeleteGuard;
use Drupal\access_events\RegistrantCounter;
use Drupal\access_events\StateChangeCollector;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for eventseries create/update/delete over the acting user.
 *
 * These endpoints run AS the acting user (the ActingUserSwitchSubscriber has
 * switched the account by the time the controller runs), so entity access
 * enforces the acting user's own permissions.
 *
 * SECURITY — draft-only: create HARDCODES moderation_state='draft' and ignores
 * any caller-supplied moderation_state. There is no self-publish path; the
 * response instead carries a review-needed signal (see buildModerationBlock)
 * telling the caller whether the acting user may publish directly or must send
 * the draft for editor review. That signal is derived entirely from the user's
 * valid workflow transitions — never a hardcoded role/permission string.
 */
class EventCrudApiController extends ControllerBase {

  /**
   * The whitelisted content fields the create endpoint copies from the body.
   *
   * moderation_state / status are deliberately absent: create locks the series
   * to draft. body is handled separately so its text format is pinned.
   */
  private const CONTENT_ATTRIBUTES = [
    'field_summary',
    'field_location',
    'field_event_type',
    'field_skill_level',
    'field_tags',
    'field_event_speakers',
    'field_event_virtual_meeting_link',
    'domain_access',
  ];

  /**
   * The events authz helper.
   *
   * @var \Drupal\access_events\EventAccessHelper
   */
  protected EventAccessHelper $accessHelper;

  /**
   * The shared coordinator-membership access check.
   *
   * @var \Drupal\access_affinitygroup\Access\CoordinatorAccess
   */
  protected CoordinatorAccess $coordinatorAccess;

  /**
   * The registrant counter.
   *
   * @var \Drupal\access_events\RegistrantCounter
   */
  protected RegistrantCounter $registrantCounter;

  /**
   * The content-moderation transition validator.
   *
   * @var \Drupal\content_moderation\StateTransitionValidationInterface
   */
  protected StateTransitionValidationInterface $transitionValidation;

  /**
   * The content-moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected ModerationInformationInterface $moderationInformation;

  /**
   * The per-request outcome collector state reactions record into.
   *
   * Reactions running off the series/instance save (access_events.state_
   * reactions, wired to the moderation-state-change hooks) record what
   * happened — instances archived/published, notifications sent — keyed by
   * entity type and id. The controller drains it once after the save to
   * build the response envelope; it never orchestrates the side effects
   * itself.
   *
   * @var \Drupal\access_events\StateChangeCollector
   */
  protected StateChangeCollector $stateChangeCollector;

  /**
   * The time service — for gating editOccurrence's confirm on the
   * prospective new date, ahead of the actual save that decides notification.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The single place the has-registrations delete rule is decided.
   *
   * @var \Drupal\access_events\EventDeleteGuard
   */
  protected EventDeleteGuard $deleteGuard;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EventAccessHelper $access_helper,
    CoordinatorAccess $coordinator_access,
    RegistrantCounter $registrant_counter,
    EntityTypeManagerInterface $entity_type_manager,
    StateTransitionValidationInterface $transition_validation,
    ModerationInformationInterface $moderation_information,
    StateChangeCollector $state_change_collector,
    TimeInterface $time,
    EventDeleteGuard $delete_guard,
  ) {
    $this->accessHelper = $access_helper;
    $this->coordinatorAccess = $coordinator_access;
    $this->registrantCounter = $registrant_counter;
    $this->entityTypeManager = $entity_type_manager;
    $this->transitionValidation = $transition_validation;
    $this->moderationInformation = $moderation_information;
    $this->stateChangeCollector = $state_change_collector;
    $this->time = $time;
    $this->deleteGuard = $delete_guard;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('access_events.access_helper'),
      $container->get('access_affinitygroup.coordinator_access'),
      $container->get('access_events.registrant_counter'),
      $container->get('entity_type.manager'),
      $container->get('content_moderation.state_transition_validation'),
      $container->get('content_moderation.moderation_information'),
      $container->get('access_events.state_change_collector'),
      $container->get('datetime.time'),
      $container->get('access_events.event_delete_guard'),
    );
  }

  /**
   * POST /api/2.3/events — create a draft eventseries.
   *
   * Gated by a coordinator check against the REQUESTED affinity groups (before
   * the series exists there is no saved entity to run userMayManageSeries on).
   * Always creates moderation_state = draft; the caller cannot self-publish.
   * The series insert hook auto-spawns one instance per custom date.
   *
   * Named createEvent (not create) because ControllerBase::create() is the
   * static service factory and cannot be overridden by an instance method.
   */
  public function createEvent(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }
    $user = $this->currentUser();
    $body = json_decode($request->getContent(), TRUE) ?: [];

    if (empty($body['title'])) {
      return $this->refuse('validation_error', 'title is required.', 422);
    }
    if (empty($body['recur_type'])) {
      return $this->refuse('validation_error', 'recur_type is required.', 422);
    }

    // Affinity group is OPTIONAL. A group is only supplied when the creator
    // wants to publish the event to it, and coordinator status is required only
    // for supplied groups. Distinguish "requested" from "resolved" so a bad
    // UUID is rejected rather than silently creating a group-less event.
    $rawGroups = $body['field_affinity_group_node'] ?? [];
    if (!is_array($rawGroups)) {
      $rawGroups = ($rawGroups === NULL || $rawGroups === '') ? [] : [$rawGroups];
    }
    // Same per-element predicate resolveGroupNodes uses.
    $requested = array_filter($rawGroups, fn ($u) => is_string($u) && $u !== '');
    $groupNodes = $this->resolveGroupNodes($rawGroups);
    if (!empty($requested) && empty($groupNodes)) {
      return $this->refuse('validation_error', 'One or more affinity groups could not be found.', 422);
    }

    $values = [
      'type' => 'default',
      'uid' => $uid,
      // Draft-only, hardcoded. Any caller moderation_state is ignored.
      'moderation_state' => 'draft',
      'title' => $body['title'],
      'recur_type' => $body['recur_type'],
    ];
    // Only write the group field when a group actually resolved; never an empty
    // array.
    if (!empty($groupNodes)) {
      $values['field_affinity_group_node'] = array_map(fn (NodeInterface $n) => $n->id(), $groupNodes);
    }
    // Maps the API custom_dates param to the entity custom_date field, or the
    // matching *_recurring_date field for a rule recur_type.
    $this->applyRecurDates($values, $body);
    // Copies the whitelisted content fields (body, field_summary, …).
    $this->applyContentFields($values, $body);

    // Coordinator gate — ONLY when group(s) were supplied. No group means no
    // group to coordinate, so no check (userCoordinatesAllGroups returns a
    // vacuous TRUE on an empty array and must not be called unguarded). A
    // supplied group still requires the caller coordinate all of them.
    if (!empty($groupNodes) && !$this->coordinatorAccess->userCoordinatesAllGroups($user, $groupNodes)) {
      return $this->refuse('not_coordinator', 'You are not a coordinator of the selected affinity group(s).', 409);
    }

    $series = $this->entityTypeManager->getStorage('eventseries')->create($values);

    // Entity-type-level create permission (governed by 'add eventseries
    // entity', which the acting-user route gate + authenticated role cover).
    if (!$series->access('create', $user, TRUE)->isAllowed()) {
      return $this->refuse('forbidden', 'You may not create events.', 403);
    }
    // The insert hook auto-spawns instances from the recur dates.
    $series->save();

    $instanceIds = array_map(
      fn ($i) => (int) $i->id(),
      $series->get('event_instances')->referencedEntities(),
    );

    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'instance_ids' => $instanceIds,
      'title' => $series->label(),
      'moderation_state' => $series->get('moderation_state')->value,
      'moderation' => $this->buildModerationBlock($series),
    ]);
  }

  /**
   * PATCH /api/2.3/event-series/{eventseries} — edit a series' content fields.
   *
   * CONTENT-ONLY: writes only the whitelisted content fields (body,
   * field_summary, …) plus title. It never writes moderation_state (a
   * transition op, gated separately) and never touches recurrence config —
   * the API's schedule surface is per-occurrence only (edit_occurrence,
   * add_occurrence, cancel_occurrence); an unregistered pattern-wide date
   * change goes through the browser form instead. Any caller-supplied
   * moderation_state is ignored. This matters for authorization: a coordinator
   * authorized via the affinity-group grant may lack a moderation transition, so
   * a content-only save (which never invokes transition validation) succeeds for
   * them — writing moderation_state here would make that same coordinator fail.
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   In production the route converter supplies the resolved EventSeries; the
   *   direct-dispatch test path supplies a raw series id. resolveSeries()
   *   normalizes either (and an instance id) to the series.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; the JSON body carries the fields to edit.
   */
  public function update($eventseries, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    $series = $this->resolveSeries($eventseries);
    if (!$series) {
      return $this->refuse('not_found', 'Event series not found.', 404);
    }

    // userMayManageSeries($series, 'update') already calls $series->access(
    // 'update') internally (its (A) branch), so no separate entity-access check
    // is needed here.
    if (!$this->accessHelper->userMayManageSeries($series, 'update')) {
      return $this->refuse('not_coordinator', 'You may not edit this event.', 409);
    }

    $body = json_decode($request->getContent(), TRUE) ?: [];

    // Content fields ONLY — never moderation_state, never recur config. Any
    // caller moderation_state in $body is ignored (applyContentFields does not
    // copy it, and title is the only extra key handled).
    $values = [];
    $this->applyContentFields($values, $body);
    if (array_key_exists('title', $body)) {
      $values['title'] = $body['title'];
    }

    foreach ($values as $field => $value) {
      $series->set($field, $value);
    }

    // The browser edit form runs entity validation before saving, so core's
    // moderation-state constraint refuses a content edit to a live published
    // series from an author who lacks the publish transition. This endpoint
    // must not offer a way around that gate: validate the series and, if any
    // violation exists, refuse rather than save. Administrators hold the
    // transition, so their edits produce no violation and pass through.
    $violations = $series->validate();
    if ($violations->count() > 0) {
      return $this->refuse('invalid_state', (string) $violations[0]->getMessage(), 409);
    }

    $series->save();

    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'updated_fields' => array_keys($values),
    ]);
  }

  /**
   * DELETE /api/2.3/event-series/{eventseries} — soft-delete a series.
   *
   * The first DESTRUCTIVE series op. A series that was ever published is
   * ARCHIVED — the series and each of its instances transition to
   * moderation_state = archived (the series archive does NOT cascade to the
   * instances, so each is archived explicitly against its own workflow). A
   * never-published draft is instead HARD-deleted; the recurring_events
   * predelete hook cascades the instance deletes, so no orphans remain.
   *
   * Registrations are always KEPT on archive — there is no force-to-destroy
   * gate on this path. Existing registrants on the series' not-yet-ended
   * instances are notified after the archive succeeds (send-only; see
   * CancellationNotifier).
   *
   * A never-published draft carrying ANY registrations (past or future —
   * attendance history is protected data) is REFUSED, not hard-deleted: see
   * EventDeleteGuard, the single place that rule is decided. The predelete
   * hook throw remains the backstop; this branch refuses BEFORE attempting
   * the delete so the caller gets a clean 409 instead of an unhandled
   * EntityStorageException.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolveSeries (404 if not found);
   *  3. userMayManageSeries('delete') entity-access gate;
   *  4. compute everPublished + future registrant count;
   *  5. preview unless confirmed (writes nothing);
   *  6. never-published draft → refuse if it has ANY registrations, else
   *     hard delete;
   *  7. else → the `archive` transition-permission gate, THEN archive + notify.
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   The resolved series (route converter) or a raw series/instance id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; confirmed is read from the query string.
   */
  public function delete($eventseries, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    $series = $this->resolveSeries($eventseries);
    if (!$series) {
      return $this->refuse('not_found', 'Event series not found.', 404);
    }

    // userMayManageSeries($series, 'delete') already calls $series->access(
    // 'delete') internally (its (A) branch), so no separate entity-access check
    // is needed here.
    if (!$this->accessHelper->userMayManageSeries($series, 'delete')) {
      return $this->refuse('not_coordinator', 'You may not delete this event.', 409);
    }

    $confirmed = filter_var($request->query->get('confirmed'), FILTER_VALIDATE_BOOLEAN);
    // FUTURE-scoped: this is who gets notified on the archive path below, and
    // (on the never-published branch) who would have been silently destroyed
    // under the old hard-delete-always behavior. It is NOT the same
    // population EventDeleteGuard blocks on — see $blockedReason immediately
    // below — so a draft with ONLY past registrants reports
    // registrants_affected: 0 here while still being refused. That divergence
    // is intentional (the two numbers answer different questions: "who would
    // be notified/lost" vs. "does ANY registration, past or future, exist at
    // all"), not a bug — the refusal response always also carries the guard's
    // own ALL-TIME count (registrations_total) so a caller never has to
    // reconcile the two itself.
    $registrants = $this->registrantCounter->countFutureForSeries((int) $series->id());
    $everPublished = $this->wasEverPublished($series);

    // A never-published draft with ANY registrations (past or future) can
    // never be hard-deleted — see EventDeleteGuard. Compute this once here so
    // both the preview and the confirmed branch below agree on it.
    $blockedReason = !$everPublished
      ? $this->deleteGuard->deletionBlockedReason($series)
      : NULL;
    $registrationsTotal = !$everPublished
      ? $this->registrantCounter->countForSeries((int) $series->id())
      : NULL;

    // Preview (no confirmed): describe what would happen, write nothing. The
    // archive path keeps registrations, so it carries no refusal.
    if (!$confirmed) {
      $preview = [
        'status' => 'preview',
        'executed' => FALSE,
        'series_id' => (int) $series->id(),
        'would_archive' => $everPublished,
        'would_hard_delete' => !$everPublished && $blockedReason === NULL,
        'registrants_affected' => $registrants,
      ];
      if ($blockedReason !== NULL) {
        $preview['refusal'] = $blockedReason;
        // The guard's own ALL-TIME count — see the $registrants docblock note
        // above for why this can differ from registrants_affected.
        $preview['registrations_total'] = $registrationsTotal;
      }
      return $this->success($preview);
    }

    if ($blockedReason !== NULL) {
      return $this->refuse('registrations_exist', $blockedReason, 409);
    }

    if (!$everPublished) {
      // Never-published draft with no registrations: hard delete. The
      // recurring_events predelete hook cascades the (registrant-free)
      // instance deletes. There is no legal archive transition FROM draft, so
      // there is nothing for state_transition_validation to check.
      $series->delete();
      return $this->success([
        'success' => TRUE,
        'series_id' => (int) $series->id(),
        'hard_deleted' => TRUE,
        'registrants_affected' => $registrants,
      ]);
    }

    // wasEverPublished() scans ALL revisions, so this branch is also reached for
    // a series that was published once but whose CURRENT state is no longer
    // published — already archived, or moved back to draft/needs_adjustment via
    // a normal editorial transition. The only legal archive transition is
    // published → archived; there is none from those states. Guard on whether
    // the transition EXISTS from the current state before validating it: the
    // non-throwing hasTransitionFromStateToState() avoids the
    // \InvalidArgumentException that isTransitionValid() → getTransitionFrom-
    // StateToState() throws (→ HTTP 500) when the transition is absent. This
    // mirrors the state-reaction sweep (EventStateReactions::sweepCancel()),
    // which likewise acts only on published, not-verifiably-past instances.
    $workflow = $this->moderationInformation->getWorkflowForEntity($series);
    $fromState = $this->moderationInformation->getOriginalState($series);
    if (!$workflow->getTypePlugin()->hasTransitionFromStateToState($fromState->id(), 'archived')) {
      // Already archived: effectively already soft-deleted — a re-delete is an
      // idempotent no-op success reflecting reality, not a 500 or a confusing
      // error.
      if ($fromState->id() === 'archived') {
        return $this->success([
          'success' => TRUE,
          'series_id' => (int) $series->id(),
          'instances_archived' => 0,
          'registrants_affected' => $registrants,
          'notified' => 0,
          'hard_deleted' => FALSE,
        ]);
      }
      // Was published, now draft/needs_adjustment: there is no legal archive
      // transition from here, so refuse cleanly rather than 500. The event must
      // be published to be archived.
      return $this->refuse('forbidden', 'This event is not in a state that can be archived; only a published event can be archived.', 403);
    }

    // Entity access (above) does not check moderation-transition permissions —
    // those are separate `use editorial transition <name>` grants. Verify the
    // acting user actually holds the `archive` transition (published →
    // archived) before touching anything; never hardcode a role/permission
    // string. isTransitionValid takes StateInterface OBJECTS, not string ids.
    // Per the real config editor roles such as news_pm and campuschampionsadmin,
    // plus administrator hold `archive`, so this refuses the common case
    // (author, AG-leader) BEFORE any instance changes.
    $toState = $workflow->getTypePlugin()->getState('archived');
    if (!$this->transitionValidation->isTransitionValid($workflow, $fromState, $toState, $this->currentUser(), $series)) {
      return $this->refuse('forbidden', 'You may not archive this event.', 403);
    }

    // The save itself does all the orchestration: access_events.state_
    // reactions (wired to eventseries_update) sweeps every published,
    // not-verifiably-past instance to archived, enqueues their registrants'
    // cancellation notices via CancellationNotifier::enqueueGated, and
    // records the outcomes on the collector under this series' key. The
    // controller does not orchestrate — it only gates, writes the state, and
    // reads back what happened.
    $series->set('moderation_state', 'archived')->save();
    $payload = $this->stateChangeCollector->drain('eventseries', (int) $series->id());

    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'instances_archived' => $payload['instances_archived'] ?? 0,
      'registrants_affected' => $registrants,
      'notified' => $payload['notified'] ?? 0,
      'notifications_disabled' => $payload['notifications_disabled'] ?? FALSE,
      'hard_deleted' => FALSE,
    ]);
  }

  /**
   * POST /api/2.3/event-series/{eventseries}/restore — un-archive a series.
   *
   * The inverse of delete()'s archive branch: an archived series and each of
   * its archived instances transition back to moderation_state = published via
   * the `archived_published` transition (archived → published). That transition
   * is DISTINCT from `publish` (whose from-states never include archived), and
   * per the live config only news_pm/administrator hold it on either workflow —
   * so an author or affinity_group_leader who owns (or could publish a NEW)
   * series is refused here.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolveSeries (404 if not found);
   *  3. userMayManageSeries('update') entity-access gate;
   *  4. the archived-state guard (hasTransitionFromStateToState) — a
   *     never-archived series refuses invalid_state; an already-published one is
   *     an idempotent no-op — BEFORE isTransitionValid, which would otherwise
   *     throw \InvalidArgumentException (HTTP 500) on a missing transition;
   *  5. the `archived_published` transition-permission gate, THEN restore.
   *
   * On success, the series save's state-reaction sweep (EventStateReactions::
   * sweepRestore()) notifies the just-republished instances' registrants that
   * the event is back on (CancellationNotifier::enqueueGated, keyed under
   * REINSTATE_KEY, send-only); this controller reports the drained count as
   * `notified` in the envelope.
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   The resolved series (route converter) or a raw series/instance id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function restore($eventseries, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    $series = $this->resolveSeries($eventseries);
    if (!$series) {
      return $this->refuse('not_found', 'Event series not found.', 404);
    }

    // Restoring is a moderation-state change on an existing series, not a
    // delete — gate it on 'update'. userMayManageSeries('update') already calls
    // $series->access('update') internally, so no separate entity-access check
    // is needed here.
    if (!$this->accessHelper->userMayManageSeries($series, 'update')) {
      return $this->refuse('not_coordinator', 'You may not restore this event.', 409);
    }

    // Restore is specifically the archived → published transition. Guard on the
    // series being ARCHIVED before validating it: the non-throwing
    // hasTransitionFromStateToState() avoids the \InvalidArgumentException that
    // isTransitionValid() → getTransitionFromStateToState() throws (→ HTTP 500)
    // when the archived → published transition is absent. hasTransition alone is
    // insufficient here (unlike delete's archive guard): a draft ALSO has a
    // transition TO published — the DISTINCT `publish` transition — so we anchor
    // on the current state being archived, the only state archived_published is
    // legal from. This mirrors delete()'s archive guard, inverted.
    $workflow = $this->moderationInformation->getWorkflowForEntity($series);
    $fromState = $this->moderationInformation->getOriginalState($series);
    $archivedToPublished = $fromState->id() === 'archived'
      && $workflow->getTypePlugin()->hasTransitionFromStateToState('archived', 'published');
    if (!$archivedToPublished) {
      // Already published: nothing to restore — an idempotent no-op success
      // reflecting reality, not a 500 or a confusing error.
      if ($fromState->id() === 'published') {
        return $this->success([
          'success' => TRUE,
          'series_id' => (int) $series->id(),
          'instances_restored' => 0,
          'notified' => 0,
        ]);
      }
      // Any other state (draft, needs_adjustment, …): there is no legal restore
      // transition from here — only an archived event can be restored. Refuse
      // cleanly rather than 500.
      return $this->refuse('invalid_state', 'This event is not archived; only an archived event can be restored.', 409);
    }

    // Entity access (above) does not check moderation-transition permissions —
    // those are separate `use editorial transition <name>` grants. Verify the
    // acting user actually holds the `archived_published` transition (archived →
    // published) before touching anything; never hardcode a role/permission
    // string. isTransitionValid takes StateInterface OBJECTS, not string ids.
    // Per the real config only news_pm and administrator hold
    // `archived_published`, so this refuses the common case (author, AG-leader —
    // the latter holds `publish` but NOT `archived_published`) BEFORE any
    // instance changes.
    $toState = $workflow->getTypePlugin()->getState('published');
    if (!$this->transitionValidation->isTransitionValid($workflow, $fromState, $toState, $this->currentUser(), $series)) {
      return $this->refuse('forbidden', 'You may not restore this event.', 403);
    }

    // The save itself does all the orchestration: access_events.state_
    // reactions sweeps every archived, unflagged (not individually
    // cancelled), not-verifiably-past instance to published, enqueues their
    // registrants' reinstatement notices via CancellationNotifier::
    // enqueueGated, and records the outcomes on the collector under this
    // series' key. The controller does not orchestrate — it only gates,
    // writes the state, and reads back what happened.
    $series->set('moderation_state', 'published')->save();
    $payload = $this->stateChangeCollector->drain('eventseries', (int) $series->id());

    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'instances_restored' => $payload['instances_published'] ?? 0,
      'notified' => $payload['notified'] ?? 0,
      'notifications_disabled' => $payload['notifications_disabled'] ?? FALSE,
    ]);
  }

  /**
   * POST /api/2.3/event-series/{eventseries}/send-for-review — request review.
   *
   * The author-facing path to publication. create_event always produces a
   * draft (there is no self-publish path), and a plain author holds
   * send_for_review but NOT publish — so their legal next step is to route the
   * series draft|needs_adjustment → ready_for_review, where an editor
   * (affinity_group_leader or news_pm, who hold publish) can approve it. This
   * is the first write op where a PLAIN AUTHOR succeeds: unlike archive
   * (delete) and archived_published (restore), authenticated holds
   * send_for_review by site policy.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolveSeries (404 if not found);
   *  3. userMayManageSeries('update') entity-access gate;
   *  4. the source-state guard — send_for_review is legal ONLY from draft or
   *     needs_adjustment. This is anchored on the CURRENT state, not the
   *     target: TWO transitions target ready_for_review (send_for_review from
   *     draft/needs_adjustment, review_to_review from ready_for_review), so a
   *     target-reachability check would be ambiguous. The in_array source check
   *     is unambiguous and also keeps isTransitionValid from ever being called
   *     on a state it cannot send_for_review from — so a published/archived/
   *     already-in-review series refuses invalid_state (409), never a 500;
   *  5. the send_for_review transition-permission gate, THEN the transition.
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   The resolved series (route converter) or a raw series/instance id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function sendForReview($eventseries, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    $series = $this->resolveSeries($eventseries);
    if (!$series) {
      return $this->refuse('not_found', 'Event series not found.', 404);
    }

    // The user may edit the series at all — entity access, not the transition
    // itself. userMayManageSeries('update') already calls $series->access(
    // 'update') internally, so no separate entity-access check is needed here.
    if (!$this->accessHelper->userMayManageSeries($series, 'update')) {
      return $this->refuse('not_coordinator', 'You may not edit this event.', 409);
    }

    // Source-state guard: send_for_review is legal only from draft or
    // needs_adjustment. Anchoring on the current state (not the target) is
    // unambiguous — see the method docblock — and prevents isTransitionValid
    // from being called on a state that cannot send_for_review, which would
    // throw \InvalidArgumentException (HTTP 500).
    $fromStateId = $series->get('moderation_state')->value;
    if (!in_array($fromStateId, ['draft', 'needs_adjustment'], TRUE)) {
      return $this->refuse('invalid_state', sprintf('This event is "%s"; send_for_review is only valid from draft or needs_adjustment.', $fromStateId), 409);
    }

    // Entity access (above) does not check moderation-transition permissions —
    // those are separate `use editorial transition <name>` grants.
    // authenticated holds send_for_review by site policy, but never hardcode
    // that — verify via the validation service so any future config change is
    // honored automatically. isTransitionValid takes StateInterface OBJECTS,
    // not string ids.
    $workflow = $this->moderationInformation->getWorkflowForEntity($series);
    $fromState = $this->moderationInformation->getOriginalState($series);
    $toState = $workflow->getTypePlugin()->getState('ready_for_review');
    if (!$this->transitionValidation->isTransitionValid($workflow, $fromState, $toState, $this->currentUser(), $series)) {
      return $this->refuse('forbidden', 'You may not send this event for review.', 403);
    }

    $series->set('moderation_state', 'ready_for_review')->save();
    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'moderation_state' => $series->get('moderation_state')->value,
    ]);
  }

  /**
   * DELETE /api/2.3/event-occurrences/{eventinstance} — cancel one occurrence.
   *
   * Tri-state, plus a catch-all refusal, keyed on the instance's CURRENT
   * moderation_state:
   *  - published → archive it. The save alone does the orchestration: the
   *    cancellation-email reaction (EventStateReactions::instancePresave()/
   *    instanceUpdate(), wired to the moderation-state-change hooks) sets
   *    individually_cancelled and enqueues
   *    the cancellation email; this controller does not orchestrate — it only
   *    gates, writes the state, and drains the collector for the response
   *    envelope. This is the instance-level equivalent of delete()'s archive
   *    branch, gated IDENTICALLY (scoped to the one instance): a bare
   *    set('moderation_state','archived')->save() would bypass content_
   *    moderation validation and let any user passing the coordinator check
   *    archive an instance regardless of whether they hold the `archive`
   *    transition, making cancel MORE permissive than delete — the transition
   *    gate below closes that hole.
   *  - archived → idempotent: set individually_cancelled TRUE (if not already)
   *    and save. This is the ONLY branch that ever sets the flag on an
   *    already-archived instance directly (the cancellation-email reaction
   *    only fires on a published→non-published transition, which an
   *    archived→archived re-save is not);
   *    it exists so an instance that arrived at archived via a SERIES-WIDE
   *    sweep (never individually flagged) can still be marked "don't bring
   *    this one back" without a live transition to ride. The response notes
   *    the instance is now excluded from a future series restore.
   *  - draft, unregistered → refuse invalid_state: delete it instead (there is
   *    nothing published to soft-cancel, and no registration to protect).
   *  - draft, registered (countForInstance() > 0) → refuse invalid_state: a
   *    draft occurrence should never carry a registrant in the normal flow, so
   *    this is flagged for manual review rather than silently archived or
   *    silently deleted.
   *  - any other state (needs_adjustment, ready_for_review, …) → refuse
   *    invalid_state (catch-all).
   *
   * Instance-level authorization resolves via the instance's PARENT series: the
   * series carries the affinity group whose coordinators may manage its
   * occurrences, and userMayManageSeries() operates on a series. 'delete' is the
   * op — cancelling an occurrence is delete-shaped.
   *
   * The registration on this instance is always KEPT on cancel — there is no
   * force-to-destroy gate on this path.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolve the instance (404 if not found);
   *  3. userMayManageSeries('delete') on the parent series (entity-access gate);
   *  4. compute future registrant count;
   *  5. preview unless confirmed (writes nothing);
   *  6. branch on the instance's CURRENT state (published/archived/draft/other);
   *  7. published branch only: the `archive` transition-permission gate, THEN
   *     archive (the cancellation-email reaction does the rest). The
   *     archived/draft/other branches do not
   *     hold a live content_moderation transition to validate — archived→
   *     archived and draft→draft are self-transitions the workflow does not
   *     define, so those branches write the flag/refuse directly rather than
   *     calling isTransitionValid() on a transition that does not exist.
   *
   * @param int|\Drupal\recurring_events\Entity\EventInstance $eventinstance
   *   In production the route converter supplies the resolved EventInstance; the
   *   direct-dispatch test path supplies a raw instance id. Both are accepted:
   *   an id is loaded (mirrors resolveSeries()'s loose handling).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; confirmed is read from the query string.
   */
  public function cancelOccurrence($eventinstance, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    // Accept a resolved entity (route converter) or a raw id (test dispatch).
    if (!$eventinstance instanceof EventInstance) {
      $eventinstance = $this->entityTypeManager->getStorage('eventinstance')->load($eventinstance);
    }
    if (!$eventinstance instanceof EventInstance) {
      return $this->refuse('not_found', 'Event occurrence not found.', 404);
    }

    // Instance authz resolves via the parent series' affinity-group coordinator
    // grant. userMayManageSeries('delete') already calls $series->access(
    // 'delete') internally (its (A) branch), so no separate entity-access check
    // is needed here.
    $series = $eventinstance->getEventSeries();
    if (!$series || !$this->accessHelper->userMayManageSeries($series, 'delete')) {
      return $this->refuse('not_coordinator', 'You may not cancel this occurrence.', 409);
    }

    $confirmed = filter_var($request->query->get('confirmed'), FILTER_VALIDATE_BOOLEAN);
    $registrants = $this->registrantCounter->countFutureForInstance((int) $eventinstance->id());

    // Preview (no confirmed): describe what would happen, write nothing.
    if (!$confirmed) {
      return $this->success([
        'status' => 'preview',
        'executed' => FALSE,
        'eventinstance_id' => (int) $eventinstance->id(),
        'registrants_affected' => $registrants,
      ]);
    }

    $currentState = $eventinstance->get('moderation_state')->value;

    if ($currentState === 'published') {
      // The instance uses the editorial_eventinstance workflow, whose only path
      // to `archived` is the `archive` transition from `published`. Entity
      // access (above) does not check moderation-transition permissions —
      // those are separate `use editorial_eventinstance transition <name>`
      // grants. Verify the acting user actually holds `archive` before
      // touching anything; never hardcode a role/permission string.
      // isTransitionValid takes StateInterface OBJECTS, not string ids. Per
      // the real config only news_pm/administrator hold `archive` on
      // editorial_eventinstance, so this refuses the common case (author,
      // AG-leader) — the SAME operation and roster delete gates.
      $workflow = $this->moderationInformation->getWorkflowForEntity($eventinstance);
      $fromState = $this->moderationInformation->getOriginalState($eventinstance);
      $toState = $workflow->getTypePlugin()->getState('archived');
      if (!$this->transitionValidation->isTransitionValid($workflow, $fromState, $toState, $this->currentUser(), $eventinstance)) {
        return $this->refuse('forbidden', 'You may not cancel this occurrence.', 403);
      }

      // The save itself does all the orchestration: the cancellation-email
      // reaction (EventStateReactions::instancePresave()/instanceUpdate())
      // sets individually_cancelled and enqueues the cancellation email; the
      // controller only drains what happened. The reaction records the SAME
      // outcome under both the instance's own key and its parent series' key
      // (see reactToInstanceCancelled()) — the
      // series-level echo carries nothing this single-occurrence response
      // needs, but it must still be drained (not just read and discarded) so
      // it never lingers to pollute a later, unrelated collector read in the
      // same request.
      $eventinstance->set('moderation_state', 'archived')->save();
      $payload = $this->stateChangeCollector->drain('eventinstance', (int) $eventinstance->id());
      $this->stateChangeCollector->drain('eventseries', (int) $series->id());

      return $this->success([
        'success' => TRUE,
        'eventinstance_id' => (int) $eventinstance->id(),
        'registrants_affected' => $registrants,
        'notified' => $payload['notified'] ?? 0,
        'notifications_disabled' => $payload['notifications_disabled'] ?? FALSE,
      ]);
    }

    if ($currentState === 'archived') {
      // Idempotent: mark (or confirm) the instance as individually cancelled
      // so a later series-wide restore skips it — see EventStateReactions::
      // sweepRestore(). This is a withdrawal of the occurrence's participation,
      // the same archive-family editorial decision that reaching `archived` via
      // moderation requires, so gate it on the same `archive` transition
      // permission rather than the broader manage-series grant. Administrators
      // hold that permission and pass; an author or AG-leader who can manage
      // the series but not archive is refused.
      if (!$this->currentUser()->hasPermission('use editorial_eventinstance transition archive')) {
        return $this->refuse('forbidden', 'Cancelling this occurrence\'s participation requires the events-editor permission.', 403);
      }
      $eventinstance->set('individually_cancelled', TRUE)->save();
      return $this->success([
        'success' => TRUE,
        'eventinstance_id' => (int) $eventinstance->id(),
        'registrants_affected' => $registrants,
        'notified' => 0,
        'note' => 'This occurrence was already cancelled; it will be excluded from a future series restore.',
      ]);
    }

    if ($currentState === 'draft') {
      $draftRegistrants = $this->registrantCounter->countForInstance((int) $eventinstance->id());
      if ($draftRegistrants > 0) {
        return $this->refuse('invalid_state', 'This occurrence is an unpublished draft with registrations; it needs manual review.', 409);
      }
      return $this->refuse('invalid_state', 'This occurrence is an unpublished draft; delete it instead.', 409);
    }

    // Catch-all: any other state (needs_adjustment, ready_for_review, …) has
    // no defined cancel semantics.
    return $this->refuse('invalid_state', sprintf('This occurrence is "%s"; only a published or archived occurrence can be cancelled.', $currentState), 409);
  }

  /**
   * POST /api/2.3/event-occurrences/{eventinstance}/restore — un-cancel one
   * occurrence.
   *
   * The inverse of cancelOccurrence(). Branches on whether the instance's
   * PARENT series is currently published (binary isPublished(), never a
   * string state compare — mirrors EventStateReactions::assertParentPublished()
   * so this controller and the occurrence-publish-under-unpublished-event
   * refusal always agree):
   *  - archived + published parent → publish it. The save alone does the
   *    orchestration: the reinstatement reaction
   *    (EventStateReactions::instancePresave()/instanceUpdate()) clears
   *    individually_cancelled and enqueues the reinstatement email; this
   *    controller only drains what happened.
   *  - archived + dark (non-published) parent → publishing would violate the
   *    occurrence-publish-under-unpublished-event refusal (an occurrence
   *    cannot be published while its event is not), so
   *    this branch instead just CLEARS the individually_cancelled flag and
   *    saves — the instance stays archived, but is no longer excluded from
   *    the series' own restore sweep (EventStateReactions::sweepRestore()), so
   *    it comes back automatically once the SERIES itself is restored. The
   *    response signals this with returns_with_series: true.
   *  - non-archived (published, draft, …) → refuse invalid_state: there is
   *    nothing to restore.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolve the instance (404 if not found);
   *  3. userMayManageSeries('update') on the parent series (entity-access
   *     gate) — restoring is a moderation-state change on an EXISTING
   *     instance, not a delete, so 'update' is the op (mirrors the series-
   *     level restore()'s own choice of 'update' over 'delete');
   *  4. the archived-state guard — non-archived refuses invalid_state up
   *     front;
   *  5. branch on the parent's published/dark status;
   *  6. published-parent branch only: the `archived_published` transition-
   *     permission gate, THEN publish (the reinstatement reaction does the
   *     rest). The dark-parent branch does not hold a live transition to
   *     validate (publishing under a dark parent is exactly what the
   *     occurrence-publish-under-unpublished-event refusal refuses), so it
   *     clears the flag directly instead.
   *
   * @param int|\Drupal\recurring_events\Entity\EventInstance $eventinstance
   *   In production the route converter supplies the resolved EventInstance; the
   *   direct-dispatch test path supplies a raw instance id. Both are accepted.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function restoreOccurrence($eventinstance, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    // Accept a resolved entity (route converter) or a raw id (test dispatch).
    if (!$eventinstance instanceof EventInstance) {
      $eventinstance = $this->entityTypeManager->getStorage('eventinstance')->load($eventinstance);
    }
    if (!$eventinstance instanceof EventInstance) {
      return $this->refuse('not_found', 'Event occurrence not found.', 404);
    }

    // Instance authz resolves via the parent series' affinity-group coordinator
    // grant. userMayManageSeries('update') already calls $series->access(
    // 'update') internally, so no separate entity-access check is needed here.
    $series = $eventinstance->getEventSeries();
    if (!$series || !$this->accessHelper->userMayManageSeries($series, 'update')) {
      return $this->refuse('not_coordinator', 'You may not restore this occurrence.', 409);
    }

    $currentState = $eventinstance->get('moderation_state')->value;
    if ($currentState !== 'archived') {
      return $this->refuse('invalid_state', 'This occurrence is not cancelled; only a cancelled (archived) occurrence can be restored.', 409);
    }

    // Reload the parent from storage — never getEventSeries()/->entity, which
    // can resolve a cached or in-request-modified copy rather than the
    // series' last-saved state. Mirrors EventStateReactions::
    // assertParentPublished()'s own loadUnchanged() + binary isPublished().
    $parent = $this->entityTypeManager->getStorage('eventseries')->loadUnchanged($series->id());
    $parentPublished = $parent instanceof EventSeries && $parent->isPublished();

    if (!$parentPublished) {
      // Publishing would violate the occurrence-publish-under-unpublished-
      // event refusal while the parent is dark. Clear
      // the flag instead so the instance rejoins the series' own restore
      // sweep once the series itself comes back; it stays archived for now.
      // Clearing the flag is an archive-family editorial decision (it re-arms
      // the occurrence to return to published), so gate it on the same
      // `archive` transition permission the moderation path requires rather
      // than the broader manage-series grant. Administrators hold it and pass.
      if (!$this->currentUser()->hasPermission('use editorial_eventinstance transition archive')) {
        return $this->refuse('forbidden', 'Restoring this occurrence\'s participation requires the events-editor permission.', 403);
      }
      $eventinstance->set('individually_cancelled', FALSE)->save();
      return $this->success([
        'success' => TRUE,
        'eventinstance_id' => (int) $eventinstance->id(),
        'returns_with_series' => TRUE,
        'notified' => 0,
      ]);
    }

    // Entity access (above) does not check moderation-transition permissions —
    // those are separate `use editorial_eventinstance transition <name>`
    // grants. Verify the acting user actually holds `archived_published`
    // (archived → published) before touching anything; never hardcode a
    // role/permission string. isTransitionValid takes StateInterface OBJECTS,
    // not string ids. Per the real config only news_pm/administrator hold
    // `archived_published` on editorial_eventinstance.
    $workflow = $this->moderationInformation->getWorkflowForEntity($eventinstance);
    $fromState = $this->moderationInformation->getOriginalState($eventinstance);
    $toState = $workflow->getTypePlugin()->getState('published');
    if (!$this->transitionValidation->isTransitionValid($workflow, $fromState, $toState, $this->currentUser(), $eventinstance)) {
      return $this->refuse('forbidden', 'You may not restore this occurrence.', 403);
    }

    // The save itself does all the orchestration: the reinstatement reaction
    // (EventStateReactions::instancePresave()/instanceUpdate()) clears
    // individually_cancelled and enqueues the reinstatement email; the
    // controller only drains what happened. The reaction records the SAME
    // outcome under both the instance's own key and its parent series' key
    // (see reactToInstanceReinstated()) — the
    // series-level echo carries nothing this single-occurrence response
    // needs, but it must still be drained (not just read and discarded) so
    // it never lingers to pollute a later, unrelated collector read in the
    // same request.
    $eventinstance->set('moderation_state', 'published')->save();
    $payload = $this->stateChangeCollector->drain('eventinstance', (int) $eventinstance->id());
    $this->stateChangeCollector->drain('eventseries', (int) $series->id());

    return $this->success([
      'success' => TRUE,
      'eventinstance_id' => (int) $eventinstance->id(),
      'notified' => $payload['notified'] ?? 0,
      'notifications_disabled' => $payload['notifications_disabled'] ?? FALSE,
    ]);
  }

  /**
   * PATCH /api/2.3/event-occurrences/{eventinstance} — edit one occurrence.
   *
   * Changes a SINGLE event instance's content fields (date, field_location).
   * This is a content edit on the instance, NOT a series recur-config change, so
   * it does NOT trigger the module's instance recreate — sibling instances and
   * the parent series are untouched, and no registrants are lost.
   *
   * A DATE change is where this can go wrong silently: someone already
   * registered needs to hear about a move. The PREVIEW gate (below the save)
   * is intentionally more permissive than what actually ends up notified:
   * it's keyed on the PROSPECTIVE new end timestamp (parsed straight from the
   * request body), not on the instance's current (pre-save) date or its
   * published state — so a coordinator always sees an honest "here's who
   * would be notified" warning before confirming, even for edits (like
   * publishing a draft AND moving its date in one call) that the real post-
   * save state reaction would end up gating differently. If the request
   * changes `date` and the PROSPECTIVE new date is future and the instance
   * has registrants, the edit requires confirmed=TRUE and previews first (no
   * write), reporting registrants_to_notify. A date change to the past, a
   * date change with no registrants, or a location-only edit all proceed
   * without confirmation — there is either nothing to notify or no
   * reschedule risk.
   *
   * On a confirmed date change, the save itself is what registrants are
   * notified of: EventStateReactions::instanceUpdate() ->
   * reactToInstanceModified() enqueues the reschedule notice whenever the
   * saved instance is published BOTH before and after this save AND the date
   * field actually changed — the SAME state reaction fires identically for
   * this endpoint and for the node/eventinstance edit form, so this
   * controller does not separately enqueue anything. Unlike the preview
   * above, the real reaction also looks at the OLD end date (not just the
   * new one): a registrant who was live under the OLD schedule still gets
   * notified even if the edit moves the event into the past, because they
   * didn't know it moved. The response's registrants_affected is drained
   * from what the reaction actually recorded, not re-derived here — see
   * below the save.
   *
   * PREVIEW GATING — published vs. dark: the registrants_to_notify preview
   * above is computed ONLY when the instance's default revision is currently
   * published — the real post-save reaction only ever fires off a saved,
   * moderated instance that is (and stays) live, so previewing a non-zero
   * count against a DARK instance (draft/archived/needs_adjustment/…) would
   * promise a notification nobody will get. A dark instance's date-change
   * preview instead reports registrants_to_notify: 0 plus a note that
   * registrants are notified when the event is restored.
   *
   * Instance-level authorization resolves via the instance's PARENT series — the
   * series carries the affinity group whose coordinators may manage its
   * occurrences, and userMayManageSeries() operates on a series. 'update' is the
   * op (this is a content edit, not a delete/archive). This mirrors
   * cancelOccurrence's authz-via-parent-series pattern; unlike cancel there is no
   * moderation transition here (no state change), so no transition gate runs.
   *
   * @param int|\Drupal\recurring_events\Entity\EventInstance $eventinstance
   *   In production the route converter supplies the resolved EventInstance; the
   *   direct-dispatch test path supplies a raw instance id. Both are accepted.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; confirmed is read from the query string, the JSON body
   *   carries date / field_location.
   */
  public function editOccurrence($eventinstance, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    // Accept a resolved entity (route converter) or a raw id (test dispatch).
    if (!$eventinstance instanceof EventInstance) {
      $eventinstance = $this->entityTypeManager->getStorage('eventinstance')->load($eventinstance);
    }
    if (!$eventinstance instanceof EventInstance) {
      return $this->refuse('not_found', 'Event occurrence not found.', 404);
    }

    // Instance authz resolves via the parent series' affinity-group coordinator
    // grant. userMayManageSeries('update') already calls $series->access(
    // 'update') internally, so no separate entity-access check is needed here.
    $series = $eventinstance->getEventSeries();
    if (!$series || !$this->accessHelper->userMayManageSeries($series, 'update')) {
      return $this->refuse('not_coordinator', 'You may not edit this occurrence.', 409);
    }

    $body = json_decode($request->getContent(), TRUE) ?: [];
    // The preview population is every registrant of the instance, not
    // future-scoped — see the method docblock. countForInstance(), not
    // countFutureForInstance().
    $registrants = $this->registrantCounter->countForInstance((int) $eventinstance->id());

    // A date move strands whoever is already registered if nobody tells
    // them. Only an actual date CHANGE carries that risk — compare against
    // the current stored value the same way
    // EventStateReactions::reactToInstanceModified() does post-save, so this
    // preview gate and the actual state reaction agree on what counts as a
    // reschedule. A location-only edit, or a date write that leaves the
    // value unchanged, needs no confirmation.
    $changesDate = array_key_exists('date', $body)
      && serialize($eventinstance->get('date')->getValue()) !== serialize([$body['date']]);

    // Gate on the PROSPECTIVE new date, not the instance's current one — the
    // contrib hook decides post-save against the NEW end timestamp. A
    // missing/malformed end_value in the request is treated as "not future"
    // (fail closed on the confirm requirement, not on the notify promise —
    // an occurrence with no parseable future end date is not a case where a
    // mass-notify silently happens without confirmation).
    $newEnd = is_array($body['date'] ?? NULL) ? ($body['date']['end_value'] ?? NULL) : NULL;
    $newEndTimestamp = is_string($newEnd) ? strtotime($newEnd . ' UTC') : FALSE;
    $newDateIsFuture = $newEndTimestamp !== FALSE && $newEndTimestamp > $this->time->getRequestTime();

    $confirmed = filter_var($request->query->get('confirmed'), FILTER_VALIDATE_BOOLEAN);

    // The registrants_to_notify preview is only meaningful when the instance
    // is currently LIVE (published default revision) — a dark instance's
    // registrants are not notified by this save; they are notified when the
    // event is restored (see the method docblock).
    $isLive = $eventinstance->isPublished();

    // Preview (no confirmed): describe what would happen, write nothing.
    if ($changesDate && $newDateIsFuture && $registrants > 0 && !$confirmed) {
      $preview = [
        'status' => 'preview',
        'executed' => FALSE,
        'eventinstance_id' => (int) $eventinstance->id(),
        'date_changes' => TRUE,
        'registrants_to_notify' => $isLive ? $registrants : 0,
      ];
      if (!$isLive) {
        $preview['note'] = 'Registrants are notified when the event is restored.';
      }
      return $this->success($preview);
    }

    // Content-only override on the one instance. date is the instance's own
    // daterange; field_location is its location string. Nothing else is writable
    // here — a recur-config change goes through add_occurrence /
    // cancel_occurrence (per-occurrence) or the browser form (unregistered
    // pattern-wide edits), never this endpoint.
    if (array_key_exists('date', $body)) {
      $eventinstance->set('date', $body['date']);
    }
    if (array_key_exists('field_location', $body)) {
      $eventinstance->set('field_location', $body['field_location']);
    }
    // Saving here is what a registered future registrant gets notified of:
    // EventStateReactions::instanceUpdate() -> reactToInstanceModified()
    // enqueues the reschedule notice whenever this save's date field actually
    // changed AND the instance is (and stays) published — the SAME state
    // reaction fires whether this save comes from this API or from the node/
    // eventinstance edit form, so no separate enqueue happens here.
    $eventinstance->save();

    // Drain the REAL outcome the state-reaction machinery just recorded,
    // rather than re-deriving a guess from $changesDate/$newDateIsFuture —
    // those two locals only describe the REQUEST, not what the state
    // reactions actually decided (e.g. they don't know whether the instance
    // was actually published before AND after this save, which is also part
    // of what gates the notice). Mirrors cancelOccurrence()/
    // restoreOccurrence(): the eventseries echo key carries nothing this
    // single-occurrence response needs, but must still be drained (not just
    // read) so it never lingers to pollute a later, unrelated collector read
    // in the same request.
    $payload = $this->stateChangeCollector->drain('eventinstance', (int) $eventinstance->id());
    $this->stateChangeCollector->drain('eventseries', (int) $series->id());

    return $this->success([
      'success' => TRUE,
      'eventinstance_id' => (int) $eventinstance->id(),
      'registrants_affected' => $payload['notified'] ?? 0,
    ]);
  }

  /**
   * POST /api/2.3/event-series/{eventseries}/occurrence — add one occurrence.
   *
   * Creates one new eventinstance directly on the series, via the SAME contrib
   * chain the series' own create/rebuild machinery uses
   * (EventCreationService::createEventInstance() → configureDefaultInheritances()
   * → save()) — NOT a custom_date config write. createEventInstance() returns
   * an UNSAVED instance whose birth moderation_state is decided by
   * access_events_recurring_events_event_instance_alter() (published parent →
   * published; archived parent → archived — see PastPreservingEventInstance
   * Creator's BIRTH STATE docblock), so this endpoint needs no state logic of
   * its own and works identically whether the series is published or archived.
   *
   * This REPLACES the old custom_date-append + validate()/save() path (which
   * relied on the series' recur-config-change save to trigger a full
   * hard-delete + recreate of every instance via
   * RecreateEventInstanceCreator/PastPreservingEventInstanceCreator). A direct
   * instance create touches nothing but the one new row: no rebuild, no
   * reschedule-block validation, no risk to any other instance's registrants —
   * which is also why the old rule-series recurrence_conflict refusal is GONE:
   * a rule series' dates come from its rule, but a direct create is not a
   * config change, so extending a weekly series past its rule end with a
   * one-off addition is exactly the sanctioned "add a date" story on either
   * recur_type.
   *
   * DUPLICATE-DATE REFUSAL: before creating, every existing instance of the
   * series is checked for a start-timestamp collision (strtotime-equal to the
   * requested start). An exact collision refuses duplicate_date rather than
   * silently creating a same-time twin. If the colliding instance is itself
   * archived AND individually_cancelled (a deliberately withdrawn occurrence —
   * see EventStateReactions::instancePresave()'s cancellation-email reaction
   * flag write), the message
   * instead points the caller at restore_occurrence — re-adding at that exact
   * moment is almost always meant to bring the cancelled occurrence back, not
   * to create a second, competing one.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. userMayManageSeries('update') entity-access gate (adding is an update);
   *  3. body validation (date.value/date.end_value required);
   *  4. draft-series refusal (invalid_state) — a draft series' new instance
   *     would be born archived like its dark parent (see the birth-state
   *     alter), never visibly published, so refuse up front rather than
   *     report a phantom success;
   *  5. duplicate-start check across the series' existing instances
   *     (duplicate_date, with the cancelled-twin variant);
   *  6. the contrib create chain: createEventInstance() → configure
   *     DefaultInheritances() → save().
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   In production the route converter supplies the resolved EventSeries; the
   *   direct-dispatch test path supplies a raw series id. Both are accepted.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; date.value/date.end_value in the JSON body (same shape as
   *   edit_occurrence's date field).
   */
  public function addOccurrence($eventseries, Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }

    // Accept a resolved entity (route converter) or a raw id (test dispatch).
    if (!$eventseries instanceof EventSeries) {
      $eventseries = $this->entityTypeManager->getStorage('eventseries')->load($eventseries);
    }
    if (!$eventseries instanceof EventSeries) {
      return $this->refuse('not_found', 'Event series not found.', 404);
    }

    // Adding an occurrence is an update to the series — gate on 'update'.
    // userMayManageSeries('update') already calls $series->access('update')
    // internally, so no separate entity-access check is needed here.
    if (!$this->accessHelper->userMayManageSeries($eventseries, 'update')) {
      return $this->refuse('not_coordinator', 'You may not add an occurrence.', 409);
    }

    $body = json_decode($request->getContent(), TRUE) ?: [];
    $startValue = is_array($body['date'] ?? NULL) ? ($body['date']['value'] ?? NULL) : NULL;
    $endValue = is_array($body['date'] ?? NULL) ? ($body['date']['end_value'] ?? NULL) : NULL;
    if (empty($startValue) || empty($endValue)) {
      return $this->refuse('validation_error', 'date.value and date.end_value are required.', 422);
    }

    // Draft (never-published) series: a new instance would be born archived
    // (the birth-state alter follows the parent's isPublished()), with no
    // path back to visible since the series itself was never published
    // either — refuse rather than report a phantom success. Mirrors the old
    // custom_date path's draft refusal, same message. An ARCHIVED (was-
    // published, now dark) series is explicitly ALLOWED: its new instance is
    // likewise born archived, but comes back the ordinary way once the
    // series itself is restored — a coherent, recoverable outcome, unlike a
    // series that was never published at all.
    $currentSeriesState = $eventseries->get('moderation_state')->value;
    if ($currentSeriesState === 'draft') {
      return $this->refuse('invalid_state', 'This event is a draft; publish it before adding occurrences. (A draft add would not create a visible occurrence.)', 409);
    }

    // Duplicate-start check: compare the requested start against every
    // existing instance's start, both normalized via strtotime(' UTC') so a
    // formatting difference (e.g. trailing seconds) does not mask a real
    // collision. An exact match refuses rather than silently creating a
    // same-time twin.
    $requestedStart = strtotime($startValue . ' UTC');
    foreach ($this->loadInstancesForSeries((int) $eventseries->id()) as $existingInstance) {
      $existingStart = $existingInstance->get('date')->value;
      if ($existingStart === NULL || $existingStart === '') {
        continue;
      }
      if (strtotime($existingStart . ' UTC') !== $requestedStart) {
        continue;
      }
      $isCancelledTwin = $existingInstance->get('moderation_state')->value === 'archived'
        && (bool) $existingInstance->get('individually_cancelled')->value;
      if ($isCancelledTwin) {
        return $this->refuse('duplicate_date', 'An occurrence already exists at this date; it is cancelled — use restore_occurrence to bring it back instead of adding a duplicate.', 409);
      }
      return $this->refuse('duplicate_date', 'An occurrence already exists at this date.', 409);
    }

    // The real contrib create chain: createEventInstance() returns an UNSAVED
    // instance whose moderation_state the birth-state alter has already set
    // from the parent's published/archived status; configureDefaultInheritances()
    // wires the computed inherited fields (title, description, …); save()
    // persists it. No rebuild, no validate() — a direct create touches nothing
    // but this one new row.
    $start = new \Drupal\Core\Datetime\DrupalDateTime($startValue, 'UTC');
    $end = new \Drupal\Core\Datetime\DrupalDateTime($endValue, 'UTC');
    /** @var \Drupal\recurring_events\EventCreationService $creationService */
    $creationService = \Drupal::service('recurring_events.event_creation_service');
    $instance = $creationService->createEventInstance($eventseries, $start, $end);
    $creationService->configureDefaultInheritances($instance, (int) $eventseries->id());
    $instance->save();

    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $eventseries->id(),
      'eventinstance_id' => (int) $instance->id(),
      'moderation_state' => $instance->get('moderation_state')->value,
    ]);
  }

  /**
   * Loads a series' default-revision instances via a fresh entity query.
   *
   * Never the computed event_instances field, which can reflect a stale
   * in-memory set on an entity that was loaded before a sibling instance was
   * added elsewhere in the same request. Used by addOccurrence()'s duplicate-
   * start check.
   *
   * @param int $seriesId
   *   The eventseries id.
   *
   * @return \Drupal\recurring_events\Entity\EventInstance[]
   *   The series' event instances.
   */
  private function loadInstancesForSeries(int $seriesId): array {
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    $ids = $storage->getQuery()
      ->condition('eventseries_id', $seriesId)
      ->accessCheck(FALSE)
      ->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

  /**
   * Whether any revision of the entity was ever in the published state.
   *
   * A never-published draft has only draft revisions, so this returns FALSE and
   * the caller hard-deletes rather than archives. eventseries is revisionable
   * (revision table eventseries_revision), so this scans revisions for a
   * published moderation_state.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to inspect.
   *
   * @return bool
   *   TRUE if any revision's moderation_state was 'published'.
   */
  private function wasEverPublished(ContentEntityInterface $entity): bool {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $vids = $storage->getQuery()
      ->allRevisions()
      ->condition($entity->getEntityType()->getKey('id'), $entity->id())
      ->accessCheck(FALSE)
      ->execute();
    foreach (array_keys($vids) as $vid) {
      $rev = $storage->loadRevision($vid);
      if ($rev && $rev->get('moderation_state')->value === 'published') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Resolves a series id OR an instance id to its EventSeries.
   *
   * The shared normalizer every series tool uses. It accepts:
   *  - an EventSeries entity (the route converter already resolved it) —
   *    returned as-is;
   *  - a series id — loaded directly;
   *  - an instance id — resolved to its parent series via the instance's
   *    eventseries_id, giving the MCP-side tools id flexibility (a caller may
   *    hold an instance id and still target the series).
   *
   * @param int|string|\Drupal\recurring_events\Entity\EventSeries $idOrInstance
   *   A series id, an instance id, or an already-resolved series entity.
   *
   * @return \Drupal\recurring_events\Entity\EventSeries|null
   *   The resolved series, or NULL if nothing matches.
   */
  private function resolveSeries($idOrInstance): ?EventSeries {
    if ($idOrInstance instanceof EventSeries) {
      return $idOrInstance;
    }
    $seriesStorage = $this->entityTypeManager->getStorage('eventseries');
    $series = $seriesStorage->load($idOrInstance);
    if ($series instanceof EventSeries) {
      return $series;
    }
    // Not a series id — try it as an instance id and follow eventseries_id.
    $instance = $this->entityTypeManager->getStorage('eventinstance')->load($idOrInstance);
    if ($instance instanceof EventInstance) {
      $parent = $seriesStorage->load($instance->get('eventseries_id')->target_id);
      if ($parent instanceof EventSeries) {
        return $parent;
      }
    }
    return NULL;
  }

  /**
   * Builds the review-needed signal for a saved series.
   *
   * Reads the acting user's valid transitions off the series and derives the
   * signal from ground truth only — never a hardcoded role/permission check.
   * can_publish reflects whatever transition the workflow config actually names
   * to reach published (e.g. "publish"), so a future rename or added role still
   * resolves correctly here. next_action tells a caller who cannot publish that
   * the draft can instead be sent for editor review.
   */
  private function buildModerationBlock(EventSeries $series): array {
    $validTransitions = $this->transitionValidation->getValidTransitions($series, $this->currentUser());
    $transitionIds = array_map(fn ($t) => $t->id(), $validTransitions);
    $canPublish = in_array('publish', $transitionIds, TRUE);
    $canSendForReview = in_array('send_for_review', $transitionIds, TRUE);
    $nextAction = (!$canPublish && $canSendForReview) ? 'send_for_review' : NULL;
    return [
      'state' => $series->get('moderation_state')->value,
      'can_publish' => $canPublish,
      'next_action' => $nextAction,
      'message' => $canPublish
        ? NULL
        : "Saved as a draft. You can't publish it directly — send it for review and an editor will approve and publish it.",
    ];
  }

  /**
   * Maps the API recur-date params onto the entity date fields.
   *
   * This method is the mapping boundary: the API param name (custom_dates,
   * start_date/end_date) intentionally differs from the entity field
   * (custom_date, value/end_value). For a custom recur_type it writes the
   * singular custom_date daterange field, one row per requested date; for a
   * rule recur_type it writes the matching *_recurring_date field.
   *
   * @param array $values
   *   The values array being built (by reference).
   * @param array $body
   *   The decoded request body.
   */
  private function applyRecurDates(array &$values, array $body): void {
    $recurType = $body['recur_type'] ?? '';
    if ($recurType === 'custom') {
      $dates = [];
      foreach ($body['custom_dates'] ?? [] as $date) {
        if (empty($date['start_date']) || empty($date['end_date'])) {
          continue;
        }
        $dates[] = [
          'value' => $date['start_date'],
          'end_value' => $date['end_date'],
        ];
      }
      $values['custom_date'] = $dates;
      return;
    }
    // Rule recur_type (e.g. weekly_recurring_date): pass the matching rule
    // field straight through when the caller supplies it.
    $ruleField = $recurType;
    if ($ruleField !== '' && array_key_exists($ruleField, $body)) {
      $values[$ruleField] = $body[$ruleField];
    }
  }

  /**
   * Copies the whitelisted content fields from the request body into $values.
   *
   * body is handled specially so its text format is pinned to basic_html.
   * moderation_state / status are never copied — create locks the series to
   * draft.
   *
   * @param array $values
   *   The values array being built (by reference).
   * @param array $body
   *   The decoded request body.
   */
  private function applyContentFields(array &$values, array $body): void {
    foreach (self::CONTENT_ATTRIBUTES as $field) {
      if (array_key_exists($field, $body)) {
        // The browser edit forms hide domain_access from anyone without the
        // domain-administration permission (only administrators may set it
        // there), so an API caller who lacks that permission must not be able
        // to set it either. Silently drop just this field for such callers,
        // leaving every other content field applied as usual.
        if ($field === 'domain_access' && !$this->currentUser()->hasPermission('administer domains')) {
          continue;
        }
        $values[$field] = $body[$field];
      }
    }
    if (array_key_exists('body', $body)) {
      $bodyIn = $body['body'];
      $value = is_array($bodyIn) ? ($bodyIn['value'] ?? '') : (string) $bodyIn;
      $values['body'] = ['value' => $value, 'format' => 'basic_html'];
    }
  }

  /**
   * Resolves affinity-group UUIDs to loaded affinity_group nodes.
   *
   * The MCP sends affinity groups as node UUIDs. Unknown UUIDs are dropped,
   * which makes the coordinator check fail closed (a group the user cannot be a
   * coordinator of is not silently written to).
   *
   * @param mixed $uuids
   *   A list of node UUID strings (or a scalar for a single value).
   *
   * @return \Drupal\node\NodeInterface[]
   *   The loaded affinity_group nodes.
   */
  private function resolveGroupNodes($uuids): array {
    if (!is_array($uuids)) {
      $uuids = ($uuids === NULL || $uuids === '') ? [] : [$uuids];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = [];
    foreach ($uuids as $uuid) {
      if (!is_string($uuid) || $uuid === '') {
        continue;
      }
      $matches = $storage->loadByProperties(['uuid' => $uuid, 'type' => 'affinity_group']);
      if ($matches) {
        $nodes[] = reset($matches);
      }
    }
    return $nodes;
  }

  /**
   * A private, uncacheable success response.
   */
  private function success(array $payload): JsonResponse {
    return (new JsonResponse($payload))->setPrivate()->setMaxAge(0);
  }

  /**
   * Builds a refusal JsonResponse with the canonical {error, message} body.
   */
  private function refuse(string $code, string $message, int $status): JsonResponse {
    return (new JsonResponse(['error' => $code, 'message' => $message], $status))
      ->setPrivate()
      ->setMaxAge(0);
  }

}
