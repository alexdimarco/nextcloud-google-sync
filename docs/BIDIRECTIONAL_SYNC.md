# Bidirectional sync — architecture & decisions

Status: **design approved 2026-05-29; implementation in progress (Phase 0 landed first).**

This app started one-way (Google → Nextcloud). This document records the
architecture chosen for adding **two-way** sync (NC → Google write-back) and
the decisions made by the project owner. It was produced from a multi-agent
design study (four rival architectures, adversarial critique, judge panel,
synthesis).

## Chosen architecture: "reconciler + ncOrigin identity + single-token-owner"

A persistent **mapping table** (`oc_calbridge_event_map`) records the
relationship between each NC calendar object (at a recurrence slot) and its
Google event. A single reconciliation job diffs both sides against the stored
baseline each tick and applies changes. Three load-bearing properties:

1. **Echo suppression via `extendedProperties.private.ncOrigin = <nc_uri>`,
   not hashes.** Verified fact: NC rewrites the ICS we hand it
   (`GoogleCalendarAPIService::generateEventData`) and Google rewrites the
   event body on `events.insert`, so a stored etag or content-hash can never
   byte-match what the next read reports. Two of the four candidate designs
   built their echo gate on hash equality and both drew fatal ping-pong
   critiques. A Google-side origin tag survives both rewrites and is the
   primary "this is my own write echoing back" signal. `etag`/`updated` are
   kept only as a secondary last-writer-wins timestamp.

2. **`nc_uri → google_id` indirection threaded through the inbound importer.**
   Today the importer writes each event at `objectUri = $e['id']` (the Google
   id). For NC-originated events that echo back from Google, the applicator
   must resolve to the *original* NC object and update it, never create a
   second object under the Google-id URI.

3. **One job owns the inbound `syncToken` lifecycle, the per-calId lock, and
   the 410/cancelled-full-pull logic.** The reconciler runs inbound-then-
   outbound sequentially under a single lock acquisition; the legacy
   `ImportCalendarJob` is retired, never run in parallel, so the token model
   is never raced.

## Owner decisions

