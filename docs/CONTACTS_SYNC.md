<!--
  - SPDX-FileCopyrightText: 2026 Alex DiMarco
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contacts Sync — Design

Status: IMPLEMENTED — C0 (continuous inbound), C1 (de-duplication), C2a (outbound create), and C2b (outbound update/delete/conflict) are all shipped. Two-way sync for "My Contacts" is complete. See §7 for the per-phase status.

This doc adapts the proven, feature-complete **calendar bidirectional sync** architecture ("reconciler + origin identity + single-token-owner") to **contacts**. It targets three owner asks: (1) continuous incremental Google→NC sync, (2) de-duplication, (3) NC→Google outbound. Where a calendar pattern maps cleanly, we reuse it verbatim; the one place it does not (no `extendedProperties` on People) is called out explicitly in §3.

---

## 1. Goal & scope

Today contacts are a **one-time, one-way, manual** import (`GoogleContactsAPIService::importContacts()` on a button click). It never deletes, never runs on a schedule, has no identity map, and has no echo/conflict model. The three asks:

1. **Continued (incremental) Google→NC sync** — a background job that pulls People-API deltas via `syncToken` (not a full re-fetch), applies creates/updates **and Google-side deletions** to NC cards.
2. **De-duplication** — collapse dupes created by prior one-time imports, and structurally prevent new dupes via a persistent identity map.
3. **NC→Google outbound** — push NC card create/update/delete to Google, echo-suppressed and conflict-resolved.

**Reuse from calendar (template):** persistent identity-map table; `getChanges*`-based incremental deltas; a single background job that **owns the sync token**; last-writer-wins with ties→Nextcloud; cap-and-drain budgets; trust-but-verify map reconcile; widen-and-gate consent rollout. **In scope v1:** "My Contacts" two-way; optional "other contacts" inbound-only. **Out of scope v1:** groups/CATEGORIES round-trip, photo round-trip, relations/residences fields.

---

## 2. Architecture — the contacts analog of the calendar reconciler

One-to-one mapping of every calendar concept to its contacts equivalent:

| Calendar (existing) | Contacts (this design) |
|---|---|
| `ImportCalendarJob` (background, owns sync token) | **`SyncContactsJob`** (background, owns People `syncToken`) |
| CalDAV `getChangesForCalendar(calId, token, level, limit)` | CardDAV **`getChangesForAddressBook(addressBookId, token, level, limit)`** |
| `calbridge_event_map` (1:N rows) | **`calbridge_contacts_map`** (1:1 row per contact) |
| Google Calendar `events.list` `syncToken` (G→NC delta) | People **`connections.list` / `otherContacts.list` `syncToken`** (G→NC delta) |
| `CalendarMap` (per linked calendar pair + two-way flag + NC change token) | **per-address-book config** (`sync_contacts` toggle + per-AB NC change token + People syncToken), keyed by user + `addressBookId` |
| `OutboundReconcileService` (classify added/modified/deleted, write outbound, advance token) | **`ContactsReconcileService`** (same state machine) |
| Origin tag `ncOrigin` in `extendedProperties` (PRIMARY echo signal) | **NONE — see §3.** Echo detection promoted to the etag/updateTime+map mechanism. |

### `calbridge_contacts_map` schema

One row per reconciled contact (no recurrence fan-out, so 1:1 not 1:N):

