# Phase 4 — Outbound Recurrence Differ (design)

Synthesized from an understand -> design -> adversarial-critique (29 findings) -> synthesize workflow.
Owner decisions (2026-05-31): (1) edited-occurrence-delete-without-EXDATE = LEAVE IN PLACE (park, safe);
(2) unsupported transitions = DETECT & REFUSE (one-way, logged); (3) inbound instance-edit detection =
PREFER CORRECTNESS (pull the instances list per tick for two-way recurring series to detect override drift).


# Phase 4 — Outbound Recurrence (FINAL, critique-hardened)

## Guiding principle change (resolves the 29 critiques in one stroke)

The proposed differ "derives the Google op set from the NC .ics" — keying instances by reconstructing originalStartTime strings from the NC RECURRENCE-ID/EXDATE. The adversarial review proves this is unsound at every seam: floating/Windows TZIDs (MED-1, MED-5), Z-vs-offset string mismatch in events.instances?originalStart (HIGH-2, HIGH-9), DST-fold collisions (MED-3), DTSTART/COUNT/UNTIL retime invalidating all tokens (HIGH-6, MED-14, MED-21), THISANDFUTURE corruption (HIGH-27), RRULE-only PATCH destroying Google EXDATEs (HIGH-18), and the master-etag echo gate being blind to override edits (HIGH-8, HIGH-9, HIGH-24).

**FINAL ARCHITECTURE: "expansion diff against live Google state," not "string reconstruction from NC."** The differ resolves every instance by `events.instances(masterId, showDeleted=true)` over a bounded window and matches NC overrides/EXDATEs to **returned Google instances** by the canonical {kind,key} UTC-instant tuple — Google's `originalStartTime` is the single source of truth for instance identity. The NC .ics is read only for *content* (what fields/state each instance should have), never to *address* a Google instance. This makes the offset/Z/floating/Windows-TZID string problems irrelevant: we never send a reconstructed `originalStart`; we match Google's own offset string canonically. `events.instances?originalStart=` is deleted from the design entirely (it caused HIGH-2, HIGH-9, MED-7, MED-25).

This is the same `getBaseComponent`/expansion-diff philosophy the inbound renderer already trusts.

## 0. Scope (v1) — what ACTUALLY ships

Three flows, all gated behind `isTwoWayEnabled(userId,calId)` AND `hasWriteScope(userId)`:

- **CREATE (LOCAL_NEW recurring)**: `events.insert` master (recurrence[] = RRULE only, see §3) + per-override + per-EXDATE sub-ops, modeled as "insert-or-adopt master, then run the differ" (resolves HIGH-13).
- **UPDATE (LOCAL_EDIT recurring)**: the expansion-diff differ (§2).
- **DELETE (LOCAL_DELETE recurring)**: `events.delete(masterId)` (§4).

All three are **detect-and-refuse-when-unsafe**: the differ has explicit guards that SKIP (status SKIPPED_UNSUPPORTED, terminal, advances token, logs loudly, leaves series one-way) rather than blindly mutate. See §6 "Refusal guards."

## 1. Components

**New service `OutboundRecurrenceService`** (lib/Service/OutboundRecurrenceService.php), injected into OutboundReconcileService alongside `writeService`. Public:
- `createLocalSeriesInGoogle(userId, calId, ncCalId, ncUri): string`
- `updateLocalSeriesInGoogle(userId, calId, ncCalId, ncUri): string`
- `deleteLocalSeriesInGoogle(userId, calId, ncCalId, ncUri): string`

Reuses static helpers from OutboundWriteService: `mapIcalDateToGoogle`, `deriveMissingEnd`, `resolveConflict`, `deriveClientId`, `isForeignDelete`. Returns the SAME status constants so reconciler `$advance` logic is unchanged; adds `SKIPPED_UNSUPPORTED` and `DEFERRED_INSTANCE` (both terminal: do NOT hold the token — see HIGH-1/HIGH-22/HIGH-23 token-wedge fix in §7).

