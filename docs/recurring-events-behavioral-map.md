# recurring_events Behavioral Map (verified against source, 2026-08-08)

Ground truth for the event state-orchestration design. Every claim carries file:line from
`web/modules/contrib/recurring_events/` (module version 2.0.3 + site patches) or our
`web/modules/custom/access/modules/access_events/`. Read basis: full spine read (both .module
files, EventCreationService, both entity classes, EventSeriesForm, EventInstanceForm,
RegistrationCreationService, RegistrantForm::save) + breadth inventory (hooks/routing/services/
api.php/schemas/queue worker/route subscriber). NOT yet exhaustively read: clone forms,
revision-revert forms (except the bare-save fact), 9 of 10 contrib tests, views/field plugins.

## 1. Entity state machinery — where instance state actually comes from

There is NO state logic in the entity classes (EventSeries.php / EventInstance.php: preCreate
sets uid; preSave sets owner + revision-author defaults; nothing else). Instance state is
produced by exactly three mechanisms:

1. **EntityPublishedTrait default** — both entities extend EditorialContentEntityBase;
   `status` defaults TRUE (born published) when no moderation workflow governs the bundle.
2. **content_moderation workflow default** — when a workflow governs the bundle, new
   entities take `default_moderation_state` (site: `draft` on editorial_eventinstance).
3. **`EventCreationService::updateInstanceStatus()`** (EventCreationService.php:758-807) —
   the ONLY series→instance state sync, with precise semantics:
   - Field selection: `moderation_state` ONLY when both entities are moderated AND their
     workflows are "the same" — determined by querying workflow config attachments and
     comparing `reset()` of each result (:764-783). Any mismatch → `return FALSE` (no sync).
     If either side unmoderated → falls back to plain `status` sync.
   - **Insert (no $event->original)**: unconditional copy of series state (:793-796).
   - **Update: DELTA-SYNC** (:798-805): instance follows the series ONLY IF
     `instance_state === series' ORIGINAL (pre-save) state`. An instance that diverged
     (e.g. individually archived while series was published) is left alone.
   - CONSEQUENCE: contrib's cancel-side cascade already preserved individually-diverged
     instances; the restore-side cascade CANNOT distinguish "archived by series cancel" from
     "individually archived" (both match original=archived) — the exact gap our keyvalue
     restore-memory fills.

### Site history of the sync (three eras, archaeology verified)
- 2022-10-17 `84476f000` "Initial Content Moderation configuration" — editorial workflow
  from birth attached to BOTH eventseries and eventinstance (+ access_news). Same day
  `e9c943be7` "Don't do moderation on eventinstances"; later re-added; history convoluted;
  repo mainline re-rooted ~2026-02 (git log without --all is blind before that).
- The dual attachment made updateInstanceStatus's reset()-comparison pass → moderation-era
  cascade worked (series publish/archive → instances follow, delta-sync semantics).
- Andrew's `bb0c43f9b` (2026-08-05) scopes eventinstance to editorial_eventinstance only —
  CORRECT config, but kills the cascade (workflow ids now differ → return FALSE). On the
  md-2797 branch, series publish/archive no longer touches instances. THE ORCHESTRATION
  DESIGN IS THE DELIBERATE REPLACEMENT (era 4).
- **EXPERIMENTALLY VERIFIED (ddev, 2026-08-08)**: with the dual attachment temporarily
  restored in active config, BOTH cascade paths work exactly as the code reads —
  create-published series → instance born `published` (insert-path unconditional copy);
  draft series published via state change → instance follows draft→published (update-path
  delta-sync). Attachment reverted after the probe. **PROD-LIVE CONFIG VERIFIED
  (terminus, read-only)**: the live environment still carries the dual attachment — the
  cascade is active on prod today, and the md-2797 branch removes it.

## 2. Series lifecycle chain (hook order as shipped)

**Insert** (`recurring_events_eventseries_insert`, recurring_events.module:161-177; skipped
during config sync): createInstances() → per instance: set eventseries_id, no new revision,
configureDefaultInheritances, **updateInstanceStatus (unconditional copy)**, save.
Translation-insert variant :182-204.

**Update** (`recurring_events_eventseries_update`, :246-307):
1. `checkForOriginalRecurConfigChanges($entity, $entity->original)` — serialize-compare of
   recur config ONLY (type/excluded/included/custom or rule field; EventCreationService:174-211;
   content fields never included).
2. SITE DEBUG PATCH (recurring_events-debug-duplicate-instances.patch): notice-logs every
   series update (:253-265) + warning when rebuild fires (:273) — retire separately.