| # | Decision | Choice |
|---|----------|--------|
| Conflict policy | Same event edited both sides since last sync | **Last-writer-wins, ties → NC** (mirrors the existing inbound rule). Same-second edits are a known unresolvable race (~1s granularity); logged as a warning. |
| Recurrence in v1 | Write-back of recurring series | **Included** (overrides the study's "defer" recommendation). Forces an ICS-differ and the 1:N map below; highest-risk piece. |
| Opt-in model | Which calendars sync two-way | **Per-calendar toggle, default OFF**, enable-able only with owner/writer access and the writable `calendar.events` scope. |
| Inbound delivery | Polling vs push | **Polling only** (`events.watch` needs a public HTTPS callback, infeasible for self-hosted NC). |
| Echo signal | (engineering) | `ncOrigin` extendedProperty, primary. |
| Identity | (engineering) | `nc_uri → google_id` indirection. |
| Lock | (engineering) | Harden the TOCTOU lockfile to `flock()` before any outbound write. |

## The 1:N recurrence map

The inbound importer stores a whole recurring series (master + inlined
exceptions, with cancelled instances as `EXDATE`) as **one** NC calendar
object whose URI is the master's Google id. But each exception is a **separate
Google event** with its own id. So one NC object maps to a master event plus N
exception events. The map represents this as sibling rows sharing `nc_uri`:

- master/standalone row: `recurrence_id = ''`, `google_id = <master id>`
- per-exception row: `recurrence_id = <originalStartTime token>`, `google_id = <exception id>`

`recurrence_id` uses the exception's `originalStartTime` (dateTime, else date)
— the same value space the importer emits as `RECURRENCE-ID`.

Note: the inbound importer does **not** preserve per-exception Google ids in
the NC blob, so a backfill from existing NC data alone can only seed master
rows; instance rows are filled by the inbound recorder on subsequent syncs
(which still have the real exception ids in scope) and by the Phase 2
trust-but-verify full reconcile.

## Phased plan

- **Phase 0 — event map, read-only (this PR).** `oc_calbridge_event_map`
  table + Entity/Mapper + `EventMapService`. The inbound importer mirrors its
  writes (master + live instances) into the map and lazily backfills master
  rows for existing calendars. Stale recurrence-sibling rows are pruned, but
  only on a **full pull** (on an incremental pull the exception list is just a
  delta; the importer forces a full pull the tick after any cancellation, so
  a phantom sibling row lives at most one tick). **Zero behavior change**: no
  outbound writes; every map call is defensive and only logs on failure.
  - *Phase-2 carry-forward:* the `(nc_cal_id, google_id)` unique index relies
    on multiple NULL `google_id` rows not colliding, which holds on
    MySQL/MariaDB/PostgreSQL/SQLite but **not Oracle**. Dormant in Phase 0
    (all rows have a non-null `google_id`); Phase 2 must resolve it before
    storing NC-origin rows with a NULL `google_id` (e.g. a non-null local
    sentinel or app-layer uniqueness), folded into its own migration.
- **Phase 1 — enablers.** Add a `$headers` parameter to
  `GoogleAPIService::request()` (currently it hardcodes headers and *cannot*
  emit `If-Match`); harden the per-calId lockfile to `flock()`.
- **Phase 2 — non-recurring outbound, opt-in.** Built in sub-slices:
  - *2a (shipped first, read-only):* the `nc_etag` baseline + a dry-run
    reconciler that detects local NC changes and **logs** what it would push,
    writing nothing. Validates the dual echo gates against real data.
  - *2b:* outbound CREATE (`events.insert` with `ncOrigin` + deterministic id)
    + the inbound `nc_uri → google_id` indirection.
  - *2c:* outbound UPDATE/DELETE (`events.patch`/`delete` with `If-Match`),
    LWW (ties → NC), 412 → re-pull never clobber, failures re-queue never
    delete. Single inbound-then-outbound job under one lock.
  - Per-calendar toggle default OFF.

  *2b deferred items (from the adversarial review — all fail-safe / log-only in
  2b, fix in the noted later phase):*
  - **DURATION instead of DTEND** (a valid but uncommon ICS shape; NC's own UI
    emits DTEND) is pushed as end==start, collapsing duration. Fix in a write
    hardening pass with Sabre `DateTimeParser::parseDuration`, coordinated with
    the all-day +1-day rule.
  - **Crash-recovery fresh row:** if a crash lands between the Google insert and
    `recordLocalNew`, and the echo arrives first, `bindGoogleIdForNcUri` mints a
    fresh `origin='nc'` row with `nc_etag=NULL` → permanent `INDETERMINATE`.
    Harmless in 2b (INDETERMINATE never writes); must be closed before outbound
    EDITS (2c) — set the baseline when minting a fresh row.
  - **Non-IANA TZID** (Windows/Outlook zone names) is passed verbatim and Google
    may reject it; **floating DATE-TIME** fidelity depends on the process default
    timezone. Address in the transport/mapping hardening pass.

### The dual echo gates

Two-way sync has an echo hazard in *both* directions and they need *different*
gates (verified against the NC source):

- **Google → NC** (our outbound write reappears in the next inbound
  `events.list`): the `extendedProperties.private.ncOrigin = <nc_uri>` tag on
  every event we write. Survives Google rewriting the body on insert.
- **NC → Google** (our inbound write appears in `getChangesForCalendar` as a
  local change): Nextcloud's own `createCalendarObject`/`updateCalendarObject`/
  `deleteCalendarObject` bump `oc_calendars.synctoken` and write an
  `oc_calendarchanges` row, with **no provenance field**. So an inbound import
  of 700 events looks identical to 700 user edits. The discriminator is the
  event map's **`nc_etag`** baseline — the etag at the moment *we* last wrote
  the object:

  | change | map row | etag vs baseline | classification |
  |--------|---------|------------------|----------------|
  | added/modified | none | — | LOCAL_NEW (user created) |
  | added/modified | present | equal | ECHO (our inbound write) |
  | added/modified | present | differs | LOCAL_EDIT (user edited) |
  | added/modified | present | baseline null | INDETERMINATE (no baseline yet) |
  | deleted | present | — | LOCAL_DELETE (user deleted) |
  | deleted | absent | — | ECHO_DELETE (our inbound delete removed the row) |

  The reconciler baselines at the current sync token on first run (so it never
  replays the initial import), then processes only deltas. If the stored token
  expires (NC purges `oc_calendarchanges`) or the calendar is re-imported under
  a fresh lower sequence, it re-baselines at the current head rather than
  re-polling a dead token.

#### Phase-2b obligations (from the 2a adversarial review — must hold before any push)

The dry-run classifier is deliberately *fail-safe*: every imperfection degrades
toward the conservative `LOCAL_EDIT_INDETERMINATE` / `ECHO_DELETE` rather than
toward "echo misclassified as a confirmed user edit". Before 2b wires a
classification to an actual `events.insert`/`patch`/`delete`, these must be
closed or it could push wrongly:

- **Never push an unbaselined row.** Map rows seeded in Phase 0 (and any not
  re-written since) have `nc_etag = NULL`, so a local edit classifies as
  `LOCAL_EDIT_INDETERMINATE`. 2b must backfill the `nc_etag` baseline (a
  trust-but-verify pass that reads current etags, or re-fetch from Google)
  before treating such a row as a confirmed edit — never push an
  `INDETERMINATE`.
- **Harden the delete echo discriminator.** The delete branch trusts map-row
  *presence* alone. `removeForNcUri` swallows a failed row deletion, so a row
  could outlive its object and mislabel our own echo-delete as `LOCAL_DELETE`.
  2b should mark `state` on a failed removal and treat such rows as
  `ECHO_DELETE`.
- **`modified` + unreadable current etag → INDETERMINATE, not `LOCAL_EDIT`**,
  before `LOCAL_EDIT` is wired to a push.
- **`nc_etag` lives on the master row only** (object-level), which is correct
  because change detection is object/URI-level; sibling rows intentionally
  leave it null.
- **Phase 3 — eligibility UX.** Per-calendar two-way toggle in personal
  settings, gated on `accessRole ∈ {owner, writer}` and the non-readonly
  scope; surfaces per-row `last_error`.
- **Phase 4 — recurrence outbound.** ICS-differ splitting a multi-VEVENT NC
  object into master `events.update` + per-`RECURRENCE-ID` instance patches +
  `EXDATE → status:cancelled`. In scope because recurrence is in v1.

## Top residual risks

1. **Map drift is sticky and self-amplifying.** Unlike the current stateless
   importer (which self-heals every full pull), the reconciler trusts its own
   history; a crash mid-tick or an out-of-band edit can turn a real foreign
   edit into a skipped echo (silent data loss) or an echo into a perceived
   foreign change (ping-pong). **MITIGATION IMPLEMENTED — `MapVerifyService`**: a
   periodic (≤ once/6h per two-way calendar, under the import flock)
   trust-but-verify pass that re-derives the map from BOTH live sides
   (`events.list` + `getCalendarObjects`). It is READ + MAP-ONLY (never writes
   Google/CalDAV), repairs only the two provably loss-proof drifts (drop a
   both-sides-gone row; rebind a dangling `google_id` to the live event still
   carrying our `ncOrigin` tag), and LOGS everything else (stale baseline,
   indeterminate `nc_etag`, foreign tag, ambiguity) to `nextcloud.log` + the row's
   `last_error`. A truncated (>50-page) or failed Google list is treated as
   incomplete and skips the pass (no acting on partial ground truth). It does NOT
   recover token-gap losses (unrecoverable by design) but makes them visible. The
   pure decision table (`classifyRowDrift`/`shouldVerify`) is unit-tested; the
   non-destructive end-to-end behavior is lab-verified (`tests/manual/verify-pass.php`).
2. **`ncOrigin` echo gate requires the inbound importer to read
   `extendedProperties`,** which it does not today. Phase 2 must add that
   fetch/read path; until then there is no durable echo gate.
3. **Same-second timestamp collisions** are unresolvable at ~1s granularity;
   surfaced as a logged warning, not silently "resolved".
4. **Recurrence stays harder/last** even though it is in v1; the differ is the
   lowest-confidence component.
5. **OAuth scope.** Write-back needs the writable `calendar.events` scope; the
   app currently requests only the `.readonly` variants, so enabling two-way
   forces re-consent for existing users.