**New pure class `RecurrenceKey`** (lib/Service/RecurrenceKey.php) — fully unit-testable, no I/O:
- `fromIcsDateProp(Sabre\VObject\Property $p, DateTimeZone $refZone, callable $isResolvableTzid): ?array{kind,key}` — returns null (NOT a fabricated instant) when the TZID is unresolvable (resolves MED-5).
- `fromGoogleInstance(array $instance): array{kind,key}` — canonicalizes a LIVE Google instance's `originalStartTime` (the authoritative side). This is the ONLY token source the differ matches against.
- `fromGoogleToken(string $token): array{kind,key}` — canonicalizes a stored sibling recurrence_id (used only to read existing rows; rows are advisory, Google instances are authoritative).
- NO `toGoogleOriginalStart` reconstruction method (deleted — caused HIGH-2/HIGH-9).

**New mapper method `EventMapMapper::findSiblingsForNcUri(ncCalId, ncUri): EventMap[]`** (recurrence_id <> '').

**New EventMapService methods**: `recordOutboundSibling(ncCalId, ncUri, token, googleId, googleUpdated, baselineEtag, state)` (upsert one sibling, state ∈ {'synced','cancelled'}) and `findSiblings(...)`. **`reapSibling` is REPLACED by `markSiblingCancelled`** — we KEEP the row with state='cancelled' so a later restore can target it (resolves MED-19 restore-loss). Rows are reaped only by inbound full-pull `deleteSiblingsNotIn`, which nc-origin series never reach — so the differ MUST treat the map as advisory and always re-resolve against live Google instances (resolves MED-12, LOW-29's unmapped-forever leak by never depending on the row being authoritative).

## 2. UPDATE differ — expansion-diff algorithm

```
updateLocalSeriesInGoogle(userId, calId, ncCalId, ncUri):
 0. Load NC .ics + etag + lastmodified (getCalendarObject). master VEVENT via
    getBaseComponent('VEVENT'); null -> SKIPPED_GONE.
 1. masterRow = getMasterRow(); masterId = masterRow.googleId.
    If masterRow missing/no googleId -> SKIPPED_GONE.
 2. REFUSAL GUARDS (§6) — compute and, if any trips, return SKIPPED_UNSUPPORTED
    (terminal, advance token, log). Guards: shape transition (single<->recurring),
    THISANDFUTURE/split signature, master DTSTART instant-or-zone changed vs stored
    baseline, RDATE present, all-day<->timed master flip, unresolvable master TZID.
 3. MASTER PATCH (start/end WITH timeZone + recurrence[]=RRULE only + summary/desc/
    location, ncOrigin tag) with If-Match masterRow.baselineEtag.
      412 -> re-GET master, resolveConflict(NC lastmodified vs live.updated, ties->NC);
             NC wins -> re-PATCH fresh etag; Google wins -> return CONFLICT-PARKED
             (record master baseline=live etag so it reads ECHO and STOPS retrying —
             do NOT hold the calendar token; resolves HIGH-1/HIGH-21 wedge).
      404/410 -> removeForNcUri + SKIPPED_GONE.
    Do NOT capture the master baseline here yet (step 7 re-GETs it).
 4. LIST LIVE INSTANCES (authoritative identity):
    GET events.instances/<masterId>?showDeleted=true&maxResults=2500, paginated,
    bounded to a window [min(NC override/EXDATE keys, master DTSTART) - 1d,
    max(...) + 1d]. Build liveByKey: {kind,key} -> {instanceId, status, etag, updated,
    originalStartTime} using RecurrenceKey::fromGoogleInstance.
    If an NC override/EXDATE key has NO live instance in window AND the window is at
    the lazily-unexpanded far edge -> DEFERRED_INSTANCE for THAT key only (park a
    pending sibling row, do NOT hold token; resolves MED-22). Re-tried next tick.
 5. Build NC intent sets from the .ics (CONTENT only, addressed by canonical key):
      ncOverrides[key]  = the override VEVENT (fields to write)
      ncExdates set     = canonical keys of every master EXDATE (getDateTimes, multi).
    Precedence rule (resolves HIGH-10, HIGH-15): EXDATE WINS over a co-located
    override. If a key is in ncExdates, it is a CANCEL regardless of any override
    VEVENT at that key; remove it from ncOverrides.
 6. Reconcile EVERY live instance + every NC sibling row against NC intent
    (diff against the SET, not just present VEVENTs — resolves HIGH-16, HIGH-17):
      For each canonical key K in (ncOverrides ∪ ncExdates ∪ liveByKey ∪ siblingKeys):
       a. K in ncExdates  -> ensure CANCEL: if liveByKey[K].status != 'cancelled',
          per-instance LWW then events.patch(instanceId, status='cancelled').
          404/410 = idempotent success (resolves MED-25). markSiblingCancelled(K).
       b. K in ncOverrides -> ensure OVERRIDE: per-instance LWW vs liveByKey[K]
          (live.updated vs NC lastmodified, ties->NC). NC wins ->
          events.update(instanceId, full override body incl status='confirmed') with
          If-Match liveByKey[K].etag (read-modify-write off the LIVE etag, NOT a stale
          sibling baseline — resolves HIGH-11 stale-baseline 412 storms). Google-
          instance wins -> SKIP this instance, mark it CONFLICT-PARKED (record sibling
          baseline=live etag), do NOT hold token (resolves MED-11). recordOutboundSibling(K).
          If liveByKey[K] is currently status='cancelled' (a restore) -> events.patch
          status='confirmed' + full body (showDeleted let us see it — resolves MED-19).
       c. K NOT in ncOverrides and NOT in ncExdates, but a live override instance
          exists (status='confirmed', originalStartTime != base expansion) OR a
          sibling row exists -> the user REMOVED the override with no EXDATE.
          v1 policy (resolves HIGH-16): this is AMBIGUOUS (delete-occurrence vs
          revert-to-default). SAFE DEFAULT = CANCEL is WRONG for revert and
          leave-in-place is WRONG for delete. We CANNOT distinguish from .ics alone,
          so v1: log CONFLICT-PARKED for that instance, leave the Google override in
          place, do NOT hold token. Surfaced in the unresolved-decisions list — the
          human owner picks the semantic. (This is a known, bounded, NON-corrupting
          gap; it never deletes or duplicates.)
 7. AUTHORITATIVE MASTER RE-GET (resolves HIGH-2 echo churn): after ALL instance
    ops, GET events/<masterId>. recordOutboundUpdate(master nc_etag = the NC object's
    CURRENT etag, google_updated + baseline_etag = the FRESH master GET response).
    This guarantees the inbound import's master incomingEtag == stored baseline ->
    pureEcho fires for the whole series next tick.
 8. Per-op durability (resolves HIGH-1, MED-15, MED-26): recordOutboundSibling /
    markSiblingCancelled is called IMMEDIATELY after EACH successful instance write,
    not batched at step 7. A mid-differ crash resumes incrementally; completed
    instances are not re-written. Idempotency: every instance op is an update/patch
    keyed on the LIVE instanceId from step 4, so replay converges.
```

Ordering: MASTER PATCH (step 3) FIRST, then LIST live instances (step 4) AFTER — so the list reflects any Google re-sequencing the master PATCH triggered (resolves HIGH-6/MED-21: we never trust pre-edit tokens; we re-list post-master-PATCH). Then per-instance ops. Then authoritative master re-GET.

## 3. EXDATE / recurrence[] strategy (refined)

recurrence[] sent outbound = **RRULE only**. Cancellations = per-instance `events.patch status=cancelled` on the LIVE instanceId from the instances list.

**Critical fix for HIGH-18 (RRULE-only PATCH destroys Google declarative EXDATEs):** Because recurrence[] is overwritten by PATCH, and the inbound renderer turns Google master-EXDATEs into NC EXDATE lines, the differ MUST cancel **every** master EXDATE per-instance on every master edit (step 6a iterates the full ncExdates set, not a "new since baseline" delta — there is no reliable EXDATE baseline). Cancelling an already-cancelled instance is idempotent (404/410/200 all = success). This makes the RRULE-only PATCH safe: any occurrence the master EXDATE used to suppress is re-suppressed per-instance in the same differ run. RDATE: REFUSED (§6) — not "best-effort."

## 4. CREATE & DELETE

**CREATE** = "ensure master, then run the differ" (resolves HIGH-13):
1. `events.insert` master: buildEventFields + recurrence[]=['RRULE:...'], start/end WITH timeZone, id=deriveClientId(uid), ncOrigin=ncUri. 409 -> adoptDuplicate by ncOrigin match (reuse OutboundWriteService::adoptDuplicate semantics → records master row origin='nc').
2. **Do NOT return terminal after adopt.** Fall straight into updateLocalSeriesInGoogle's steps 4-8 (list instances + reconcile overrides/EXDATEs) against the now-known master id. CREATED is returned only after master AND all sub-ops reach a terminal state; a crash-replay re-enters and finishes idempotently.

**DELETE** whole series = `events.delete(masterId)` If-Match master baseline; 404/410 idempotent success; 412 -> NC-delete-wins (re-GET + re-delete, `isForeignDelete` guard) exactly like resolveDeleteConflict. Google cascades override instances. Then `removeForNcUri` drops master + all siblings. (Single-instance delete is NOT a LOCAL_DELETE — it is a new EXDATE → LOCAL_EDIT, handled by step 6a.)

## 5. Reconciler hook & echo (inbound fix is MANDATORY, not optional)

**Router** in OutboundReconcileService::reconcile per-uri loop: peek the master VEVENT (`getBaseComponent('VEVENT')`) for RRULE/RDATE/RECURRENCE-ID. Recurring -> OutboundRecurrenceService; else existing flat OutboundWriteService. Shared status constants keep `$advance` intact. SKIPPED_UNSUPPORTED / DEFERRED_INSTANCE / CONFLICT-PARKED are TERMINAL (advance the token) — only genuine transient ERROR holds it (resolves the single-token calendar-wide wedge in HIGH-1/HIGH-22/HIGH-23).

**Inbound echo gate MUST be made sibling-aware (resolves HIGH-8, HIGH-9, HIGH-24 — without this, Phase 4 silently drops every Google-side instance edit):** In GoogleCalendarAPIService's ncOrigin branch (lines 731-790), when the master is `pureEcho`, do NOT bind+`continue` unconditionally. First compare each exception with `recurringEventId == masterId` against its sibling row's baseline_etag (via the new findSiblings). **If any sibling's live override etag differs from its stored baseline, fall through to applyRemoteToNcOrigin** (re-render the whole series including overrides) instead of bind+continue. This is the ONLY way a Google-side single-instance edit reaches NC for an nc-origin series. Also: whenever the differ writes ANY override outbound, force the next inbound pull FULL (mirror the existing forceFullNext-on-cancellation mechanic) so the master re-renders overrides inline in a single tick — keeping the master-nc_etag echo funnel valid (resolves HIGH-24's "different ticks" race).

