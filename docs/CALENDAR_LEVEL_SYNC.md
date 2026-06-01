<!--
  - SPDX-FileCopyrightText: 2026 Alex DiMarco
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Design: Calendar-level sync (create a Google calendar from a Nextcloud one)

**Status: IMPLEMENTED (P-a through P-d shipped, plus trash pause/resume).**
This document was the design/review gate; the phased plan in §7 tracks status.

Today the app syncs *events* within calendars that originate on Google. This
proposal adds the missing direction at the **calendar** level: take an existing
**Nextcloud** calendar, create a matching calendar in **Google**, link them, and
two-way sync from then on — with a button in the app's Personal settings.

It also documents the already-shipped Google→Nextcloud onboarding for symmetry.

---

## 1. Why this is not just a button

Two assumptions in today's code are load-bearing and must change first.

### 1a. Calendar identity = "NC URI equals the Google calendar id"
`importCalendar()` resolves/creates the Nextcloud calendar as
`$newCalUri = urlencode($calId)` (GoogleCalendarAPIService.php:682, created at
:692), and **everything** downstream is keyed on the Google `calId`: the
`ImportCalendarJob` argument, the inbound `sync_token_<md5(calId)>`, the outbound
`nc_change_token_<md5(calId)>`, the `two_way_<md5(calId)>` flag, and the new
`last_verify_<md5(calId)>` pref.

A pre-existing Nextcloud calendar has its **own** URI (a UUID), which will never
equal a freshly-created Google id. So linking *that* calendar to a *new* Google
calendar requires an explicit **calendar-level mapping** — the calendar analogue
of the existing `calbridge_event_map`.

