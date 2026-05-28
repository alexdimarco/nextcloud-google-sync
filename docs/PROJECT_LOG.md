# Project log

Project working title: **Calendar Bridge** (target app ID: `outside_provider_calendar_bridge`)
Forked from: [MarcelRobitaille/nextcloud_google_synchronization](https://github.com/MarcelRobitaille/nextcloud_google_synchronization)
Fork home: [alexdimarco/nextcloud-google-sync](https://github.com/alexdimarco/nextcloud-google-sync)
License: AGPL-3.0

## 2026-05-28 — Phase 1 punch-list cleanup + audit follow-on

Closed out the remaining four Phase-1 punch-list items in one session,
plus a destructive-on-error bug surfaced while debugging the others.
Five PRs open against `main` at session end (in landing order):
[#3](https://github.com/alexdimarco/nextcloud-google-sync/pull/3),
[#4](https://github.com/alexdimarco/nextcloud-google-sync/pull/4),
[#5](https://github.com/alexdimarco/nextcloud-google-sync/pull/5),
[#6](https://github.com/alexdimarco/nextcloud-google-sync/pull/6),
[#7](https://github.com/alexdimarco/nextcloud-google-sync/pull/7).

### Phase 1, fix #7: preserve local events when Google API errors mid-sync (PR #7)

Not on the original punch list — surfaced this session as an audit-class
finding. When `getCalendarEvents()` returns early due to an API error
(expired refresh token, 5xx, network blip, bogus `calId`), the generator
yields nothing and `importCalendar()` falls through to the "unseen URIs
were deleted in Google" loop. Every locally-stored event gets wiped
because nothing was marked seen. This is how the lab's five imported
Google calendars went from hundreds of events to zero apiece while the
OAuth refresh token was revoked — every cron tick saw a 401 and wiped
the calendars clean.

- **Files touched**: `lib/Service/GoogleCalendarAPIService.php` only.
- **Change**: gate the unseen-URI deletion in `importCalendar()` on
  `!$apiErrored` (in addition to the `!$isIncremental` gate added by
  PR #6). `$apiErrored` is `isset($genReturn['error'])` on the
  generator's `getReturn()`.
- **Verification**: counted events on calendar 6 (`Holidays in Canada`):
  348. Called `safeImportCalendar("admin", "this-cal-does-not-exist@group.calendar.google.com", "Holidays in Canada", …)`
  — i.e. matched the existing local calendar by name but pointed at a
  Google `calId` that returns 404. Result:
  `{nbAdded:0, nbUpdated:0, nbDeleted:0}`. Count on calendar 6 after
  the failed sync: **still 348**. Without the fix, every one of the
  348 would have been deleted from CalDAV.
- **Out of scope**: `importCalendar()` still returns its success shape
  on a fully-failed sync — the caller can't tell from the return that
  nothing happened. Surfacing the error through `BackgroundJob` and
  `Controller` callers is a separate change.

### Phase 1, fix #6: nextSyncToken / incremental sync (PR #6)

Phase-0 audit flagged every cron tick as a full `events.list` pull.
For busy calendars that wastes Google quota and CalDAV write churn —
even when nothing changed, the whole calendar gets rewritten through
`generateEventData`.

- **Files touched**: `lib/Service/GoogleCalendarAPIService.php`,
  `tests/unit/Service/GoogleCalendarAPIServiceHelpersTest.php`.
- **Change**: `getCalendarEvents()` accepts a `syncToken` parameter and
  returns `['nextSyncToken' => string|null]` from the final page (or
  `['error' => …]` on failure). `importCalendar()` loads/saves the
  token in `oc_preferences` under `sync_token_<md5(calId)>` per
  user-calendar. Only used when `consider_all_events=1` (Google's
  `syncToken` is incompatible with `eventTypes` filtering).
- **410 GONE fallback**: token cleared, the request re-issued without
  it on the same tick, the full-pull path runs, and the new token from
  that pull is saved. Detection is a substring read on
  `'status code: 410'` in `GoogleAPIService`'s error string.
- **Cancelled master events on incremental** arrive explicitly with
  `status:cancelled` and are deleted from CalDAV (counted as
  `nbDeleted`, new field in the return shape — additive, no caller
  breaks). On a full pull, the existing "unseen URIs" loop still
  handles deletions; the incremental path replaces it.
- **Cancelled recurring instance on incremental** clears the token to
  force the next tick to be a full pull. The existing EXDATE
  generation in `generateEventData()` needs the full exception list to
  fire correctly; patching the master inline here would duplicate that
  logic. Trade-off: one cron tick of EXDATE drift on cancellations.
- **Bug encountered during dev** (kept me from believing the feature
  worked for ~20 minutes): the first cut had `cancelledRecurringInstance`
  checked on full pulls too, which fires on any well-used calendar with
  historic cancellations, which prevented the token from ever being
  saved. Fix gates the check on `$isIncremental` only.
- **Verification** (lab, 2026-05-28):
  - Clean run: cleared all `sync_token_*` prefs, ran
    `ImportCalendarJob` for primary calendar (`dimarcotech@gmail.com`,
    731 events). Token persisted to
    `sync_token_10d78543c95b5e62d727c391b42074ea`.
  - Steady-state incremental: re-ran. Added 0, token unchanged
    (Google returns the same token when nothing changed). Direct
    `GoogleAPIService` probe confirmed `items=0`, `nextSyncToken`
    present, no `nextPageToken`.
  - 410 fallback: planted a bogus token
    `ThisIsAnExpiredTokenFromBeforeTheBigBang`. Re-ran. `nextcloud.log`
    shows the 410 GONE (`reason: fullSyncRequired`); the token in
    `oc_preferences` after the run is a fresh real Google sync token,
    not the bogus one — fallback path executed end-to-end.

### Phase 1, fix #5: VTIMEZONE emission (PR #5)

Closes the Test 6 + audit finding: imported events carrying `TZID=`
references had no accompanying `VTIMEZONE` block. NC's Sabre layer
resolved the zone internally so on-screen times looked right, but
external CalDAV consumers fetching the ICS saw a `TZID` with no anchor.

- **Files touched**: `lib/Service/GoogleCalendarAPIService.php`,
  `tests/unit/Service/GoogleCalendarAPIServiceHelpersTest.php`.
- **Approach**: post-process the assembled VEVENT text — regex-extract
  TZIDs via `extractTzids()`, build a `VTIMEZONE` block per unique
  TZID via `buildVTimezoneBlock()`, splice the blocks into the
  `VCALENDAR` wrapper before `$eventData` in `importCalendar()`. No
  changes to `mapTime()`'s signature. Safe to regex because `mapTime()`
  is the only emitter of `;TZID=` in our output and only writes IANA
  names (no chars that need quoting).
- **VTIMEZONE shape**: one STANDARD and (if the zone has DST) one
  DAYLIGHT subcomponent, populated from
  `DateTimeZone::getTransitions()` over a ±400-day window around
  `now`. No RRULE — clients with a tz database (all modern ones)
  resolve future transitions from the TZID; the subcomponents anchor
  the offset for the current era. Empty string for unknown TZIDs.
- **Verification** (lab, post-OAuth-reauth, 2026-05-28): re-imported
  all calendars. DB query: **33 of 33 events carrying `TZID=`
  references have an accompanying `VTIMEZONE` block** (100%). Sample
  (cal 3, `America/New_York` event):

  ```
  BEGIN:VTIMEZONE
  TZID:America/New_York
  BEGIN:STANDARD
  DTSTART:20261101T020000
  TZOFFSETFROM:-0400
  TZOFFSETTO:-0500
  END:STANDARD
  BEGIN:DAYLIGHT
  DTSTART:20270314T020000
  TZOFFSETFROM:-0500
  TZOFFSETTO:-0400
  END:DAYLIGHT
  END:VTIMEZONE
  ```

  Nov-first-Sunday at 02:00 and March-second-Sunday at 02:00 match the
  actual US/Canada DST transitions for that cycle.

### Phase 1, fix #4: minimal PHPUnit suite (PR #4)

Resolves project-log Q8 with a "yes" — the next two items (VTIMEZONE,
nextSyncToken) both have branches awkward to exercise from the lab, so
the test harness landed first.

- **Files touched**: `composer.json` (added `phpunit/phpunit ^10.5`
  dev-dep + autoload PSR-4 entries), `composer.lock`,
  `phpunit.xml` (new), `tests/bootstrap.php` (new),
  `tests/unit/Service/GoogleCalendarAPIServiceHelpersTest.php` (new).
- **Approach**: pure-helper tests only. No NC bootstrap. The service
  is instantiated via `ReflectionClass::newInstanceWithoutConstructor()`
  with `utcTimezone` seeded by reflection (because `mapTime()` reads
  it). Private methods reached via reflection. `phpunit 10.5` is the
  last line that supports PHP 8.1 per `composer.json` `platform.php`.
- **Coverage on initial suite** (21 tests): `quoteIcalParam` (3),
  `mapTime` (4), `buildOrganizerLine` (3), `buildAttendeeLine` (10
  inc. data provider). Extended on later PRs to 26 (sync-token) / 29
  (VTIMEZONE).
- **composer.lock** also picks up the upstream `php-ds/php-ds`
  resolution that Phase-0 had been carrying as an operational
  uncommitted diff. Composer can't lock only the new dev-dep on a
  manifest with an unresolved `require`, so the two land together.

### Phase 1, fix #3: NC 33 max-version bump (PR #3)

One-line removal of the `--force` install friction noted in Phase 0.

- **Files touched**: `appinfo/info.xml` only.
- **Change**: `max-version="32"` → `max-version="33"`. Min stays at 32
  (nothing is known to require 33).
- **Verification**: `occ app:disable google_synchronization` followed
  by `occ app:enable google_synchronization` (no `--force`) succeeded
  on the lab NC 33.0.3. All five registered `ImportCalendarJob`
  entries survived the round-trip.

### Other things that happened today

- **OAuth refresh token revocation**: sometime between Phase 0 (May 20)
  and today (May 28), the lab's Google OAuth refresh token went
  invalid (`invalid_grant: Token has been expired or revoked`). This
  combined with the destructive-on-error bug (now fixed in PR #7) had
  silently emptied all five imported Google calendars over the
  intervening week. Cron tick + 401 + unseen-URI-deletion fired
  repeatedly until each calendar reached zero objects. Re-auth from
  Personal Settings restored the connection — required clearing the
  stale `user_name` server-side first (it survived the token death,
  so the UI thought the account was still connected and showed
  "Disconnect from Google" instead of "Sign in to Google"). After
  reauth, the next cron + force-runs re-populated everything:
  primary 731, Holidays in Canada 348, School Dates 61, plus the
  other two shared calendars.
- **Drive API not enabled in the Google Cloud project**: the
  `consider_all_events` path silently calls
  `/drive/v3/about?fields=*` and `/people/v1/people/me/connections`
  on settings-page load. The lab's Google Cloud project doesn't have
  the Drive API enabled, so those endpoints 403 with
  `reason: accessNotConfigured`. Not a code bug — config gap in the
  cloud project. We're a calendar-only fork; Drive integration is
  out of scope but the settings page still wires those probes. The
  forecasted clean-up: gate the probes on
  `state.user_scopes.can_access_drive` (already tracked).
- **PR #5 (VTIMEZONE) PR-body edit blocked by gh CLI**: `gh pr edit
  --body` errors out with a "Projects (classic) is being deprecated"
  GraphQL message. Worked around by adding the lab-verification
  evidence as a follow-up comment on the PR instead. Not investigated
  further.

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

### Phase 1 punch list — status (2026-05-28)

All five items now have open PRs against `main`.

- ~~Emit `ATTENDEE` lines for `attendees[]`. (Test 7.)~~ — landed as PR #2.
- ~~Emit a `VTIMEZONE` block when DTSTART/DTEND use `TZID`. (Test 6 + audit.)~~ — PR #5, lab-verified 33/33.
- ~~Bump `info.xml` `max-version` to 33.~~ — PR #3, lab-verified disable→enable round-trip.
- ~~Stand up a minimal PHPUnit suite before more changes land.~~ — PR #4. Q8 below resolved as "yes".
- ~~Honor `nextSyncToken` so brand-new Google events don't miss a cron tick.~~ — PR #6, lab-verified clean / steady / 410-fallback.

Bonus: PR #7 closes the destructive-on-error audit-class finding that
surfaced while debugging the others.

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
8. **Testing posture.** ~~No PHPUnit suite exists. Setting one up now is cheap; setting it up after large changes is expensive. Worth investing a day before any non-trivial Phase 1 code lands?~~ **Resolved 2026-05-28 as "yes" — PR #4 adds the harness. Reflection-based, no NC bootstrap, 21 tests on landing. Extended to 26 (sync-token branch) / 29 (vtimezone branch). CI wiring still open.**

## Other anomalies observed during Phase 0 (not blocking)

- `composer.lock` in the upstream fork is **out of date** — `php-ds/php-ds` is in `composer.json` but not in the lock file. Required a `composer update --no-dev` to bootstrap `vendor/`. Lock change is not committed (treated as operational).
- The host clone's permissions stayed at `alex:alex` (775/664). Did **not** run the recipe's `sudo chown -R 33:33` because sudo was unavailable in the agent session and the bind mount makes it unnecessary (Apache reads via "other" perms). The recipe's host-side symlink under `~/gitprojects/nextcloud-lab/custom_apps/` was also skipped for the same reason. Both can be added manually for tidiness; neither affects function.
- Google's `events.list` had a measurable propagation lag (~3 minutes) between an event being created in the calendar UI and being visible to the API. Means brand-new events might miss a sync tick. Not fixable from our side without `nextSyncToken` (which we don't use yet) or a small "retry on miss" heuristic.