## 6. Refusal guards (deferral-is-only-safe-if-detected — resolves HIGH-27, HIGH-6, MED-14, MED-20, MED-5, the RDATE MED)

Before any mutation, updateLocalSeriesInGoogle returns SKIPPED_UNSUPPORTED (terminal, one-way, loud log) if:
1. **Shape transition**: NC object is now non-recurring but the map row was recurring, or vice versa (compare `isRecurring(master)` to a new `shape` column on the master row, default backfilled). 
2. **Split signature (THISANDFUTURE)**: master RRULE GAINED an UNTIL/COUNT not in the stored baseline RRULE, OR a sibling LOCAL_NEW recurring object shares this series' UID/RELATED-TO. (Store the baseline RRULE string on the master row.)
3. **Master DTSTART moved**: the master DTSTART canonical instant or its zone changed vs the stored master baseline (new `master_dtstart` advisory column). This re-anchors the whole expansion and invalidates instance identity — refuse rather than orphan/duplicate (HIGH-6, MED-14).
4. **RDATE present** on the master (no symmetric inbound support; would silently drop occurrences — the RDATE MED finding).
5. **All-day<->timed master flip** vs baseline (MED-20, LOW-23).
6. **Unresolvable master TZID** (not in DateTimeZone::listIdentifiers and not Z/UTC) — RecurrenceKey returns null; refuse (MED-5).