3. Rebuild gate (:270-282): `date_changes && ($entity->isPublished() || !$moderated) &&
   isDefaultTranslation()` → resolve creator plugin from `recurring_events.eventseries.config
   creator_plugin` (site value: null → manager falls back to stock recreator) → fire
   `hook_recurring_events_event_instance_creator_plugin_alter` (OUR swap point) →
   `processInstances($entity)`.
4. Reset series cache, reload (:285-287).
5. **Sync loop over ALL instances on EVERY series save** (:289-306): updateInstanceStatus per
   instance (delta-sync; saves only when TRUE) + messenger "Successfully updated X / Skipped Y".
   Post-scoping this loop is a no-op except the message noise.

**Predelete** (`recurring_events_eventseries_predelete`, :389-408; default translation only):
invokeAll(`recurring_events_pre_delete_instances`) → delete every instance →
invokeAll(`recurring_events_post_delete_instances`).

## 3. The rebuild path (clearEventInstances/createInstances)

`clearEventInstances` (EventCreationService:405-454) — fires FIVE hooks:
`save_pre_instances_deletion` (series-wide; registration module hooks HERE),
`save_pre_instances_deletion_alter` (&$instances — mutable list!), per-instance
`save_pre_instance_deletion` / `save_post_instance_deletion`, `save_post_instances_deletion`.
Our PastPreservingEventInstanceCreator BYPASSES this entirely (direct deletes of future,
registrant-free-by-belt instances).

