<?php

namespace Drupal\access_events\Controller;

use Drupal\access_affinitygroup\Access\CoordinatorAccess;
use Drupal\access_events\CancellationNotifier;
use Drupal\access_events\EventAccessHelper;
use Drupal\access_events\RegistrantCounter;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
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
   * The cancellation notifier.
   *
   * @var \Drupal\access_events\CancellationNotifier
   */
  protected CancellationNotifier $cancellationNotifier;

  /**
   * The series-cancel keyvalue store — series id => int[] of instance ids
   * archived by that series-level cancel.
   *
   * This is the machine authority a later restore reads to know which
   * instances IT is responsible for republishing, as distinct from an
   * instance a coordinator cancelled on its own beforehand (which must stay
   * cancelled through a series cancel/restore cycle).
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected KeyValueStoreInterface $seriesCancelStore;

  /**
   * The time service — for gating editOccurrence's confirm on the
   * prospective new date the same way contrib's notification hook does.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

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
    CancellationNotifier $cancellation_notifier,
    KeyValueFactoryInterface $key_value_factory,
    TimeInterface $time,
  ) {
    $this->accessHelper = $access_helper;
    $this->coordinatorAccess = $coordinator_access;
    $this->registrantCounter = $registrant_counter;
    $this->entityTypeManager = $entity_type_manager;
    $this->transitionValidation = $transition_validation;
    $this->moderationInformation = $moderation_information;
    $this->cancellationNotifier = $cancellation_notifier;
    $this->seriesCancelStore = $key_value_factory->get('access_events.series_cancel');
    $this->time = $time;
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
      $container->get('access_events.cancellation_notifier'),
      $container->get('keyvalue'),
      $container->get('datetime.time'),
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

    // Resolve the requested affinity groups for the coordinator gate (create
    // requires an AG).
    $groupNodes = $this->resolveGroupNodes($body['field_affinity_group_node'] ?? []);
    if (empty($groupNodes)) {
      return $this->refuse('validation_error', 'field_affinity_group_node is required to create an event.', 422);
    }

    $values = [
      'type' => 'default',
      'uid' => $uid,
      // Draft-only, hardcoded. Any caller moderation_state is ignored.
      'moderation_state' => 'draft',
      'title' => $body['title'],
      'field_affinity_group_node' => array_map(fn (NodeInterface $n) => $n->id(), $groupNodes),
      'recur_type' => $body['recur_type'],
    ];
    // Maps the API custom_dates param to the entity custom_date field, or the
    // matching *_recurring_date field for a rule recur_type.
    $this->applyRecurDates($values, $body);
    // Copies the whitelisted content fields (body, field_summary, …).
    $this->applyContentFields($values, $body);

    // Coordinator gate against the REQUESTED groups, before the series exists —
    // a controller-level check against $groupNodes directly, not
    // EventAccessHelper::userMayManageSeries (there is no saved series to call
    // $series->access($op) on yet).
    if (!$this->coordinatorAccess->userCoordinatesAllGroups($user, $groupNodes)) {
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
   * transition op, gated separately) and never touches recurrence config (that
   * is the destructive update_recurrence path). Any caller-supplied
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
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolveSeries (404 if not found);
   *  3. userMayManageSeries('delete') entity-access gate;
   *  4. compute everPublished + future registrant count;
   *  5. preview unless confirmed (writes nothing);
   *  6. never-published draft → hard delete;
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
    $registrants = $this->registrantCounter->countFutureForSeries((int) $series->id());
    $everPublished = $this->wasEverPublished($series);

    // Preview (no confirmed): describe what would happen, write nothing. The
    // never-published branch hard-deletes the series (cascading its
    // instances' registrant rows via the recurring_events predelete hook),
    // and the instance-deletion notification key is disabled — so those
    // registrants are never told. Say so plainly here, before the coordinator
    // confirms; the archive path keeps registrations, so it carries no such
    // warning.
    if (!$confirmed) {
      $preview = [
        'status' => 'preview',
        'executed' => FALSE,
        'series_id' => (int) $series->id(),
        'would_archive' => $everPublished,
        'would_hard_delete' => !$everPublished,
        'registrants_affected' => $registrants,
      ];
      if (!$everPublished && $registrants > 0) {
        $preview['warning'] = sprintf(
          'This draft has %d registration(s) that will be PERMANENTLY REMOVED. Registrants will NOT be notified.',
          $registrants,
        );
      }
      return $this->success($preview);
    }

    if (!$everPublished) {
      // Never-published draft: hard delete. The recurring_events predelete hook
      // cascades the instance deletes. There is no legal archive transition
      // FROM draft, so there is nothing for state_transition_validation to check.
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
    // mirrors archiveSeriesWithInstances(), which likewise acts only on the
    // published → archived transition and skips non-published instances.
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

    // Write-ahead: record the candidate set (currently-published instances)
    // BEFORE archiving, so a restore reads a value even if the process dies
    // mid-archive. The series save is archiveSeriesWithInstances()'s LAST
    // operation — if the process dies before it, the series is still
    // 'published' (or whatever it was), so restore()'s archived-state guard
    // refuses invalid_state rather than reading this memory at all; a
    // retried delete overwrites the entry cleanly. Writing AFTER the archive
    // instead would leave a crash window where the archive completes (series
    // saved) but the memory write never happens — restore would then find no
    // entry and fall back to "republish everything", wrongly reviving any
    // instance an earlier cancelOccurrence had individually archived. An
    // over-broad memory written here is safe either way: restore intersects
    // it with whatever is CURRENTLY archived.
    $candidateIds = [];
    foreach ($series->get('event_instances')->referencedEntities() as $instance) {
      if ($instance->get('moderation_state')->value === 'published') {
        $candidateIds[] = (int) $instance->id();
      }
    }
    $this->seriesCancelStore->set((int) $series->id(), $candidateIds);

    $archivedIds = $this->archiveSeriesWithInstances($series);
    // Reconcile: overwrite with the actually-archived set, in case a skip
    // (e.g. a transition-permission divergence) narrowed it from the
    // candidate set computed above.
    $this->seriesCancelStore->set((int) $series->id(), $archivedIds);

    // Keep registrations; notify only the instances just archived — NOT every
    // future instance of the series. An instance already cancelled via
    // cancelOccurrence was notified then; renotifying here would double-send.
    // An instance left in draft/needs_adjustment was never archived at all;
    // notifying it would falsely tell its registrants the event was cancelled.
    $notified = $this->cancellationNotifier->notifyInstances($archivedIds, CancellationNotifier::KEY);
    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'instances_archived' => count($archivedIds),
      'registrants_affected' => $registrants,
      'notified' => $notified,
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
   * On success, notifies the just-republished instances' registrants that the
   * event is back on (CancellationNotifier::notifyInstances, keyed under
   * REINSTATE_KEY, send-only) and reports the count as `notified` in the
   * envelope.
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

    // The series-cancel memory (written by delete()'s archive path) is the
    // machine authority for which instances THIS series' cancel archived, as
    // opposed to one a coordinator cancelled individually beforehand. NULL
    // means no entry — either this series predates the feature or the
    // memory was already consumed by an earlier restore — and
    // restoreSeriesWithInstances() falls back to republishing every archived
    // instance, today's behavior. An empty array is NOT the same as NULL: it
    // means every instance was already individually cancelled when the
    // series was archived, so the series itself republishes but zero
    // instances do.
    $remembered = $this->seriesCancelStore->get((int) $series->id(), NULL);
    $restoredIds = $this->restoreSeriesWithInstances($series, $remembered);
    // The memory is single-use: once consumed by a restore, delete it so a
    // later re-archive/re-restore cycle starts clean rather than reading a
    // stale set.
    $this->seriesCancelStore->delete((int) $series->id());
    // Notify only the instances just republished — NOT every future instance
    // of the series — for the same reason delete() scopes to archivedIds: an
    // instance that was never archived should not get a "this event is back
    // on" notice for an event it was never told was cancelled.
    $notified = $this->cancellationNotifier->notifyInstances($restoredIds, CancellationNotifier::REINSTATE_KEY);
    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'instances_restored' => count($restoredIds),
      'notified' => $notified,
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
   * Soft-cancels a SINGLE event instance by ARCHIVING it: the targeted instance
   * transitions to moderation_state = archived while its sibling instances and
   * the parent series are left untouched. This is the instance-level equivalent
   * of delete()'s archive branch, so it is gated IDENTICALLY — scoped to the one
   * instance. A bare set('moderation_state','archived')->save() would bypass
   * content_moderation validation and let any user passing the coordinator check
   * archive an instance regardless of whether they hold the `archive`
   * transition, making cancel MORE permissive than delete; the transition gate
   * below closes that hole.
   *
   * Instance-level authorization resolves via the instance's PARENT series: the
   * series carries the affinity group whose coordinators may manage its
   * occurrences, and userMayManageSeries() operates on a series. 'delete' is the
   * op — cancelling an occurrence is delete-shaped (it archives).
   *
   * The registration on this instance is always KEPT on cancel — there is no
   * force-to-destroy gate on this path. Existing registrants are notified
   * after the archive succeeds, if the instance has not yet ended (send-only;
   * see CancellationNotifier).
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolve the instance (404 if not found);
   *  3. userMayManageSeries('delete') on the parent series (entity-access gate);
   *  4. compute future registrant count;
   *  5. preview unless confirmed (writes nothing);
   *  6. the archived-state guard (hasTransitionFromStateToState) on the
   *     editorial_eventinstance workflow — an already-archived instance is an
   *     idempotent no-op; a non-published one refuses invalid_state — BEFORE
   *     isTransitionValid, which would otherwise throw \InvalidArgumentException
   *     (HTTP 500) on a missing transition;
   *  7. the `archive` transition-permission gate, THEN archive + notify.
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

    // The instance uses the editorial_eventinstance workflow, whose only path to
    // `archived` is the `archive` transition from `published`. Guard on the
    // transition EXISTING from the current state before validating it: the
    // non-throwing hasTransitionFromStateToState() avoids the
    // \InvalidArgumentException that isTransitionValid() →
    // getTransitionFromStateToState() throws (→ HTTP 500) when the transition is
    // absent, AND avoids wrongly archiving a non-published instance. Mirrors
    // delete()'s archive guard, scoped to one instance.
    $workflow = $this->moderationInformation->getWorkflowForEntity($eventinstance);
    $fromState = $this->moderationInformation->getOriginalState($eventinstance);
    if (!$workflow->getTypePlugin()->hasTransitionFromStateToState($fromState->id(), 'archived')) {
      // Already archived: effectively already cancelled — an idempotent no-op
      // success reflecting reality, not a 500 or a confusing error.
      if ($fromState->id() === 'archived') {
        return $this->success([
          'success' => TRUE,
          'eventinstance_id' => (int) $eventinstance->id(),
          'registrants_affected' => $registrants,
          'notified' => 0,
        ]);
      }
      // Draft / any other non-published state: there is no legal archive
      // transition from here — only a published occurrence can be cancelled.
      return $this->refuse('invalid_state', 'This occurrence is not published; only a published occurrence can be cancelled.', 409);
    }

    // Entity access (above) does not check moderation-transition permissions —
    // those are separate `use editorial_eventinstance transition <name>` grants.
    // Verify the acting user actually holds the `archive` transition (published
    // → archived) on the instance workflow before touching anything; never
    // hardcode a role/permission string. isTransitionValid takes StateInterface
    // OBJECTS, not string ids. Per the real config only news_pm/administrator
    // hold `archive` on editorial_eventinstance, so this refuses the common case
    // (author, AG-leader) — the SAME operation and roster delete gates.
    $toState = $workflow->getTypePlugin()->getState('archived');
    if (!$this->transitionValidation->isTransitionValid($workflow, $fromState, $toState, $this->currentUser(), $eventinstance)) {
      return $this->refuse('forbidden', 'You may not cancel this occurrence.', 403);
    }

    $eventinstance->set('moderation_state', 'archived')->save();
    // Keep the registration; notify affected future registrants (send-only).
    $notified = $this->cancellationNotifier->notifyInstanceCancelled((int) $eventinstance->id());
    return $this->success([
      'success' => TRUE,
      'eventinstance_id' => (int) $eventinstance->id(),
      'registrants_affected' => $registrants,
      'notified' => $notified,
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
   * registered needs to hear about a move. contrib's own
   * recurring_events_registration_entity_update() decides whether to notify
   * based on the NEW date, checked AFTER save
   * ($entity->date->end_date->getTimestamp() > time()) — not the old stored
   * date. So the confirm gate here must mirror that: it is keyed on the
   * PROSPECTIVE new end timestamp (parsed from the request body), not
   * countFutureForInstance() against the instance's current (pre-save) date.
   * Gating on the old date would silently mass-notify a past→future move
   * (the hook fires post-save against the new future date; the old-date gate
   * saw nothing to confirm) and would falsely promise a notify on a
   * future→past correction (the hook's post-save check sees the date is now
   * past and never fires, but the old-date gate reported registrants as
   * "to notify"). If the request changes `date` and the PROSPECTIVE new date
   * is future and the instance has registrants, the edit requires
   * confirmed=TRUE and previews first (no write), reporting
   * registrants_to_notify. A date change to the past, a date change with no
   * registrants, or a location-only edit all proceed without confirmation —
   * there is either nothing to notify or no reschedule risk. On a confirmed
   * date change, the save itself is what registrants are notified of: the
   * contrib hook enqueues instance_modification_notification whenever the
   * date field actually changed AND the new date is future, so this fires
   * identically for this endpoint and for the node/eventinstance edit form —
   * this controller does not separately enqueue anything. The population the
   * hook notifies is EVERY registrant of the instance (retrieveRegisteredParties(),
   * not future-scoped), which is why the gate/report below use
   * RegistrantCounter::countForInstance(), not countFutureForInstance().
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
    // The population the contrib hook notifies is every registrant of the
    // instance (retrieveRegisteredParties(), not future-scoped) — see the
    // method docblock. countForInstance(), not countFutureForInstance().
    $registrants = $this->registrantCounter->countForInstance((int) $eventinstance->id());

    // A date move strands whoever is already registered if nobody tells
    // them. Only an actual date CHANGE carries that risk — compare against
    // the current stored value the same way
    // recurring_events_registration_entity_update() does, so this gate and
    // the module's own notification hook agree on what counts as a
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

    // Preview (no confirmed): describe what would happen, write nothing.
    if ($changesDate && $newDateIsFuture && $registrants > 0 && !$confirmed) {
      return $this->success([
        'status' => 'preview',
        'executed' => FALSE,
        'eventinstance_id' => (int) $eventinstance->id(),
        'date_changes' => TRUE,
        'registrants_to_notify' => $registrants,
      ]);
    }

    // Content-only override on the one instance. date is the instance's own
    // daterange; field_location is its location string. Nothing else is writable
    // here — a recur-config change is the add_occurrence / update_recurrence path.
    if (array_key_exists('date', $body)) {
      $eventinstance->set('date', $body['date']);
    }
    if (array_key_exists('field_location', $body)) {
      $eventinstance->set('field_location', $body['field_location']);
    }
    // Saving here is what a registered future registrant gets notified of: if
    // the date actually changed, recurring_events_registration_entity_update()
    // (hook_entity_update() on eventinstance) enqueues
    // instance_modification_notification for every registrant on this
    // instance — the SAME hook fires whether this save comes from this API or
    // from the eventinstance edit form, so no separate enqueue happens here.
    $eventinstance->save();

    // Report the count only when the hook will actually fire (changesDate AND
    // newDateIsFuture — the exact condition the hook checks post-save).
    // Otherwise report 0: a future→past correction must not promise a notify
    // that contrib's hook will never send once it sees the new date is past.
    $notifiedCount = ($changesDate && $newDateIsFuture) ? $registrants : 0;

    return $this->success([
      'success' => TRUE,
      'eventinstance_id' => (int) $eventinstance->id(),
      'registrants_affected' => $notifiedCount,
    ]);
  }

  /**
   * POST /api/2.3/event-series/{eventseries}/occurrence — add one occurrence.
   *
   * Appends a one-off date to a CUSTOM series by writing the singular custom_date
   * daterange field. The API params start_date/end_date map onto the field's
   * value/end_value keys (the same mapping boundary applyRecurDates() draws for
   * create). The existing custom_date rows are read, the new row appended, and
   * the field set back — so the append is additive, not a replace.
   *
   * REFUSAL — rule series: a rule-based series (weekly_recurring_date, …) is
   * refused recurrence_conflict. You cannot append a one-off date to a rule
   * series: its dates come from the recurrence rule, and included_dates is a
   * whitelist FILTER over rule-generated dates, not an append surface. Changing a
   * rule series' dates is the update_recurrence path.
   *
   * REFUSAL — draft series: a draft custom series' recur-config change does not
   * fire the module recreate (it is gated on published), so appending a
   * custom_date would not materialize a visible instance. Refused invalid_state
   * rather than reporting a phantom success.
   *
   * DESTRUCTIVE — published custom recreate: appending a date to a PUBLISHED
   * custom series is a recur-config change, and recurring_events fires its
   * RecreateEventInstanceCreator on such a change — a HARD-DELETE + recreate of
   * ALL the series' instances, which would destroy any attached registrants. The
   * append is applied to the entity in memory and then validated: the
   * EventSeriesRescheduleBlock constraint refuses the save (has_registrations)
   * when the series has FUTURE registrants. Past-only registrants do not block —
   * a rebuild cannot harm a registrant whose instance already ended.
   *
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. userMayManageSeries('update') entity-access gate (appending is an update);
   *  3. rule-series refusal (recurrence_conflict) — writes nothing;
   *  4. body validation (start_date/end_date required);
   *  5. draft-series refusal (invalid_state) — writes nothing;
   *  6. append the custom_date in memory, then validate(): refused
   *     (has_registrations) when the reschedule-block constraint objects;
   *     otherwise save (which regenerates instances, since the series is
   *     published).
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   In production the route converter supplies the resolved EventSeries; the
   *   direct-dispatch test path supplies a raw series id. Both are accepted.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; start_date/end_date in the JSON body.
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

    // Appending a date is an update to the series' recur config — gate on
    // 'update'. userMayManageSeries('update') already calls $series->access(
    // 'update') internally, so no separate entity-access check is needed here.
    if (!$this->accessHelper->userMayManageSeries($eventseries, 'update')) {
      return $this->refuse('not_coordinator', 'You may not add an occurrence.', 409);
    }

    // Rule-series refusal: a one-off cannot be appended to a rule series.
    if ($eventseries->getRecurType() !== 'custom') {
      return $this->refuse('recurrence_conflict', 'This event uses a recurrence rule; use update_recurrence to change its dates.', 409);
    }

    $body = json_decode($request->getContent(), TRUE) ?: [];
    if (empty($body['start_date']) || empty($body['end_date'])) {
      return $this->refuse('validation_error', 'start_date and end_date are required.', 422);
    }

    // Draft series: appending a custom_date does NOT materialize an instance
    // (the module recreate is gated on published), so refuse rather than report
    // a phantom success.
    $isPublished = $eventseries->get('moderation_state')->value === 'published';
    if (!$isPublished) {
      return $this->refuse('invalid_state', 'This event is a draft; publish it before adding occurrences. (A draft add would not create a visible occurrence.)', 409);
    }

    // Append the new custom_date, then validate: the reschedule-block constraint
    // refuses the save when the series has future registrants (a date change
    // would destroy them). validate() surfaces that as a violation.
    $existing = $eventseries->get('custom_date')->getValue();
    $existing[] = ['value' => $body['start_date'], 'end_value' => $body['end_date']];
    $eventseries->set('custom_date', $existing);
    $violations = $eventseries->validate();
    if ($violations->count() > 0) {
      return $this->refuse('has_registrations', 'This event has registrations; its dates cannot be changed. Cancel and recreate to change the schedule.', 409);
    }
    try {
      $eventseries->save();
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      // A registrant on an instance with a NULL/malformed end date is invisible
      // to the constraint's SQL count, so the save can pass validation and the
      // rebuild plugin's last-resort registrant check aborts mid-save instead.
      // Core wraps that abort (and rolls the save back — nothing was deleted or
      // changed); surface it as the same refusal the constraint gives rather
      // than an unhandled 500. Any other storage failure is not ours to mask.
      if ($e->getPrevious() instanceof \RuntimeException
        && str_contains($e->getMessage(), 'registration')) {
        return $this->refuse('has_registrations', 'This event has a registration on an occurrence without a valid end date; its dates cannot be changed. Cancel and recreate to change the schedule.', 409);
      }
      throw $e;
    }

    $reloaded = $this->entityTypeManager->getStorage('eventseries')->loadUnchanged($eventseries->id());
    $instanceIds = array_map(
      fn ($i) => (int) $i->id(),
      $reloaded->get('event_instances')->referencedEntities(),
    );

    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $eventseries->id(),
      'instance_ids' => $instanceIds,
    ]);
  }

  /**
   * Republishes an archived series and each of its archived instances.
   *
   * The inverse of archiveSeriesWithInstances(): the series republish does NOT
   * cascade to instances, so each archived instance is republished explicitly.
   * It is gated against the INSTANCE's OWN workflow (editorial_eventinstance),
   * not the series' editorial workflow — the two are distinct
   * content_moderation workflows with independently-granted transition
   * permissions. The series-level `archived_published` gate in restore() already
   * ran, so only news_pm/administrator reach this method; both also hold
   * `archived_published` on editorial_eventinstance, so a permitted user never
   * lands in a partial state. Each instance is still validated rather than
   * assumed — a future permission divergence must not silently transition an
   * instance the user does not hold the grant for. Only instances currently
   * `archived` are republished; others are skipped (never throwing), mirroring
   * archiveSeriesWithInstances().
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The series to restore.
   * @param int[]|null $only
   *   The instance ids THIS series-level cancel archived (from the
   *   series-cancel keyvalue memory), or NULL. NULL means no filter — the
   *   legacy fallback that republishes every archived instance, for a series
   *   with no memory entry (predates this feature, or the memory was already
   *   consumed). An empty array is NOT NULL: it means every instance was
   *   already individually cancelled when the series was archived, so zero
   *   instances should republish here even though the series itself does.
   *   Conflating [] with missing would wrongly fall back to
   *   republish-everything and revive instances the series cancel never
   *   touched.
   *
   * @return int[]
   *   The ids of the instances actually republished by this call. Callers
   *   notify exactly this set — not "every future instance of the series" —
   *   so an instance that was never archived (e.g. left in draft) does not
   *   get a false "this event is back on" notice.
   */
  private function restoreSeriesWithInstances(EventSeries $series, ?array $only = NULL): array {
    $restoredIds = [];
    foreach ($series->get('event_instances')->referencedEntities() as $instance) {
      if ($instance->get('moderation_state')->value !== 'archived') {
        continue;
      }
      // Restrict to the set THIS series cancel archived. An instance
      // cancelled individually beforehand (cancelOccurrence) is archived but
      // not in $only, so it is skipped here and stays archived through the
      // series cancel/restore cycle.
      if ($only !== NULL && !in_array((int) $instance->id(), $only, TRUE)) {
        continue;
      }
      $instanceWorkflow = $this->moderationInformation->getWorkflowForEntity($instance);
      $instanceFromState = $this->moderationInformation->getOriginalState($instance);
      if (!$instanceWorkflow->getTypePlugin()->hasTransitionFromStateToState('archived', 'published')) {
        // No archived → published transition on this instance's workflow; skip
        // rather than throw. Not expected for a state-archived instance.
        continue;
      }
      $instanceToState = $instanceWorkflow->getTypePlugin()->getState('published');
      if (!$this->transitionValidation->isTransitionValid($instanceWorkflow, $instanceFromState, $instanceToState, $this->currentUser(), $instance)) {
        // Not expected for a user who passed the series gate; skip rather than
        // fail the whole op.
        continue;
      }
      $instance->set('moderation_state', 'published')->save();
      $restoredIds[] = (int) $instance->id();
    }
    $series->set('moderation_state', 'published')->save();
    return $restoredIds;
  }

  /**
   * Archives a published series and each of its published instances.
   *
   * The series archive does NOT cascade to instances, so each instance is
   * archived explicitly. It is gated against the INSTANCE's OWN workflow
   * (editorial_eventinstance), not the series' editorial workflow — the two are
   * distinct content_moderation workflows with independently-granted transition
   * permissions. The series-level `archive` gate in delete() already ran, so
   * only news_pm/administrator reach this method; both also hold `archive` on
   * editorial_eventinstance, so a permitted user never lands in a partial
   * state. Each instance is still validated rather than assumed — a future
   * permission divergence between the two workflows must not silently
   * transition an instance the user does not hold the grant for.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $series
   *   The series to archive.
   *
   * @return int[]
   *   The ids of the instances actually archived by this call. Callers notify
   *   exactly this set — not "every future instance of the series" — so an
   *   instance that was already individually cancelled (via cancelOccurrence,
   *   whose registrants were notified then) does not get a second, duplicate
   *   notice here, and an instance left in a non-published state (draft,
   *   needs_adjustment — not archived at all) does not get a false
   *   "cancelled" notice.
   */
  private function archiveSeriesWithInstances(EventSeries $series): array {
    $archivedIds = [];
    foreach ($series->get('event_instances')->referencedEntities() as $instance) {
      if ($instance->get('moderation_state')->value !== 'published') {
        continue;
      }
      $instanceWorkflow = $this->moderationInformation->getWorkflowForEntity($instance);
      $instanceFromState = $this->moderationInformation->getOriginalState($instance);
      $instanceToState = $instanceWorkflow->getTypePlugin()->getState('archived');
      if (!$this->transitionValidation->isTransitionValid($instanceWorkflow, $instanceFromState, $instanceToState, $this->currentUser(), $instance)) {
        // Not expected for a user who passed the series gate; skip rather than
        // fail the whole op.
        continue;
      }
      // content_moderation already forces a new revision on this save, so the
      // log message costs nothing extra — it is a breadcrumb for a human
      // debugging "why did this instance stay cancelled after a series
      // restore" at /events/{id}/revisions. The series-cancel keyvalue
      // memory is the machine authority restore() actually reads; this is
      // not it.
      $instance->setRevisionLogMessage('Archived by series cancel of series ' . $series->id());
      $instance->set('moderation_state', 'archived')->save();
      $archivedIds[] = (int) $instance->id();
    }
    $series->set('moderation_state', 'archived')->save();
    return $archivedIds;
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