CREATE has a narrower guard set (no baseline to diff): refuse only RDATE-present and unresolvable-TZID; everything else is a clean insert.

## 7. Per-row baseline / 412 / LWW / token discipline

- Master op: If-Match master baseline; 412 LWW ties->NC.
- Instance op: If-Match the **LIVE instance etag from step 4's list** (read-modify-write), never a stale sibling baseline (resolves HIGH-11).
- Per-instance Google-wins: SKIP + park (record baseline=live etag so it reads ECHO), do NOT hold token (resolves MED-11, HIGH-1).
- Coarse NC clock: only whole-object lastmodified exists, so all per-instance LWW compares against it. Accepted for v1; an instance edited on Google more recently than the whole NC object's lastmodified correctly wins (Google-instance-wins → skip). Documented limitation (the per-instance LWW open question).
- **Token wedge fix (the dominant systemic risk, HIGH-1/HIGH-21/HIGH-22/HIGH-23):** the calendar-wide token is held ONLY on transient ERROR. SKIPPED_*, CONFLICT-PARKED, DEFERRED_INSTANCE are terminal and advance. A single wedged instance can NEVER freeze the whole calendar's outbound sync. The master nc_etag is refreshed on partial success (step 7 runs whenever the master op itself succeeded), so the series stops re-running master+all-instances every tick.