| Column | Meaning |
|---|---|
| `nc_addressbook_id` (int) | NC address book holding the card |
| `nc_card_uri` (string) | NC card URI (PK component with addressbook id) |
| `google_resource_name` (string) | `people/{id}`, Google's stable identity |
| `origin` (enum `nc`\|`google`) | who created the contact first |
| `baseline_etag` (string) | **last Google etag we know** (from last read or our last write's response) |
| `nc_etag` (string/int) | **NC card echo baseline** = `lastmodified` we last wrote/observed |
| `google_updated` (string) | Google `metadata.sources.updateTime` at last sync (LWW input) |
| `state` (enum `synced`\|`pending`\|`error`) | reconciliation lifecycle |
| `last_error` (string, nullable) | last failure detail |

Indexes: unique on (`nc_addressbook_id`,`nc_card_uri`) and on `google_resource_name`. This is the direct analog of `EventMap`, minus recurrence columns, plus an explicit second etag (`nc_etag`) because that mechanism is promoted to primary (§3).

### Token ownership

The People-API `syncToken` is **owned solely by `SyncContactsJob`** (single-token-owner), persisted per user + address book in `IConfig` exactly like the calendar `nc_change_token_<hash>` keys. Two tokens per linked address book:
- **Google→NC:** People `nextSyncToken` (from `connections.list?requestSyncToken=true`). Expires after **7 days** → on `EXPIRED_SYNC_TOKEN` error, drop token and full-resync (reconcile against the map, do **not** re-create dupes).
- **NC→Google:** CardDAV change token from `getChangesForAddressBook` (advanced only after all outbound writes in the batch succeed).

---

## 3. The key DIFFERENCE from calendar — echo suppression without an origin tag

**Calendar relies on a PRIMARY echo signal that contacts do not have.** Calendar stamps `extendedProperties` (`ncOrigin`) on every event it writes to Google; on inbound, an event carrying our tag is trivially an echo. **Google People API has NO `extendedProperties`** and no system-writable custom metadata (`userDefined` is end-user data we must not hijack). So the calendar's *secondary* mechanism — the `nc_etag` / `baseline_etag` map baseline — is **promoted to the primary and only echo-suppression mechanism** for contacts.

### Inbound classification (Google→NC), per changed `resourceName`

Look up the map row by `google_resource_name`:

1. **No row** → `LOCAL_NEW` from Google's side: a contact created on Google we've never seen → **create** NC card, insert map row (`origin=google`), record `baseline_etag`=incoming etag, `google_updated`=incoming updateTime, `nc_etag`=resulting card `lastmodified`.
2. **Row exists, `metadata.deleted=true`** → Google deletion → **delete** NC card (authoritative, §6), delete map row.
3. **Row exists, incoming `etag == baseline_etag`** → **ECHO of our own outbound write** → no-op (this is the case the calendar tag would have caught; here the matching etag is the proof).
4. **Row exists, incoming `etag != baseline_etag`** → **real Google-side edit** → apply LWW (§6): if `google_updated > nc_card.lastmodified` rewrite the NC card, then refresh `baseline_etag`, `google_updated`, `nc_etag` from the result; else the NC side is newer → leave NC, let the next outbound pass push NC→Google.

### Outbound write must record the new etag (so its own echo is suppressed)

The echo gate in step 3 only works if every outbound write **captures the etag Google returns and stores it as the new `baseline_etag` before the next inbound poll**:

- `createContact` → response carries `resourceName` + `etag` → insert/patch map row with both, set `state=synced`.
- `updateContact` → **must send `updatePersonFields` mask + `person.etag` = current `baseline_etag`** (optimistic concurrency). On success, store the **returned** etag as the new `baseline_etag`. On **400 `failedPrecondition`** (stale etag → someone edited on Google between our read and write): re-read that contact, re-run inbound classification/LWW, then retry once. This is the contacts analog of calendar's conflict path.
- After any outbound write, also refresh `nc_etag` from the NC card's `lastmodified` so the *next* CardDAV change-token delta doesn't re-classify our own write as a `LOCAL_EDIT`.

So both directions are gated by stored baselines: inbound echoes are caught by matching `baseline_etag`; the NC-side echo of an inbound apply is caught by matching `nc_etag`. No origin tag required.

> Caveat (People docs): incremental sync is "not intended for read-after-write" — propagation can lag minutes. Our write's echo may surface a poll or two later; the persistent `baseline_etag` survives across job runs, so the gate still fires whenever it arrives.

---

## 4. Identity

- **Google-imported cards** today use **NC card URI = sanitized `resourceName`** (`str_replace('/', '_', …)`, e.g. `people/c123` → `people_c123`). Stable, Google-assigned, deterministic.
- **NC-created cards** will use an **NC UID-based URI ≠ `resourceName`** (Google assigns `resourceName` only at `createContact` time; before that there is no Google id).

Therefore the **map is the source of truth for the `resourceName` ↔ `card_uri` correspondence**, exactly like `event_map` is for events — the URI is *not* a reliable derivation of the Google id once NC-origin contacts exist. Reverse lookups (NC URI → resourceName for outbound) go through the map, never through string transforms.

**Slash sanitization:** the `/`→`_` transform is **lossy/ambiguous** (a real `_` in an id is indistinguishable from an escaped `/`). We keep the sanitized form as the *URI* for backward compatibility with already-imported cards, but the map stores the **raw, unsanitized `google_resource_name`** as the authoritative id. All Google calls use the raw value from the map; the sanitized URI is only an NC-storage key.

---

## 5. De-duplication (C1)

Two problems: **(a) collapse existing dupes** from prior one-time imports (same contact imported into multiple address books, or re-imported as pre-map cards), and **(b) prevent new dupes**.

**Matching, in priority order:**
1. **Primary — `resourceName`** (via the map, or via the card URI for legacy pre-map cards). Exact, authoritative, zero false positives.
2. **Fallback — email + structured name** (`EMAIL` set intersection AND normalized `N`/`FN` match) for legacy cards whose URI was mangled or that predate any stable id. Heuristic → only auto-acts on high-confidence (shared email **and** name); ambiguous matches are flagged, not merged.

**Merge-vs-keep-one policy — RECOMMEND keep-one (canonical card), not field-merge.** Field-level vCard merging is lossy and hard to make idempotent across re-syncs; keeping a single canonical card per `resourceName` is deterministic and reconciler-friendly. Canonical selection: prefer the card already in the user's **default/target** address book; tie-break to the most-recently-modified. Non-canonical dupes are removed (or moved) and a single map row is established for the survivor. (Owner may override to "merge" — see §8b; if so, merge is union-of-fields with NC winning on direct conflict, then one canonical card remains.)

**Execution:**
- **One-shot dedup pass** (C1 entry): scan all sync-enabled address books, group by `resourceName` (primary) then by email+name (fallback), pick canonical, delete/relocate dupes, write one map row each. Idempotent and re-runnable.
- **Ongoing prevention:** with the map in place, inbound step 1 (§3) only creates a card when **no map row and no resourceName match** exists — structurally, an already-mapped contact can never be re-imported as a second card.

---

## 6. Conflict policy

**RECOMMEND: reuse the calendar policy unchanged — last-writer-wins, ties → Nextcloud.**

- **LWW inputs:** Google `metadata.sources.updateTime` vs. NC card `lastmodified` (the same two timestamps the importer already compares in `GoogleContactsAPIService::importContacts()`). Strictly-newer wins; **on a tie, Nextcloud wins** (matches calendar).
- **etag drives detection, updateTime drives resolution:** `etag != baseline_etag` (inbound) or a CardDAV change-token delta (outbound) tells us *something changed*; `updateTime`/`lastmodified` tells us *which side wins*. The optimistic `etag` on `updateContact` (§3) protects the actual write from a racing Google edit.
- **Deletions are authoritative**, not LWW: a Google deletion (`metadata.deleted=true`) deletes the NC card; an NC card deletion (CardDAV delete delta) calls `deleteContact`. (Delete-vs-concurrent-edit resolves to delete, matching calendar.) No field-level diffing — whole-vCard replace, as today.

---

## 7. Phased plan — C0 → C1 → C2

Each phase follows the calendar cadence: **branch → lab validation → adversarial review → PR → CI green**. Phases are independently shippable.

### C0 — Continuous incremental inbound (no outbound yet)
- New `calbridge_contacts_map` table + migration.
- **Identity backfill:** populate map rows for already-imported cards (URI→resourceName).
- **Per-address-book "Sync contacts" toggle** (analog of calendar two-way flag), stored in `IConfig` per user+`addressBookId`.
- **`SyncContactsJob`** (background): People `connections.list?requestSyncToken=true` incremental pull; owns + persists `syncToken`; applies creates/updates **and Google-side deletions** (`metadata.deleted=true` → `deleteCard`) — closing today's "never deletes" gap. Cap-and-drain budget per run; advance token only on full success; on `EXPIRED_SYNC_TOKEN` → full resync via map (no dupes).
- Still **read-only scope** — no Google writes in C0.

### C1 — De-duplication
- One-shot dedup pass (§5) + ongoing prevention via the map. Ships on top of C0's map.

### C2 — Outbound NC→Google
- **Re-consent to read-write `contacts` scope** (widen-and-gate, §8a).
- `ContactsReconcileService`: read CardDAV `getChangesForAddressBook` deltas, classify `LOCAL_NEW`/`LOCAL_EDIT`/`LOCAL_DELETE`/`ECHO` against the map, write `createContact`/`updateContact`/`deleteContact` (echo-suppressed per §3, LWW per §6, etag optimistic concurrency, sequential per user). Advance NC change token only after all writes succeed.
- "Other contacts" remain **inbound-only** (Google makes them read-only — §9).

**C2a — CREATE — DONE (4.12.0).** `reconcileOutbound` + `classifyOutbound` + `createNcContactInGoogle` (`people:createContact`). A create that succeeds in Google but whose map row fails to persist is surfaced as an orphan and the token is force-advanced so it can never be re-POSTed (createContact has no client id → a replay would duplicate).

**C2b — UPDATE + DELETE + conflict — DONE (4.13.0).** Implemented entirely in `GoogleContactsAPIService` (no separate service — it lives beside the create path):
- **UPDATE** — `updateNcContactInGoogle` → `people:updateContact` as **POST + `X-HTTP-Method-Override: PATCH`** (NC's `IClient` has no `patch()`). The `updatePersonFields` mask is a **dedicated, writable-only** list (`updatePersonFields()`) that is the EXACT set `buildPersonFromVCard` emits — *not* `connectionsPersonFields()` (which names read-only groups like photos/memberships that Google would CLEAR on the first edit). The mask + sparse body gives correct clear-on-empty for managed fields. The current Google `etag` rides in the body for optimistic concurrency.
- **CONFLICT** — Google's stale-etag reply is **HTTP 400 `failedPrecondition`** (not 412 like calendar); it is disambiguated from a genuine malformed-body 400 by inspecting the response body, BEFORE the permanent-reject path. On conflict (or a missing baseline) `resolveUpdateConflictContact` re-GETs the live contact and applies LWW (§6, ties→NC): **NC wins** → single re-PATCH with the fresh etag; **Google wins** → abandon **without** touching `baseline_etag`, so the next inbound pull (incoming etag ≠ baseline) applies Google's newer version (never blind-clobber).
- **DELETE** — `deleteNcContactInGoogle` → `people:deleteContact`, which is **unconditional** (no If-Match), so NC-delete-wins is automatic and there is **no delete-conflict path**. Idempotent on 404/410. The card is already gone, so the `resourceName` comes from the map row (contacts have no `extendedProperties` → no live "is it ours" re-check; ownership is the map row alone). The row is removed only on success/idempotent-404.
- **No-ping-pong** — a successful write refreshes BOTH baselines (`baseline_etag` from the response's new Google etag; `nc_etag` from the card's current etag), so the next inbound pull sees `ECHO` (incoming etag == baseline) and the next outbound classify sees `ECHO` (current etag == nc_etag). C2b does **not** gate pushes on the `origin` column (a Google-origin card can be legitimately edited in NC and must push); echo suppression rests on the etag baselines + LWW.
- **Token discipline** — UPDATE/DELETE share a cap-and-drain `contactsWriteBudget()` (separate from creates); a failed/conflicted write HOLDS the token (idempotent on replay — etag-guarded patch, 404-idempotent delete), so no CREATE-style orphan/`$mustAdvance` half-state.

---

## 8. Owner decisions (need sign-off)

> The following four decisions block implementation and need owner sign-off. Recommendations given.

**(a) Add the read-write `contacts` scope (required for C2).**
**RECOMMEND: YES, widen-and-gate.** Required for any outbound write; `contacts.readonly` cannot mutate. It is a Google-sensitive scope (re-consent for existing users, and Google verification for the OAuth app). Mitigate exactly as calendar did: request the wider scope but **gate** all outbound behind the per-address-book "Sync contacts" toggle so consent ≠ automatic writes. C0/C1 ship on read-only; only C2 needs it.

**(b) Dedup merge policy.**
**RECOMMEND: keep-one canonical card** (deterministic, reconciler-safe) over field-level merge (lossy, non-idempotent). See §5. Owner may opt into union-merge (NC wins conflicts) if field-preservation across historically-split cards matters more than simplicity.

**(c) Conflict policy.**
**RECOMMEND: reuse calendar LWW, ties → Nextcloud**, deletions authoritative (§6). Consistent mental model across the app; no new semantics for users to learn.

**(d) Keep "other contacts" in scope, or drop?**
**RECOMMEND: keep, but inbound-only.** `contacts.other.readonly` is read-only on Google's side — they can never be pushed back (§9). Keeping them gives users the existing import breadth; just never attempt outbound for any card whose map row originates from `otherContacts`. Dropping is acceptable if the read-only asymmetry is judged too confusing.

---

## 9. Risks / accepted limits

- **No origin tag (echo suppression rests entirely on etag/updateTime baselines).** The single point of failure the calendar avoided with `ncOrigin`. Mitigated by persisting `baseline_etag`/`nc_etag` in the map across job runs; an echo is suppressed whenever it arrives, even runs later.
- **etag staleness** — a long sync interval vs. a concurrent Google edit yields 400 `failedPrecondition` on `updateContact`. Handled by re-read → re-classify (LWW) → retry-once; not a data-loss path, just an extra round-trip.
- **"Read-after-write" lag** — People incremental sync may surface our own write a poll or two later; baselines are persistent so this is benign (no double-apply).
- **Address-book-level vs account-level mapping** — the map is keyed per `(nc_addressbook_id, nc_card_uri)`; the *same* Google contact synced into two address books is two map rows for one `resourceName`. The dedup pass + the `resourceName` index keep this coherent, but cross-address-book identity is a known sharp edge.
- **`otherContacts` are read-only on Google** — outbound is structurally impossible for them; v1 treats them inbound-only. Promoting an "other contact" to a writable "My Contact" is a future, explicit user action, not auto.
- **No CardDAV / address-book lifecycle events** in OCP (unlike calendar's `CalendarDeletedEvent` etc.). The job must **poll** `getChangesForAddressBook` and detect address-book trash/restore/delete by query, not by event subscription. Token ownership across trash/restore is undefined → on a missing/changed address book, drop its tokens and re-establish via the map.
- **Sync-token expiry (7 days)** — gaps > 7d force a full resync; safe because the map prevents re-creation of dupes.
- **Orphaned map row vs dedup (C1)** — if a synced card's map-row write ever fails (the card is created but `recordMapping` errors out persistently), that card looks *unmapped* to the dedup pass and, if it high-confidence-matches one other synced card, could be deleted as a stray. Mitigations: the dedup pass loads the whole map in ONE query and **aborts** if that read fails (so a transient error never misclassifies), deletions go to the recoverable Contacts trash, and the match requires name + a shared email. The residual (a *persistently* un-recorded synced card that also collides with a different contact's name+email) is rare and recoverable; surfacing persistent map-write failures is future hardening.
- **Address-book ownership** — the sync and dedup entry points (`syncAddressBook`, `dedupeAddressBook`, `setSyncContacts`) verify the address book is owned by the calling user (via `getAddressBooksForUser`) before reading/writing/deleting cards, so a crafted `addressBookId` cannot touch another user's contacts.
- **Groups (CATEGORIES) and photos out of v1 scope** — import-only as today; not round-tripped (group resource-name mapping and photo re-upload are their own subprojects).
- **People API quota** — per-user 10k/day, 2400 QPM; batch endpoints (`batchCreate` 200, `batchUpdate` 200, `batchDelete` 500 per request) and exponential backoff on 429 keep large reconciles within budget. Sync (`syncToken`) quota is fixed/non-increasable — another reason to use incremental deltas, never full re-fetch.

---

*Architecture mirrors the merged calendar bidirectional sync (P0–P4). The one genuine divergence is echo suppression (§3); everything else is a rename of a proven pattern.*
