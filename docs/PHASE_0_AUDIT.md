# Phase 0 audit — `nextcloud_google_synchronization` (fork)

Read of `appinfo/`, `lib/`, `templates/`, `README.md`, `CHANGELOG.md`, `composer.json`, `package.json`, `vite.config.js`, and the upstream GitHub issue tracker on Marcel's fork. Source tree as of fork point: `MarcelRobitaille/nextcloud_google_synchronization@c4d548f` (Merge PR #42, "Prepare 4.1.0"). No source modifications.

## App identity

| Field          | Value                                                                 |
| -------------- | --------------------------------------------------------------------- |
| App ID         | `google_synchronization`                                              |
| Display name   | Google Synchronization                                                |
| PHP namespace  | `OCA\Google`                                                          |
| Version        | 4.1.0                                                                 |
| Author         | Marcel Robitaille (forked from Julien Veyssier's `integration_google`) |
| License        | AGPL-3.0 (`<licence>agpl</licence>` in info.xml; COPYING is GPLv3 — see "License" below) |

Namespace `OCA\Google` is **identical to upstream `nextcloud/integration_google`**. The two apps cannot coexist (same DB config keys, same routes, same notifier id). README states this explicitly.

## Nextcloud version range

`info.xml` declares `<nextcloud min-version="32" max-version="32"/>` — narrowly pinned to NC 32 only. README claims "Nextcloud >= 28" but README is stale; info.xml is authoritative.

**Compatibility hit on this lab:** lab runs Nextcloud **33.0.3**. Enabling the app will require `occ app:enable --force google_synchronization`. We should expect to bump max-version (or accept the force-enable) in Phase 1; nothing in the actual code is obviously NC-33-incompatible, but we have not verified.

## OAuth token storage

Encrypted via `OCP\Security\ICrypto`. Lifecycle:

- `lib/Service/SecretService.php` wraps all token I/O — `setEncryptedUserValue` / `getEncryptedUserValue` call `$crypto->encrypt`/`decrypt` around standard `IConfig` user-pref storage. Empty string short-circuits the encrypt (stored as empty).
- Per-user secrets in `oc_preferences` (`appid='google_synchronization'`): `token`, `refresh_token`. Plus plaintext metadata: `token_expires_at`, `user_id`, `user_name`, `user_scopes`, `oauth_state`, `redirect_uri`, `consider_*` flags, `drive_*` dirs.
- App-level secrets in `oc_appconfig`: `client_id`, `client_secret` — also encrypted via the same `ICrypto` path (`setAdminConfig` in `ConfigController` calls `$this->crypto->encrypt` directly; `SecretService::setEncryptedAppValue` is the standard path).
- Token refresh: `GoogleAPIService::checkTokenExpiration()` refreshes when `now > expires_at - 120s`. Run before every API call (`request` / `simpleRequest` / `simpleDownload`).
- Migration `Version03001001Date20241111105515` (Nov 2024) is a one-shot that encrypts previously-plaintext stored `client_id`, `client_secret`, `token`, `refresh_token`. Mostly idempotent: it re-encrypts any non-empty `configvalue` it finds — running it twice would double-encrypt and break decryption. Migrations only run once per `oc_migrations`, so this is normally fine, but anyone manually rerunning it will brick tokens.

## BackgroundJob mechanism

Two jobs in `lib/BackgroundJob/`:

- **`ImportCalendarJob` (`TimedJob`, interval = 1 second).** One job entry per `(user_id, cal_id)` registered via "Sync calendar" toggle in the UI. Constructor calls `parent::setInterval(1)` — effectively "every time cron fires." Argument is `array{user_id, cal_id, cal_name, color}`. Job calls `GoogleCalendarAPIService::safeImportCalendar()`. **No user session is set** — the service operates entirely off the `$userId` string and grabs that user's tokens via `IConfig`/`SecretService`. Per-user iteration is implicit: each registered calendar becomes its own job row.
- **`ImportDriveJob` (`QueuedJob`).** One-shot per drive import, requeues itself.

Worth flagging: `ImportCalendarJob::run()` prints to stdout via `echo()`. Under Nextcloud cron mode this just goes nowhere; under AJAX mode there's no visible effect; under webcron / `occ background-job:execute` it lands on the request output. There's a separate (debug-level) logger in the service. Production logs will mostly be silent unless `loglevel=0`.

There is **no `UserScopeService::setUserScope()` call** in the calendar path despite the class existing. That's relevant for any extension we add that needs filesystem / event emission scoping — `UserScopeService` is used only in the Drive path (not directly visible in this read but referenced by upstream patterns; we did not verify by inspection here).

A `nextcloud_google_synchronization_calendar_import_$calId.lock` file in `sys_get_temp_dir()` guards against concurrent runs on the same calendar. It's per-cal_id (NOT per-user), and in our Docker setup `/tmp` is per-container so it's effectively local to the `app` container. A stale lock from a crashed worker will block all future runs until manually removed — there's no PID-or-age check.

## Database schema

This app **adds no tables.** Everything lives in core Nextcloud tables:

- `oc_preferences` — per-user app config (tokens, expires_at, scopes, dir prefs, "in progress" flags like `importing_drive`).
- `oc_appconfig` — admin OAuth client credentials, `use_popup` flag.
- `oc_jobs` — background job rows (one per `ImportCalendarJob` registration; argument is JSON `{user_id, cal_id, cal_name, color}`).
- `oc_calendars` / `oc_calendarobjects` — CalDAV target, accessed via `OCA\DAV\CalDAV\CalDavBackend` directly.
- `oc_addressbooks` / `oc_cards` — contacts target.

The single `Migration/Version03001001Date20241111105515.php` only does the in-place token re-encryption described above. No CREATE TABLE.

Calendars are created on first import as **new** NC calendars named `"<original name> (Google Calendar import)"`. Existing local calendars are never written to (see "Sync direction" / "Conflict handling" below).

## Sync direction

**One-way: Google → Nextcloud only.** Confirmed by README ("This is a one-way synchronization") and code: every CalDAV operation in `GoogleCalendarAPIService` is `createCalendar` / `createCalendarObject` / `updateCalendarObject` / `deleteCalendarObject`. No `request()` call writes back to Google (`calendar/v3/calendars/.../events` is GET-only here). No webhook or push channel registration.

## Recurring event handling

- **RRULE**: yes. Google returns the recurrence array as a list of RFC 5545 lines (`RRULE:...`, `EXDATE:...`, `RDATE:...`) — the code in `GoogleCalendarAPIService::generateEventData()` lines 177–181 emits each verbatim. So whatever Google sends is whatever Nextcloud gets.
- **EXDATE**: same — passes through if Google emits it. In practice, Google does NOT emit EXDATE for cancelled instances; it emits a separate cancelled event with `recurringEventId` (see below).
- **RECURRENCE-ID overrides**: yes. Exception events (those with `recurringEventId` set) are collected separately, then **inlined as additional VEVENT blocks inside the parent's VCALENDAR resource** (lines 207–211). UID matches the master, `RECURRENCE-ID;<originalStartTime>` is set. This is CalDAV-correct.
- **Cancelled instances**: when an instance is deleted in Google, the API returns it as `status: cancelled` with `recurringEventId`. The code falls through the same exception-inlining path and emits `STATUS:CANCELLED` on the override VEVENT — but does NOT also emit an `EXDATE` on the master. Most CalDAV clients honor STATUS:CANCELLED on an override; Sabre/Nextcloud Calendar UI usually does. This is the most fragile recurrence path and worth a real-world test (see PHASE_0_TEST_DATA.md row "Test recurring with cancellation").
- **TZID / VTIMEZONE**: `mapTime()` emits `TZID=Foo` references on DTSTART/DTEND but **never emits the VTIMEZONE block** itself. Sabre on the receiving side does the right thing for IANA tz names, but downstream clients (CalDAV consumers, ICS exports) may render times as floating. The 4.1.0 CHANGELOG entry "Support timezones in calendar events #276" landed this in October 2025 — earlier versions had no TZID at all.

## Conflict handling

Simple last-writer-wins by timestamp, on a **per-import basis only** (not bidirectional reconciliation since there's no upstream write):

- For an existing local CalDAV object with matching URI, compare Google's `updated` timestamp vs. NC's `LAST-MODIFIED`. Skip if remote is not newer. (`GoogleCalendarAPIService::importCalendar`, lines 405–418.)
- For new objects, create. For unseen-in-remote objects, delete locally. (Lines 458–460 — `deleteCalendarObject` is called on any local URI not seen in the Google response.)

**Consequence:** if a user manually edits an event in the Nextcloud Calendar UI, the next sync will **overwrite their change** any time Google's `updated` is newer than the local `LAST-MODIFIED`. There is no "leave local edits alone" flag, no merge, no conflict marker. For the "shared team calendar I'm consuming" use case Marcel describes in the README, this is fine. For our broader use case it is a real gap.

Also: the delete-on-not-seen logic uses `caldavBackend::deleteCalendarObject(..., true)` — the trailing `true` is `forceDeletePermanently`, so deletions skip the trash. Recoverability is limited to Google undeleting on its side.

## Rate-limit and retry handling

**Effectively none.** `GoogleAPIService::request()` catches `ServerException`/`ClientException`/`ConnectException` and converts them to `['error' => ...]` return values. No retry, no exponential backoff, no 429 handling, no `Retry-After` honoring. Pagination is just `nextPageToken`-driven with `maxResults=2500` per page. The lock file is the only concurrency guard. Token refresh happens at the 120-second-before-expiry boundary on every request.

If Google starts 429-throttling, the sync just silently produces an `error` in the return value and exits the run; the next cron tick (1 second later per `setInterval(1)`) will retry blindly. That's a likely source of hammering during outages.

## Tests

The `tests/` directory contains a single file: `tests/stub.phpstub` (psalm-related). **There is no PHPUnit suite, no integration tests, no fixtures, no CI test job in `.github/workflows/`.** Static analysis only (psalm + php-cs-fixer + ESLint + stylelint). This is a meaningful gap for our planned work — any refactor will be flying blind.

## License headers and AGPL compliance

- `info.xml` declares `<licence>agpl</licence>`.
- `package.json` declares `"license": "AGPL-3.0"`.
- `COPYING` file is the full **GPLv3** text. This is a known longstanding mismatch in the upstream `integration_google` family — `COPYING` was never updated when the license was declared AGPL. Most source-file headers ("This file is licensed under the Affero General Public License version 3 or later. See the COPYING file") reference COPYING, which is GPLv3. Newer files (e.g., the migration) use proper SPDX `AGPL-3.0-or-later` headers.
- Practical impact: we inherit a license-text inconsistency. We should ship a corrected `COPYING` (the AGPL-3.0 text) in our distribution if we publish, and the existing header references will then be accurate. Until then, treat the project as AGPL-3.0 per the explicit declarations in `info.xml` and `package.json`.
- Most PHP files carry "@author Julien Veyssier" and "@copyright Julien Veyssier 2020" — these must be preserved in any derivative; we should add our own copyright lines on files we modify in Phase 1.

## Build pipeline (relevant before staging)

The repo does **not** include built JS bundles. `templates/personalSettings.php` and `templates/adminSettings.php` register `google_synchronization-personalSettings` / `google_synchronization-adminSettings` via `OCP\Util::addScript`, which expect files like `js/google_synchronization-personalSettings.mjs` produced by `vite build`.

The repo also does **not** include `vendor/`. `lib/Service/GoogleCalendarAPIService.php` line 36 has a hard `require_once __DIR__ . '/../../vendor/autoload.php';`. If that file doesn't exist, the whole calendar service will fatal on autoload.

So Phase 0 staging needs both:
1. `composer install --no-dev` in the app dir (pulls in `ortic/color-converter`, `php-ds/php-ds`).
2. `npm ci && npm run build` (Node 22, npm 10) to produce `js/` bundles.

Without these the app will load (info.xml is parsed) but the settings pages will be blank and the background job will hard-fail.

## Notable open issues on Marcel's fork

(Pulled from `gh issue list` on `MarcelRobitaille/nextcloud_google_synchronization`, state=open, 11 issues total. Most relevant first.)

| # | Title | Why it matters to us |
| - | ----- | -------------------- |
| 47 | Allow to force specific timezone | Confirms the TZID/VTIMEZONE gap is a known sore spot. |
| 46 | Birthdays-sync toggle doesn't persist | Setting-persistence bug in personal settings; touches the same Vue layer. |
| 44 | "Failed to save Google admin options: status 500" when entering client id/secret | Admin OAuth setup is fragile — probably PasswordConfirmation flow related. We will hit this during Step 4. |
| 40 | Gracefully handle google logout | Failure mode for revoked refresh tokens. We will likely tighten this in Phase 1. |
| 27 | Set token expire period for 6 months | User confusion about Google's refresh-token semantics; not a code bug. |
| 17 | UTF-8 error | Possibly related to the truncate/escape behavior on SUMMARY/LOCATION/DESCRIPTION. |
| 8 | Can import Google calendar, but not sync | Old (2023), pre-4.0.1 sync-toggle fix; likely already resolved by #36 in 4.0.1. |
| 5 | Continuous synchronization of contacts | Future Phase work — contacts are import-only today. |
| 2 | Make synchronization frequency configurable | Currently hard-coded `setInterval(1)`; configurable interval is a Phase-1 candidate. |

No bug appears blocking for Phase 0 baseline validation.

## Surprising / unclear / worth flagging

1. **`require_once __DIR__ . '/../../vendor/autoload.php';` inside a class file.** Lib code shouldn't manually require autoload — Nextcloud auto-loads composer.json deps when the app boots. This works but is non-idiomatic; if we restructure we should drop the line.
2. **`echo()` for job logging.** Mixed with `LoggerInterface` debug calls in the same code path. Inconsistent and noisy under `occ background-job:execute`.
3. **`setInterval(1)` on a TimedJob.** Effectively "run every cron tick." With Nextcloud's default 5-minute cron, that's not catastrophic, but it bypasses the framework's rate limiting. Issue #2 wants this configurable.
4. **`date_default_timezone_set('UTC');` inside `importCalendar()`.** This is a process-global side effect from a service method — anything else running in the same PHP process after this call gets UTC as its default. Under cron-per-request this is fine; if a long-lived process shares it (unlikely in classic NC), it could surprise.
5. **`SUMMARY`/`LOCATION`/`DESCRIPTION` truncated to 250 chars with `substr()`.** Likely a hangover from when DB columns were `varchar(255)` (see CHANGELOG 0.0.17). CalDAV objects today are stored as full TEXT/CLOB blobs in `oc_calendarobjects.calendardata`, so the truncation is now data loss with no DB reason. Also: `\n` is escaped naïvely with `str_replace("\n", '\n', ...)` and there's no escaping of `;`, `,`, or `\` — RFC 5545 ICAL escaping is incomplete. Most events will be fine; one with a comma in the description is a latent bug.
6. **Attendees are silently dropped.** Google's `attendees` array is never read or written into the VEVENT (no `ATTENDEE;` lines emitted). Our Phase-0 "Test multi-attendee" event will land in NC without any attendee records. This is on the Phase-1 list.
7. **No EXDATE generation, even when Google returns `status:cancelled` for a single instance.** We rely on Sabre honoring `STATUS:CANCELLED` on a RECURRENCE-ID override. Worth confirming visually in the Calendar UI during Step 6.
8. **The `iCalUID` collision risk.** UID is constructed as `<ncCalId>-<iCalUID>` and the same UID is reused for the master and every exception (which is RFC-correct), but the CalDAV object URI is `$e['id']` (Google's event ID), which is unique per Google event. So master + exceptions are stored as multiple CalDAV objects, all sharing one UID. Inside one CalDAV calendar that's actually CalDAV-illegal (multiple resources, same UID, same calendar). Sabre may or may not enforce it. The 4.1.0 CHANGELOG note "Support recurrence exceptions #281" suggests Marcel resolved this with the inline-into-master-VCALENDAR approach instead — let me re-read... yes, exceptions are inlined into the master's VCALENDAR (only the master is written as a CalDAV resource, exceptions are NOT separately created — see lines 383–389 which split into `$events`/`$exceptions`, then only `$events` is iterated for object creation, lines 394–454). So this is fine. The CalDAV URI uses `$e['id']` (Google event id) and there's only one CalDAV object per recurring series. Confirmed safe.
9. **Personal-settings page always issues a `userinfo` request just to refresh the token.** `Personal::getForm()` at line 70 hits `oauth2/v1/userinfo` on every settings page load. Cheap, but unnecessary chatter against Google's API for a connected user.
10. **README claims NC >= 28; `info.xml` says min=max=32.** Documentation/metadata drift; metadata wins. We will need to widen `max-version` to use this on NC 33.
11. **The `Set` from `php-ds` is used to track "URIs not yet seen" for diff-delete.** This is fine. But on a calendar with thousands of events, every sync pull-iterates the full Google event list — there is no incremental/`syncToken` use of Google's API. So sync cost grows linearly with calendar size, every cron tick. Worth verifying we want this before we deploy to a real user with a big shared calendar.

## Quick summary for Phase 1 scoping

| Area                            | Current state                       | Phase-1 candidate? |
| ------------------------------- | ----------------------------------- | ------------------ |
| One-way G→NC sync               | Works, last-writer-wins             | Bidirectional?     |
| Recurrence + exceptions          | RRULE pass-through, inlined overrides | Verify cancellation path; possibly write EXDATE |
| Cancelled instances              | STATUS:CANCELLED, no EXDATE         | Probably leave as is until a real failure observed |
| Attendees                       | Dropped                             | Yes — write ATTENDEE lines |
| ICAL escaping                   | Naïve; truncates at 250 chars       | Yes — RFC 5545 escaping + drop truncation |
| Timezones                       | TZID only, no VTIMEZONE             | Yes — emit VTIMEZONE |
| Local-edit preservation         | None                                | Probably — at minimum a "skip if local LAST-MODIFIED is newer" flag |
| Rate limiting / retry           | None                                | Yes — 429 backoff with Retry-After |
| Sync token / incremental fetch  | None — full pull every run          | Yes — use Google's `syncToken` |
| Background-job interval         | Hard-coded 1s (every cron tick)     | Yes — make configurable (issue #2) |
| Tests                            | None                                | Yes — at least integration tests against a recorded Google API fixture |
| NC version range                | min=max=32                          | Bump max to 33 |