## 8. Quota / large-series bounding (resolves MED-16, MED-26)

The differ records each sibling immediately (step 8) so work is durable per-op. Add a per-tick instance-op budget (e.g. 50 writes); on hitting it, record progress, return a non-terminal-but-advancing PARTIAL that re-enters next tick to finish remaining instances (the LIVE-instance list makes resume idempotent). The master PATCH is skipped on re-entry if its baseline already matches the .ics-derived body (a body fingerprint check) to avoid re-touching the master etag every tick.


## Locked instance key



## Implementation order

1. RecurrenceKey pure class + exhaustive unit tests FIRST (no I/O, lab-verifiable via phpunit only). Cover: timed TZID NY weekly across DST (10:00 -> 2026-03-02=15:00Z EST, 2026-03-09=14:00Z EDT); the locked equivalence '.ics TZID=America/New_York:20260608T100000' and Google instance originalStartTime '2026-06-08T10:00:00-04:00' BOTH -> {timed,2026-06-08T14:00:00Z}; all-day -> {allday,Y-m-d}; Z/UTC value -> UTC regardless; floating + unresolvable/Windows TZID -> returns null (no fabricated instant); never-cross-kinds. This is the foundation every other step depends on and is the cheapest thing to get exactly right.
2. EventMapMapper::findSiblingsForNcUri + EventMapService::{findSiblings, recordOutboundSibling, markSiblingCancelled}. Add a `state` value 'cancelled'. Unit-test the upsert paths. Add migration Version04000003 for new advisory master-row columns: shape ('recurring'/'single'), baseline_rrule, master_dtstart (for refusal guards §6) + backfill defaults. Resolve the Oracle NULL-google_id sentinel question NOW: only ever insert a sibling AFTER the instance write returns an id (never a null-google_id sibling); confirm no intermediate insert path violates this (LOW-29).
3. OutboundRecurrenceService skeleton + status constants (SKIPPED_UNSUPPORTED, DEFERRED_INSTANCE, CONFLICT-PARKED) + the §6 REFUSAL GUARDS, with unit tests that each unsafe shape returns SKIPPED_UNSUPPORTED and performs ZERO Google calls. Ship this BEFORE any mutation logic so the deferred-but-dangerous cases (THISANDFUTURE, DTSTART move, RDATE, shape transition) are provably refused first. Wire the recurring/flat router in OutboundReconcileService with terminal-vs-hold $advance for the new statuses.
4. DELETE whole-series (events.delete + 412 NC-delete-wins + isForeignDelete + removeForNcUri). Simplest mutation, mirrors existing resolveDeleteConflict. Lab-verify: create a Google recurring series with two overrides, import, enable two-way, delete the NC object, confirm master+overrides gone on Google and all map rows removed.
5. The instance LIST + canonical-match resolver (events.instances?showDeleted=true, paginated, windowed) as a standalone method with integration coverage in the lab. Verify it matches NC override/EXDATE keys to live Google instances by originalStartTime (NOT start), including the moved-then-deleted case (HIGH-7) and DST window. Verify far-future-unexpanded -> DEFERRED_INSTANCE (no token hold).
6. UPDATE differ steps 3-8 on top of the resolver: master PATCH (RRULE-only), per-EXDATE cancel of the FULL set (HIGH-18), per-override update with LIVE-etag If-Match + per-instance LWW, EXDATE-wins precedence (HIGH-10/15), authoritative master re-GET (HIGH-2), per-op durable recording (HIGH-1). Lab-verify each op-mapping row end to end on the real lab calendar.
7. CREATE as insert-or-adopt-master-then-run-differ (HIGH-13). Lab-verify crash-replay: insert master, kill before sub-ops, re-run, confirm 409-adopt THEN overrides/EXDATEs are applied (not short-circuited terminal).
8. INBOUND echo-gate sibling-awareness (HIGH-8/9/24) in GoogleCalendarAPIService: on pureEcho master, compare each exception etag to its sibling baseline; fall through to applyRemoteToNcOrigin if any differs. Force-full-next-pull whenever the differ writes an override. Lab-verify: edit ONE Google instance of an nc-origin series, confirm the change lands back in NC (currently it is dropped forever).
9. Per-tick instance-op budget + PARTIAL resume + master body-fingerprint skip (MED-16/26). Lab-verify a synthetic ~60-override series completes across two ticks without re-touching the master etag or wedging the token.
10. Full adversarial re-review (re-run the 29 scenarios as targeted tests where feasible), then PR. Each HIGH finding gets a named regression test (moved-then-deleted-then-reaped; override-then-deleted-no-EXDATE; RRULE-only-PATCH-preserves-cancellations; Google-instance-edit-echo; DTSTART-move-refused; THISANDFUTURE-refused).

