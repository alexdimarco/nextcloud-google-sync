# Project log

Project working title: **Calendar Bridge** (target app ID: `outside_provider_calendar_bridge`)
Forked from: [MarcelRobitaille/nextcloud_google_synchronization](https://github.com/MarcelRobitaille/nextcloud_google_synchronization)
Fork home: [alexdimarco/nextcloud-google-sync](https://github.com/alexdimarco/nextcloud-google-sync)
License: AGPL-3.0

## 2026-05-25 — Phase 1, fix #2: ORGANIZER and ATTENDEE emission

Fixes the "Test multi-attendee" failure flagged in `docs/PHASE_0_TEST_DATA.md`
(row 7) and the corresponding audit finding ("Attendees are silently
dropped").

- **Files touched**: `lib/Service/GoogleCalendarAPIService.php` only. Added
  `buildOrganizerLine()`, `buildAttendeeLine()`, `quoteIcalParam()`
  private helpers; called from `generateEventData()` immediately after
  the `LAST-MODIFIED` block, before `VALARM`.
- **Mapping**:
  - `$e['organizer'].email` → `ORGANIZER[;CN="..."]:mailto:...`
  - `$e['attendees'][]` → `ATTENDEE;[CN="..."];[CUTYPE=RESOURCE];ROLE=REQ-|OPT-PARTICIPANT;[PARTSTAT=...]:mailto:...`
  - `responseStatus`: accepted/declined/tentative/needsAction → matching `PARTSTAT`
  - `optional: true` → `ROLE=OPT-PARTICIPANT` (else REQ-)
  - `resource: true` → `CUTYPE=RESOURCE`
  - `displayName` (when present) → `CN="..."` with always-quoted param value
- **Verification**: deleted the local copy of `7fmmi9c0vudpl72j1tkjlmamga`
  ("Test multi-attendee"), force-executed the primary-calendar sync,
  inspected the rewritten ICS. Output:
  ```
  ORGANIZER:mailto:dimarcotech@gmail.com
  ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:fake1@example.com
  ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:dimarcotech@gmail.com
  ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:fake2@example.com
  ```
  All three attendees + the organizer present. PARTSTAT mapping correct
  (Google's `needsAction` → `NEEDS-ACTION`, `accepted` → `ACCEPTED`).
- **Coverage gap** (worth knowing): The test event doesn't exercise the
  `CN=` (displayName), `OPT-PARTICIPANT` (optional), `TENTATIVE`/`DECLINED`
  PARTSTAT, or `CUTYPE=RESOURCE` branches — the fake attendees lack
  displayNames and no organic event in the lab account got re-imported
  in this sync (Google's `updated` field didn't change for any of them).
  All four branches are simple deterministic mappings; the risk of bug
  is low, but a follow-up spot-check on a real meeting invite is worth
  doing.
- **Migration note**: Existing imported events keep their attendee-less
  ICS until they're re-touched by a sync (which only happens when Google
  reports a newer `updated` timestamp). A one-shot "force re-import all"
  is out of scope for this fix.

## 2026-05-25 — Phase 1, fix #1: cancelled recurring instances

Fixes the "Test recurring with cancellation" failure flagged in
`docs/PHASE_0_TEST_DATA.md` (row 5).

- **Approach**: emit `EXDATE` lines on the master `VEVENT` rather than
  attempting to synthesize `STATUS:CANCELLED` overrides. This reverses the
  speculative preference noted in PROJECT_LOG question #6 — actually
  implementing the override path required synthesizing DTSTART/DTEND from
  the master's duration (Google omits dates for cancelled instances), and
  EXDATE is simpler, universally supported by CalDAV clients, and matches
  the "deleted in Google → gone in NC" UX users expect. The only
  information loss is the explicit "cancelled vs. never scheduled"
  semantic, which the previous code didn't surface either.
- **Files touched**: `lib/Service/GoogleCalendarAPIService.php` only.
  `generateEventData()` now partitions exceptions: cancelled ones become
  EXDATE lines inside the master VEVENT; live (rescheduled) ones keep the
  existing inline-override behavior.
- **Verification**: deleted the locally-stored master for
  `7nulnn1h3egv0n3kf8r0pdjpq6` in `oc_calendarobjects`, force-executed
  the `ImportCalendarJob` for the primary calendar, and confirmed the
  rewritten VCALENDAR contains
  `EXDATE;TZID=America/New_York:20260524T150000` — exactly the 3rd
  daily instance (Sun May 24), which is the instance deleted in Google.
  The unrelated "Test recurring with exception" event was not re-touched
  by the sync (Google's `updated` unchanged) and its inline override
  remained intact, confirming no regression to the live-exception path.

### Phase 1 punch list — remaining

(From `docs/PHASE_0_TEST_DATA.md`, items not yet addressed.)

- Emit `ATTENDEE` lines for `attendees[]`. (Test 7.)
- Emit a `VTIMEZONE` block when DTSTART/DTEND use `TZID`. (Test 6 + audit.)
- Bump `info.xml` `max-version` to 33 (and verify) so install on the lab
  stops requiring `--force`.
- Stand up a minimal PHPUnit suite before more changes land (decision
  pending — see PROJECT_LOG q8).
- Honor `nextSyncToken` so brand-new Google events don't miss a cron tick.

## 2026-05-20 — Phase 0 complete

- Forked upstream to `alexdimarco/nextcloud-google-sync`. No PHP source modifications.
  - Pre-existing fork at the same destination (a fork of `nextcloud/integration_google` with a custom "chatgpt" commit on top, dated 2026-05-03) was deleted from GitHub after archiving its local working copy to `~/gitprojects/_archived-nextcloud-google-sync-chatgpt/`. That archive still has the full git history of the prior attempt and can be revived later if needed.
- Installed in lab Nextcloud at http://localhost:8080. Lab is NC 33.0.3; fork pins `min/max-version=32`, so enable required `occ app:enable --force`. App reports as `google_synchronization: 4.1.0` in `occ app:list`.
- Lab `docker-compose.yml` now has an extra bind mount on both `app` and `cron` services so the host clone at `~/gitprojects/nextcloud-google-sync/` shows up inside the container at `/var/www/html/custom_apps/google_synchronization`. Live edits on the host are immediately visible to NC.
- Baseline one-way Google→Nextcloud sync confirmed against sacrificial Google account (`pass google/test-calendar-bridge/{client_id,client_secret}`). 5 of 7 seeded test events imported on the first cron tick; 2 more landed on a forced re-run. See `docs/PHASE_0_TEST_DATA.md` for the per-event table.
- Edge-case test calendar seeded; results in `docs/PHASE_0_TEST_DATA.md`.
- Audit findings in `docs/PHASE_0_AUDIT.md`.
- Google Cloud setup walkthrough in `docs/SETUP_GOOGLE_CLOUD.md`.

### Confirmed-working behavior on this fork (4.1.0, vanilla, no changes)

| Capability | Status |
| ---------- | ------ |
| OAuth flow against `http://localhost:8080` redirect URI | works |
| Per-user token + refresh token storage, encrypted via `OCP\Security\ICrypto` | works |
| "Sync calendar" toggle registers a `TimedJob` per `(user, cal_id)` | works |
| Cron picks up the job and runs `safeImportCalendar()` automatically | works |
| New calendar creation in NC (named `"<google name> (Google Calendar import)"`) | works |
| Simple timed events, all-day events, default reminders | works |
| Recurring events with `RRULE` pass-through | works |
| Recurring-event exceptions (RECURRENCE-ID overrides, inlined in same VCALENDAR resource) | works |
| Multi-calendar sync (primary + 4 shared calendars in this lab account) | works |
| Lock files released cleanly in `finally` block | works |

### Confirmed-broken / gaps observed during Phase 0

| Gap | Cause (from code read) | Evidence |
| --- | ---------------------- | -------- |
| Cancelled-instance overrides silently dropped | `generateEventData()` returns `''` when `start`/`end` are missing, and Google returns cancelled exceptions without DTSTART/DTEND | Test event 5: NC stores only the master, no `STATUS:CANCELLED` override |
| Attendees never emitted | The `attendees[]` field is never read from Google's response, never written to ICS | Test event 7: 2 attendees in Google, 0 in NC ICS |
| No VTIMEZONE block emitted | `mapTime()` writes `TZID=...` but never an opening `BEGIN:VTIMEZONE` block | Test event 6 ICS, confirmed |
| No incremental sync | No `syncToken` use; every cron tick is a full events.list pull | Audit + observed |
| No rate-limit / retry | Errors converted to `['error' => ...]`, no backoff, no 429 handling | Audit |
| Local user edits get clobbered | Only timestamp check; no "leave local edits alone" path | Audit (not exercised in Phase 0) |
| Calendar URI is URL-encoded display name | `urlencode($newCalName)` used as CalDAV URI | Inspect of `oc_calendars.uri` |
| `info.xml` max-version=32 blocks installation on NC 33 without `--force` | Hard-coded in info.xml | Observed during Phase 0 enable step |

## Open questions for Phase 1

1. **App identity / rename strategy.** The target app ID in our project notes is `outside_provider_calendar_bridge`. The fork still ships as `google_synchronization` with namespace `OCA\Google`. A clean rename touches: `appinfo/info.xml`, every PHP namespace, every L10n key, every initial-state key, every route name, the migration's hard-coded `Application::APP_ID` string, and the JS `generateUrl('/apps/google_synchronization/...')` calls. **Should Phase 1 do the rename first** (clean repo identity from the start, painful migration story for anyone running the upstream), **or last** (keep upstream compat through the bulk of dev, rename right before publish)? Either is defensible.
2. **AGPL `COPYING` mismatch.** Upstream ships the GPLv3 text in `COPYING` despite declaring AGPL-3.0 in `info.xml` and `package.json`. Do we fix this in our fork (correct, low-risk) or leave it alone (matches upstream, surprising for anyone reading our repo)?
3. **Bidirectional sync.** The README explicitly scopes this fork as one-way (G→NC). Phase 1 charter says "two-way" — is the plan to add a NC→G write-back path inside the existing `GoogleCalendarAPIService`, or to fork the responsibility into a new service and treat the current code as the inbound side only? Architecture choice influences locking, conflict resolution, and incremental sync strategy.
4. **Multi-tenant tokens.** Right now `client_id` / `client_secret` are global (`oc_appconfig`). For a self-hosted app shared by multiple users, that's fine, but it means every NC user authenticates to the **same** Google Cloud project. Phase 1 candidates: keep as-is (simple), allow per-user OAuth client credentials (private), or move to a hosted "bring-your-own-tenant" model (complex). Pick before designing the admin/personal settings UI.
5. **Background-job interval.** Hard-coded `setInterval(1)`, so every cron tick fires sync of every registered calendar. Upstream issue #2 has been open since 2023 asking for this to be configurable. Is "configurable per-calendar interval" in scope for Phase 1, and if so do we use NC's standard cron-style scheduling or invent something app-specific?
6. **Cancelled-instance handling — pick a behavior.** Two RFC-correct ways exist (RECURRENCE-ID override with `STATUS:CANCELLED` vs `EXDATE` on master). Marcel's code attempts the first but bugs out on missing DTSTART. We can fix either way; the choice affects how external CalDAV clients render the gap. Personal preference: synthesize DTSTART from the master's pattern + the cancelled `originalStartTime`, then emit a real cancelled override — preserves the most information.
7. **NC 33 / 34 compatibility.** The lab is NC 33.0.3, and `info.xml` says `min=max=32`. Bumping to `min=32 max=34` is a one-line edit but commits us to actually verifying NC 33 behavior. Does Phase 1 want a "supported NC range" matrix and CI smoke against multiple NC versions, or just track latest?
8. **Testing posture.** No PHPUnit suite exists. Setting one up now is cheap; setting it up after large changes is expensive. Worth investing a day before any non-trivial Phase 1 code lands?

## Other anomalies observed during Phase 0 (not blocking)

- `composer.lock` in the upstream fork is **out of date** — `php-ds/php-ds` is in `composer.json` but not in the lock file. Required a `composer update --no-dev` to bootstrap `vendor/`. Lock change is not committed (treated as operational).
- The host clone's permissions stayed at `alex:alex` (775/664). Did **not** run the recipe's `sudo chown -R 33:33` because sudo was unavailable in the agent session and the bind mount makes it unnecessary (Apache reads via "other" perms). The recipe's host-side symlink under `~/gitprojects/nextcloud-lab/custom_apps/` was also skipped for the same reason. Both can be added manually for tidiness; neither affects function.
- Google's `events.list` had a measurable propagation lag (~3 minutes) between an event being created in the calendar UI and being visible to the API. Means brand-new events might miss a sync tick. Not fixable from our side without `nextSyncToken` (which we don't use yet) or a small "retry on miss" heuristic.