`createInstances` (:467-527): converts entity config → date set (custom branch or rule-type
`calculateInstances`) → `recurring_events_event_instances_pre_create` alter ONCE on the set
(contrib's own impl applies global+per-event include/exclude dates, recurring_events.module:589-693;
OUR impl filters past dates ONLY during our plugin's rebuild via series-id-scoped static) →
per-date `createEventInstance` + configureDefaultInheritances + save. NOT atomic (one-at-a-time).

`createEventInstance` (:542-590): data = eventseries_id/date/type/uid; fires
**`recurring_events_event_instance` DATA ALTER (:553) — injection point where
moderation_state could be set at creation** (candidate alternative to post-create publish).
No status/moderation set by contrib → defaults apply (draft under moderation).

## 4. Registration submodule

- `event_registration` base field on eventseries (registration_type instance|series, dates
  open|scheduled, capacity, waitlist, unique_email, permitted_roles)
  (recurring_events_registration.module:53-70). Computed counts on eventinstance:
  availability/registration/waitlist (:72-99).
- **RegistrantForm::save** (RegistrantForm.php ~463-560): gate = `administer any registrant`
  OR (registrationIsOpen && (availability>0 || -1 || waitlist)). **NO moderation/published
  check** (the browser draft-register hole; admin bypasses even the open gate).
  **setEventInstance() called UNCONDITIONALLY** → every form registrant carries
  eventinstance_id regardless of registration_type. setRegistrationType stamps the mode.
- `RegistrationCreationService`:
  - retrieveRegisteredParties (:192-230): 'series' type → query by eventseries_id;
    'instance' → by eventinstance_id.
  - **retrieveAllSeriesRegisteredParties($future_only) (:293-308): ALL-OR-NOTHING —
    if $future_only, gates once on eventSeriesHasFutureInstances() (ANY instance end>now,
    :316-330) then returns ALL series registrants incl. past ones.**
  - registrationIsOpen (:618-639): pure date-window logic; 'open' type closes at event start.
  - promoteFromWaitlist (:759-787): registrant UPDATE (not create) + promotion notification.
- Registrant lifecycle reactions (recurring_events_registration.module):
  - `save_pre_instances_deletion` (:228-255): REBUILD path — notify ALL series registrants
    (future-gated series-wide) under `series_modification_notification`, then DELETE each.
  - `hook_entity_update` (:260-298): SITEWIDE hook, if eventinstance AND serialized date
    changed AND new end>now → notify instance's parties under
    `instance_modification_notification` (site: enabled with reschedule wording).
  - `recurring_events_pre_delete_instance` (:303-332): instance delete FORMS path — notify
    (future-only) under `instance_deletion_notification` (site: contrib default, disabled)
    then DELETE registrants.
  - `recurring_events_pre_delete_instances` (:337-374): series delete path — notify future
    (`series_deletion_notification`, disabled) then DELETE ALL registrants.
  - `recurring_events_registration_send_notification` (:384-428): gates
    `email_notifications && notifications.<key>.enabled` (ANDed), queue-vs-immediate on
    `email_notifications_queue`; queue name
    `recurring_events_registration_email_notifications_queue_worker`.
- Contrib's ContactForm route access HARDCODES access_events\EventWaitlist::isAuthor
  (registration routing) — site patch; cross-module coupling to be aware of.

## 5. Site data facts (ddev copy, 2026-08-08)

- 928 series; **197 with registration enabled**; 1,777 registrants across 181 series.
- registration_type stored values: '' 316 / 'instance' 611 (mostly widget default noise) /
  **'series' exactly 1** (id 789 "National Research Platform Online Training").
- **0 registrants with NULL/0 eventinstance_id** — instance-keyed machinery is safe for all
  existing data; series-type mode differs only in capacity/dedup scoping.

## 6. Our layer (access_events) — already shipped on feat/event-crud

- Reschedule-block: EventSeriesRescheduleBlock constraint (validate-time, loadUnchanged) +
  `access_events_eventseries_presave` backstop (uses $entity->original, valid in presave) —
  refuse recur-config change when countFutureForSeries>0.
- PastPreservingEventInstanceCreator + creator-plugin alter + series-id-scoped pre_create
  filter: rebuilds never touch ended instances; publish-sync republishes created instances
  when series is published; belt aborts on unexpectedly-registered future instance.
- CancellationNotifier (send-only; keys event_cancelled_notification /
  event_reinstated_notification, module-owned) + notifyInstances(ids,key).
- Restore memory: keyvalue access_events.series_cancel (write-ahead; []≠NULL; consumed on
  restore; predelete cleanup) + revision-log breadcrumb.
- EventDeleteGuard + predelete/pre_delete_instance interceptions + form guards +
  module_implements_alter forcing our hooks FIRST (contrib ran first in this env —
  ordering is environmental, always pin with a test).
- API controller: archive/restore/cancel/edit/add occurrence semantics (thin-ness pending the
  orchestration refactor); register() published gate; draft-delete refusal.
- Cron guards: reminders + PostSurvey skip archived instances.

## 6b. Clone / revision-revert / tests (final read pass)

- **Clone forms** (EventSeriesCloneForm/EventInstanceCloneForm, 22 lines each): just
  `createDuplicate()` + the normal parent form save → a series clone is a NEW entity through
  the STANDARD insert path (instances spawn, state sync applies) — era-4 reactions cover
  clones automatically. CHECK under our authz: createDuplicate may carry moderation_state
  from a published source into the duplicate (potential review bypass via clone; gated by
  entity 'clone' access — see recurring_events_entity_operation, module:52-75).
- **Revision revert forms** (both ~170 lines): bare `$revision->save()` — NO validate().
  Series revert: covered by our presave backstop for recur changes; the update-hook rebuild
  fires normally if the reverted revision differs in dates. Instance revert: a date revert on
  a registered future instance fires contrib's date-change notify (correct behavior for free).
- **Contrib tests (all 12 swept)**: date-math pins for the five recur field types
  (calculateInstances), RegistrantController title regression, service instantiation, a
  LoadTest smoke, 2 migrate unit tests, RegistrationCreationServiceTest. **ZERO tests pin
  the publish cascade / updateInstanceStatus / rebuild lifecycle** — the de-facto spec is
  silent on the machinery being redesigned; our access_events kernel suite is the only spec.
  (Date-math test BODIES skimmed at method level only — pure date assertions, no state.)
- **RegistrantTest proves instance-less registrants are a supported entity shape**
  (series-reference-only, registration_type 'series') — even though this site's form always
  sets eventinstance_id (RegistrantForm save calls setEventInstance unconditionally) and the
  data has zero instance-less rows. The create-gate must handle the shape deliberately
  (e.g. series-state check or refuse instance-less creation) rather than assume an instance.

## 7. Open items the design must settle

1. Transition-reaction hook ordering vs contrib's update hook (rebuild + dead sync loop) —
   no precedent; decide with tests; use implements_alter + order-pinning per the delete guard.
2. Materialize-on-publish: must route through the plugin's scoped path (direct
   createInstances bypasses the past-date filter; non-atomic). Alternative injection point:
   the `recurring_events_event_instance` data alter (set state at creation).
3. Double-run prevention: controllers must stop orchestrating once hooks react; envelope
   counts (notified/instances_*) must flow back from the reaction layer.
4. Series-type registration: only capacity/dedup scoping differs; decide support-or-restrict.
5. The dead sync loop still runs every series save (messenger noise + debug patch logging);
   patch retirement queued separately.
6. NOT YET READ: clone forms (EventSeriesCloneForm/EventInstanceCloneForm — do clones fire
   insert hooks/create instances?), revision-revert forms (bare ->save(), no validate — the
   presave backstop covers recur changes; verify nothing else), 9 contrib tests (de-facto spec).