## Deferred (v1) — detected & refused, stays one-way

- THISANDFUTURE / 'this-and-following' edits: DETECTED and REFUSED (SKIPPED_UNSUPPORTED), not silently passed. Series stays one-way. No master truncation, no duplicate series.
- RRULE add/drop transition (single<->recurring): DETECTED via shape column and REFUSED. No PATCHing recurrence[] onto a flat event, no dropping a series to a single.
- Master DTSTART time/zone move on an existing series: DETECTED (master_dtstart baseline) and REFUSED — re-anchors the whole expansion and would orphan all siblings.
- RDATE-added one-off occurrences: DETECTED and the series is REFUSED outbound (Google has no reliable add-occurrence-outside-RRULE API and there is zero inbound RDATE support). Never silently dropped.
- All-day <-> timed master conversion: DETECTED and REFUSED (every per-instance key would break).
- Override-revert vs delete-occurrence-without-EXDATE: PARKED (Google override left in place, logged) — non-corrupting; awaits the owner's semantic decision on whether 'revert to series default' is expressible.
- Attendee / reminder / VALARM sync on recurring series and per-instance attendee diffs: out of scope (consistent with the flat path omitting attendees + sendUpdates=none).
- Per-instance NC modified timestamps: v1 uses only the whole-object lastmodified for all per-instance LWW (coarse but non-clobbering).

## Top risks

