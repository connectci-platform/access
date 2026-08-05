<?php

namespace Drupal\access_events\Controller;

use Drupal\access_affinitygroup\Access\CoordinatorAccess;
use Drupal\access_events\EventAccessHelper;
use Drupal\access_events\RegistrantCounter;
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
   * Constructs the controller.
   */
  public function __construct(
    EventAccessHelper $access_helper,
    CoordinatorAccess $coordinator_access,
    RegistrantCounter $registrant_counter,
    EntityTypeManagerInterface $entity_type_manager,
    StateTransitionValidationInterface $transition_validation,
    ModerationInformationInterface $moderation_information,
  ) {
    $this->accessHelper = $access_helper;
    $this->coordinatorAccess = $coordinator_access;
    $this->registrantCounter = $registrant_counter;
    $this->entityTypeManager = $entity_type_manager;
    $this->transitionValidation = $transition_validation;
    $this->moderationInformation = $moderation_information;
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
   * Gates run in a fixed order so no partial write happens on a refusal:
   *  1. acting-uid guard;
   *  2. resolveSeries (404 if not found);
   *  3. userMayManageSeries('delete') entity-access gate;
   *  4. compute everPublished + registrant count;
   *  5. preview unless confirmed (writes nothing);
   *  6. registrant protection (refused without force — writes nothing);
   *  7. never-published draft → hard delete;
   *  8. else → the `archive` transition-permission gate, THEN archive.
   *
   * @param int|\Drupal\recurring_events\Entity\EventSeries $eventseries
   *   The resolved series (route converter) or a raw series/instance id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; confirmed/force are read from the query string.
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
    $force = filter_var($request->query->get('force'), FILTER_VALIDATE_BOOLEAN);
    $registrants = $this->registrantCounter->countForSeries((int) $series->id());
    $everPublished = $this->wasEverPublished($series);

    // Preview (no confirmed): describe what would happen, write nothing.
    if (!$confirmed) {
      return $this->success([
        'status' => 'preview',
        'executed' => FALSE,
        'series_id' => (int) $series->id(),
        'would_archive' => $everPublished,
        'would_hard_delete' => !$everPublished,
        'registrants_affected' => $registrants,
      ]);
    }

    // Registrant protection: refuse unless force. Writes nothing.
    if ($registrants > 0 && !$force) {
      return $this->refuse('has_registrations', sprintf('%d registrant(s) are attached to this event. Pass force=true to proceed; their records are kept on archive.', $registrants), 409);
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

    $archived = $this->archiveSeriesWithInstances($series);
    return $this->success([
      'success' => TRUE,
      'series_id' => (int) $series->id(),
      'instances_archived' => $archived,
      'registrants_affected' => $registrants,
      'hard_deleted' => FALSE,
    ]);
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
   * @return int
   *   The number of instances archived.
   */
  private function archiveSeriesWithInstances(EventSeries $series): int {
    $count = 0;
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
      $instance->set('moderation_state', 'archived')->save();
      $count++;
    }
    $series->set('moderation_state', 'archived')->save();
    return $count;
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