### 1b. The OAuth scope can't create calendars
`calendar.events` (the read-write scope that powers today's two-way) can manage
*events* but **cannot create calendars**. Creating a calendar needs an additional
scope (decision below), which means a **one-time reconnect** per user.

---

## 2. Locked decisions

| Decision | Choice |
| --- | --- |
| OAuth scope for creating calendars | **`https://www.googleapis.com/auth/calendar.app.created`** (minimal — create + manage only app-created calendars), requested **in addition to** the existing `calendar.events`. |
| Button placement | **In the app's Personal settings** — a new "Your Nextcloud calendars" list, mirroring the existing Google-calendar list. |

`calendar.app.created` semantics: the app can create *secondary* calendars and
fully manage events on calendars **it** created, but can never touch calendars it
didn't create. Existing shared/owned Google calendars keep using `calendar.events`.
Net granted set after re-consent: today's read scopes + `calendar.events` +
`calendar.app.created`.

---

## 3. The two directions

### Direction B — Google → Nextcloud (ALREADY SHIPPED)
Clicking **Sync calendar** → `/sync-calendar` → `registerSyncCalendar()` adds an
`ImportCalendarJob`; first run creates the NC calendar (URI = `urlencode(googleId)`)
and imports. New Google calendars appear automatically (live `calendarList` fetch).
*Optional polish, independent of this design: a "Sync all" button and a badge for
not-yet-synced Google calendars.*

### Direction A — Nextcloud → Google (THIS PROPOSAL)
A new **"Your Nextcloud calendars"** section lists the user's owned, writable,
non-app-managed calendars. Each has a **"Create in Google + two-way sync"** button.

```
Personal → Calendar Bridge
  Google calendars
   • Work        [Sync] [Two-way]
   • Family      [Sync] [Two-way]
  Your Nextcloud calendars
   • Personal    [Create in Google + sync]
   • Project X   [Create in Google + sync]
```

---

## 4. Design

### 4a. OAuth scope + re-consent
- Add `CALENDAR_APP_CREATED_SCOPE = '.../auth/calendar.app.created'` to
  `ConfigController` and to the scope list the connect button requests
  (`PersonalSettings.vue`, alongside the existing calendar scopes).
- In `oauthRedirect()`'s `$scopesArray`, derive a new flag
  `can_create_calendar = in_array(CALENDAR_APP_CREATED_SCOPE, $scopes) ? 1 : 0`.
  This joins `user_scopes` (written ONLY by the redirect — never user-settable,
  consistent with the existing `can_write_calendar` security model).
- The new button is gated on `state.user_scopes.can_create_calendar` (UI) AND a
  server-side check (defense in depth, like `setTwoWaySync`). Users who connected
  earlier see a "reconnect to enable" hint.

### 4b. New calendar-mapping table
A small table, the calendar analogue of `calbridge_event_map`:

```
calbridge_calendar_map
  id            integer  PK
  nc_cal_id     integer  the Nextcloud calendar id
  nc_cal_uri    string   the NC calendar URI (for resolution/debug)
  google_cal_id string   the Google calendar id
  origin        string   'nc'      = we created the Google calendar from an NC one
                         'google'  = imported from Google (optional backfill)
  created_at    integer
  UNIQUE(nc_cal_id)        -- one Google calendar per NC calendar
  UNIQUE(google_cal_id)    -- and vice-versa
```

Only **`origin='nc'`** rows are strictly required for v1 (the new direction).
Google-originated calendars keep working via the legacy URI=id fallback, so we do
**not** need to backfill them on day one.

### 4c. Generalize calendar resolution (backward-compatible)
The single behavioural change to the importer: replace the implicit
`URI = urlencode(calId)` resolution with a **map-first, legacy-fallback** lookup.

```
resolveNcCalendar(userId, googleCalId):
  row = calendarMap.findByGoogleId(googleCalId)
  if row: return row.nc_cal_id            # NC-originated pairing — use the existing calendar, never create
  else:   <legacy path unchanged>         # URI = urlencode(googleCalId); find or create
```

The reconciler (`reconcile(userId, calId, ncCalId)`) already takes `ncCalId`
explicitly — `importCalendar` just feeds it the resolved id. The job argument,
tokens, two-way flag and `last_verify` stay keyed on the Google `calId`, so the
rest of the machinery (inbound import, outbound reconcile, verify pass) is reused
unchanged. This is the crux: **one resolution seam, everything else composes.**

### 4d. The NC→Google create flow
New endpoint `POST /sync-nc-calendar { ncCalUri }` → a service method that:
1. Resolves the NC calendar (must be owned + writable; reject birthdays/holidays/
   already-mapped/app-import calendars).
2. **Creates the Google calendar** `Y` via a new `calendars.insert` wrapper.
   **DECIDED:** set `summary` = NC display name and `timeZone` = the NC calendar's
   `calendar-timezone` (both free on the insert call); **color is deferred to P-d**
   (it lives on the *calendarList* entry, needs a separate `calendarList.patch` +
   hex→palette handling). Requires `can_create_calendar`. Deterministic retry: on a
   transient failure, re-running must not create duplicates — so either look up by
   summary or store an in-progress marker before the insert.
3. Records a `calendar_map` row `(nc_cal_id=X, google_cal_id=Y, origin='nc')`.
4. `registerSyncCalendar(userId, Y, name, color)` — reuses the existing job
   registration (resolution now finds X via the map).
5. Enables two-way for `Y` (`setTwoWayEnabled`), which requires `can_write_calendar`
   too — so the button needs **both** scopes; surface that in the gate/hint.
6. Triggers the **initial bulk push** (§5).

### 4e. UI
- New controller `getNcCalendarList()` → `CalDavBackend::getCalendarsForUser(
  'principals/users/'.$uid)`, filtered to owned/writable and **excluding** any
  calendar already in the map or matching the legacy import-URI scheme.
- `PersonalSettings.vue`: a "Your Nextcloud calendars" `<ul>` under the Google
  list; each row a name + a button → `POST /sync-nc-calendar`; show state
  (linked / not linked) and disable with a reason when scopes are missing.

---

## 5. Initial bulk push (the one genuinely new runtime behaviour)

The new Google calendar `Y` starts **empty**, and `X`'s existing events are
genuinely local (not echoes), so they must all be created in `Y`.

This is NOT the same as enabling two-way on an existing Google calendar, where
`setTwoWayEnabled` deliberately **re-baselines the token to skip** the initial set
(those events came FROM Google = echoes). For an NC-originated pairing we must do
the opposite: classify the whole calendar as `LOCAL_NEW` once.

Mechanically the event map has **no rows** for `X`'s objects, so
`classifyChange` already returns `LOCAL_NEW` for each → `createLocalEventInGoogle`
/ `createLocalSeriesInGoogle`. So the bootstrap is simply: **do not baseline-skip
for `origin='nc'` calendars** — process the calendar from an empty change token
once, letting every object classify `LOCAL_NEW` and push. Recurring series route
to the recurrence differ as usual.

Considerations:
- **Quota / volume — DECIDED: cap-and-drain.** A large calendar = a big first delta
  (a `LOCAL_NEW` storm), and Google throttles write bursts. Use a per-tick create
  cap (a tunable method like `instanceOpBudget()`) with token-hold: a typical
  calendar finishes in one tick, while a pathological multi-thousand-event calendar
  drains over several background ticks (already-pushed events classify `ECHO` next
  tick, so it converges). Quota-safe, bounded lock-hold, no wedge.
- **Attendees — DECIDED: keep stripping.** Today's outbound strips attendees and
  uses `sendUpdates=none`; keep that, so the initial push can never blast invite
  emails. Document the limitation: "event details sync, guest lists do not." Full
  attendee/RSVP sync is a separate, larger feature.
- **Echo.** Pushed events carry `ncOrigin` tags + map rows; the inbound import of
  `Y` resolves `ncCalId=X` via the map (§4c) and recognises them as echoes — no
  duplicate NC calendar, no re-import.

---

## 6. Edge cases & risks

| Risk | Mitigation |
| --- | --- |
| The calendar-resolution change touches the importer's most central path | Map-first + **unchanged legacy fallback**; lab-verify existing Google-originated calendars are byte-for-byte unaffected before shipping the new path. |
| Duplicate Google calendar on retry of the insert | Mark "creation in progress" / look up by summary before inserting; the `UNIQUE(nc_cal_id)` row guards the mapping. |
| Initial-push quota storm | Per-tick create cap + token-hold (mirrors the recurrence budget breaker). |
| Un-sync controls (DECIDED) | Two actions: **"Disconnect Nextcloud/Google calendar"** — stop the job, clear the two-way flag, **keep BOTH calendars + their events** (just unlinked; re-linking reuses the same pairing, no duplicate). And **"Delete both calendars"** — a destructive, **explicitly-confirmed** action that deletes the Nextcloud calendar AND the Google calendar (and all events on both). Confirmation must be unambiguous: in the NC-origin case it removes the user's *original* Nextcloud calendar. |
| User deletes the Google calendar by hand | Next sync 404s on `Y`; handle as a clean "calendar gone → stop syncing + log + drop the map row", not an error loop (P-c). |
| `last_verify`/MapVerifyService on a mapped calendar | The verify pass already keys on `calId` and resolves rows by `nc_cal_id`; with the map it just needs the same map-aware `ncCalId` — no special-casing. |
| Calendar rename propagation (either way) | **Out of scope for v1** (see §8). |
| Two NC calendars → one Google calendar (or vice-versa) | Prevented by the two UNIQUE constraints. |

---

## 7. Phased plan

- **P-a — OAuth scope plumbing. ✅ DONE (#25).** `calendar.app.created` requested +
  `can_create_calendar` flag derived in the OAuth redirect (forge-proof).
- **P-b — Calendar map + resolution seam. ✅ DONE (#25).** `calbridge_calendar_map`
  table + map-first `importCalendar`/reconcile resolution with legacy fallback;
  lab-verified no regression on Google-originated calendars.
- **P-c — NC→Google create flow + UI. ✅ DONE.** `calendars.insert/delete` wrappers
  (name + timezone); `linkNcCalendarToGoogle` (create Y, map w/ rollback, register,
  enable two-way) + the cap-and-drain bootstrap in the reconciler; the controller
  endpoints + routes; the "Your Nextcloud calendars" settings UI with **Create in
  Google + sync**, **Disconnect**, and confirmed **Delete both calendars**.
  Lab-verified: cap-and-drain bootstrap (2→4→5, held→drained), scope gate, clean
  link-error rollback, delete-both. NOTE: the real `calendars.insert` success path
  needs a user reconnect (calendar.app.created) and so is exercised in production,
  not the lab.
- **P-d — polish. ✅ PARTLY DONE.** Shipped this slice (lab/build-verified):
  - *(MED)* ✅ A **permanently-Google-rejected event** (a 400/422 malformed body)
    no longer wedges the token: it is terminal (`SKIPPED_REJECTED`, advances the
    token, logged, left one-way); 403/404/410/5xx/429 stay transient (hold +
    retry). Covers EVERY outbound write path — flat create + flat update, recurring
    master create + master PATCH, and the per-instance override/cancel/restore
    (which now treat a permanent rejection like their 404/410 no-op so
    `runInstanceDiff` does not bubble ERROR). *(The P-d adversarial review caught
    that the first cut hardened only the two CREATE paths; the UPDATE/PATCH +
    per-instance paths were then completed.)* Lab-verified: create 400→advance /
    500→hold, and update 400→advance.
  - *(LOW)* ✅ An NC calendar **deleted out-of-band** is cleaned up by a
    `CalendarDeletedListener` on `CalendarDeletedEvent` (drops the map row,
    unregisters the job, clears two-way; keeps the Google calendar). Lab-verified.
  - *(LOW)* ✅ `showServerError` now surfaces the server's `{error: ...}` body as
    the headline (falls back to the axios message), and **HTML-escapes** it (a
    review-caught XSS: server/Google error bodies flowed into the isHTML toast).
  - *(small)* ✅ Direction-B **"Sync all"** button + **"(not synced)"** marker.

- **Trash pause/resume. ✅ DONE.** Soft-delete (the *normal* Calendar-app delete)
  now fires `CalendarMovedToTrashEvent` → the pairing is **paused** (import job
  unregistered + two-way off) but the `calbridge_calendar_map` row is **kept**, so
  the job stops ticking into an invisible calendar for the whole ~30-day retention
  window. `CalendarRestoredEvent` (un-trash) **resumes** it (re-registers the job +
  re-enables two-way); because the event-map rows survive trashing, the re-baseline
  classifies every event as an ECHO rather than re-pushing. The three lifecycle
  events (delete=unlink, trash=pause, restore=resume) are handled by one
  `CalendarPairingListener` (replaces `CalendarDeletedListener`). Lab-verified
  end-to-end (trash→restore→purge), since these are NC-side events that fire
  without the `calendar.app.created` scope.

  Still DEFERRED:
  - *(MED)* The mid-flight-cron-tick **ghost** on delete-both/disconnect teardown
    (no import flock) — genuinely concurrency-tricky, can't be lab-verified without
    the create scope, and a near-zero-probability, non-destructive glitch (a stray
    empty import calendar). Recommendation: fold a "don't `createCalendar` on a
    failed events fetch" guard into the importer the next time that resolution path
    is touched — it kills this ghost *and* any 404-ghost for free, rather than
    building bespoke teardown locking.
  - Calendar **rename** propagation; **color** mirroring (modifies the shared
    `request()` + is lab-unverifiable); attendee/RSVP sync (large separate feature).

Each phase: branch off `main`, lab-verify on the sacrificial account, adversarial
review workflow, PR, CI — the established cadence.

---

## 8. Out of scope for v1
- Calendar **rename** propagation in either direction (delete IS handled — see the
  "Delete both calendars" action in §6). 
- **Color** mirroring (deferred to P-d) and any sharing/ACL sync; **timezone** IS
  mirrored on create.
- **Attendee / RSVP** sync — the initial push strips guest lists (§5).
- Adopting/managing Google calendars the app did **not** create (impossible under
  `calendar.app.created` by design — would need the full `calendar` scope).
- Backfilling `origin='google'` rows into the calendar map (legacy fallback covers
  existing calendars; backfill only if a later feature needs it).

---

## 9. Resolved decisions (owner, 2026-05-31)
1. **Initial-push pacing:** **cap-and-drain** — a tunable per-tick create cap with
   token-hold; normal calendars finish in one tick, huge ones drain over several
   background ticks (§5).
2. **Attendees on the initial push:** **keep stripping** them (current outbound
   behaviour); documented as a known limitation (§5).
3. **Un-sync UX:** two actions — **"Disconnect Nextcloud/Google calendar"**
   (unlink, keep both calendars) and **"Delete both calendars"** (destructive,
   explicitly confirmed — deletes the NC *and* Google calendar) (§6).
4. **Create fidelity:** mirror **name + timezone** on `calendars.insert`; **color
   deferred** to P-d (§4d).