- SYSTEMIC TOKEN WEDGE (was HIGH-1/21/22/23): one calendar-wide change token + one $advance flag means any non-terminal recurring-series failure freezes outbound sync for the ENTIRE calendar. MITIGATED by making SKIPPED_UNSUPPORTED/CONFLICT-PARKED/DEFERRED_INSTANCE terminal (advance) and holding only on transient ERROR, plus per-op durable recording so a partial run is never re-run wholesale. This is the highest-leverage fix; if it regresses, a single bad instance stalls everything.
- INBOUND BLINDNESS TO OVERRIDE EDITS (was HIGH-8/9/24): the inbound echo gate is master-etag-keyed and never iterates exception resources, so a Google-side single-instance edit of an nc-origin series is silently dropped forever. The bidirectional contract is BROKEN for instance-level edits until the gate is made sibling-aware AND we empirically confirm Google bumps the master 'updated' on an exception-only edit (it does not always). If Google does NOT bump the master, we must additionally pull instances to detect override drift. MUST be validated in the lab before claiming two-way recurrence works.
- MASTER-EDIT DESTROYS GOOGLE CANCELLATIONS (was HIGH-18): RRULE-only PATCH overwrites recurrence[], resurrecting previously-EXDATE'd plain occurrences. MITIGATED by cancelling the FULL master-EXDATE set per-instance on every master edit (idempotent). Risk: if any EXDATE'd slot's instance can't be resolved (far-future unexpanded), the occurrence transiently reappears until DEFERRED_INSTANCE retries.
- DESTRUCTIVE RECURRENCE TRANSITIONS (was HIGH-6/27, MED-14/20): DTSTART move, THISANDFUTURE split, single<->recurring, all-day<->timed flip, RDATE-add all re-anchor or split the series and would orphan/duplicate/data-loss if the differ blindly applied them. MITIGATED by §6 refusal guards (detect -> SKIPPED_UNSUPPORTED, stay one-way). Risk: a transition shape the guards don't anticipate slips through and corrupts; the guards must be conservative (refuse on any baseline mismatch) and the baseline columns must be reliably populated.
- OVERRIDE-DELETED-WITHOUT-EXDATE (was HIGH-16): deleting an already-edited occurrence commonly removes the override VEVENT with NO compensating EXDATE — indistinguishable from revert-to-default from the .ics alone. v1 PARKS it (leaves the Google override, logs), which means a real single-occurrence delete of an edited instance may NOT propagate. Non-corrupting but a genuine user-visible gap; needs the owner's semantic decision.
- DST-fold / sub-daily ambiguity (was MED-3): two occurrences can canonicalize to one UTC instant, or a gap-hour wall time shifts. MITIGATED by requiring exactly-one live instance at {kind,key}; zero-or-many -> park. Sub-daily EXDATE cancellation is explicitly unsupported when uniqueness fails.

## Review-driven changes (round 1) and v1 limitations

The Phase-4 adversarial review (8 confirmed findings) drove these changes:
- Guard baselines (shape/RRULE/DTSTART) are now SEEDED at import for recurring
  series (`seedImportedSeriesBaseline`), so the FIRST NC edit of an imported
  series is diffed against its pre-edit shape (else a DTSTART move / shape flip /
  this-and-following split on an imported series bypassed the refusal guards).
- Incomplete instance diffs RESUME: the master Google baseline is refreshed
  immediately, but `nc_etag` (the ECHO marker) is set ONLY on a complete diff;
  a transient ERROR holds the change token, so the series re-classifies as
  LOCAL_EDIT next tick and the differ resumes idempotently (CREATE resets nc_etag
  so it too resumes). DEFERRED_INSTANCE ADVANCES the token (anti-wedge): a budget
  or far-future remainder converges now and re-syncs on a later edit / full pull.
- EXDATE removal RESTORES a previously-cancelled occurrence (cancelled sibling
  rows are iterated and patched back to status=confirmed).
- Per-instance writes are NC-WINS (no per-instance LWW). A master PATCH propagates
  master fields (e.g. summary) onto its instances, RESETTING overrides; a
  per-instance LWW would mistake our own reset for a concurrent Google edit and
  drop the override (verified). Outbound therefore re-asserts NC's overrides;
  Google-side per-instance edits made while NC is QUIESCENT are captured inbound
  by the sibling-aware echo gate. Net per-instance conflict policy: the side that
  most recently edited the SERIES wins (a coarse LWW), consistent with ties->NC.

Accepted v1 limitations (non-corrupting, documented):
- A Google-side per-instance edit CONCURRENT with an NC series edit resolves
  NC-wins for that tick (the master-PATCH propagation resets it regardless);
  it re-syncs inbound once NC is quiescent.
- A multi-instance outbound edit can trigger ONE spurious inbound re-render
  (stale sibling baselines from intra-series etag bumps); it self-corrects via
  the inbound sibling-baseline refresh and converges.
