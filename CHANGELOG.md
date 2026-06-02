# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [4.12.0]

### Added

- **Outbound contacts sync — Nextcloud → Google, first slice (Track 2, C2a):
  CREATE.** When contacts sync is on for an address book, a *new* contact you
  create in Nextcloud is now pushed up to Google. It is gated behind a new
  read-write `contacts` permission (you must **Disconnect** and **Sign in with
  Google** again to grant it — until then nothing is written to Google), and
  echo-suppressed so the contact isn't re-created or bounced back. Editing and
  deleting Nextcloud contacts on the Google side, plus the edit-conflict
  handling, land in the next slice; for now NC-side edits/deletes are detected
  and logged but not yet pushed. See `docs/CONTACTS_SYNC.md`.

### Hardened (pre-merge review)

- A Google contact that is created but whose local mapping then fails to save
  is now surfaced as an orphan and the change token is advanced past it, so it
  can never be silently re-created as a duplicate on a later run.
- A create response missing its `etag`, a thin/absent response `metadata`, and
  a transient failure to re-read a mapped card are all handled defensively
  (the contact is still mapped; no crash; no misclassification into a re-create).
- Permanent (4xx) create rejections now log the Google error body, and
  disabling contacts sync enforces the same address-book ownership check as
  enabling it.

## [4.11.0]

### Added

- **Contact de-duplication (Track 2, C1).** A **"Remove duplicates"** button
  next to each address book (in the **Contacts** section) collapses leftover
  duplicate contacts — most commonly cards that existed in an address book
  *before* you turned on contacts sync, which sync then re-created. It is
  deliberately conservative: it removes a stray card **only** when it is a
  high-confidence duplicate of exactly one Google-synced contact (same name
  **and** a shared email address), never touches a synced card, and leaves
  ambiguous or low-confidence matches alone. Removed cards go to the Contacts
  trash (recoverable). New duplicates are already prevented by the sync's
  identity map.

## [4.10.0]

### Added

- **Continuous Google → Nextcloud contacts sync (Track 2, C0).** A new
  per-address-book **"Sync contacts"** toggle (in the **Contacts** section) keeps
  a Nextcloud address book continuously in sync with your Google contacts via a
  background job. Unlike the one-time **Import** button, it pulls only the
  incremental changes each run (using a People-API sync token) and — for the
  first time — also applies **Google-side deletions** to your Nextcloud cards.
  Edits use last-writer-wins (ties → Nextcloud), and the identity map prevents
  duplicates (including across a sync-token expiry / full resync). Inbound only
  for now; pushing Nextcloud changes back to Google comes in a later phase.

## [4.9.0]

### Added

- **Contacts sync foundation (Track 2, C0).** A new `calbridge_contacts_map`
  identity table (Nextcloud card ↔ Google People `resourceName`, with the
  etag/updateTime baselines needed for echo-suppression and conflict
  resolution) and a defensive `ContactMapService`. The Google Contacts import
  now records a mapping row for every card it writes, laying the groundwork for
  continuous incremental sync, de-duplication, and two-way contacts sync. No
  user-visible change yet — see `docs/CONTACTS_SYNC.md` for the design.

## [4.8.0]

### Removed

- **The Google Drive import feature** (inherited from the upstream Google
  integration) has been removed: its settings section, background import job,
  service, routes, and the `drive.readonly` OAuth scope are all gone. This also
  eliminates the recurring *"Google Drive API has not been used … or it is
  disabled"* error that appeared on the settings page when the Drive API was not
  enabled in the Google Cloud project. The Google **Contacts** import is
  unaffected and remains available. Existing users keep an unused Drive grant in
  Google until they next reconnect; the consent screen no longer requests it.

## [4.7.0]

### Added

- **Trashing a linked calendar now pauses its sync instead of leaving it
  running.** When a Nextcloud calendar that is linked to Google is soft-deleted
  (the normal Calendar-app delete, which moves it to the trash), its background
  import job is unregistered and two-way sync is turned off — but the link is
  kept. Previously the job kept running for the whole ~30-day trash-retention
  window, importing into an invisible calendar.
- **Restoring a trashed linked calendar resumes its sync** automatically
  (re-registers the job + re-enables two-way). Because the per-event mapping
  survives trashing, nothing is re-pushed or duplicated on resume.

## [4.6.0]

### Added

- **"Sync all" button + a "(not synced)" marker** in the Google calendar list, to
  start background sync for every not-yet-synced calendar at once.

### Fixed

- A Nextcloud calendar that is **linked to Google and then deleted out-of-band**
  (Calendar app / occ) is now cleanly unlinked (its mapping + background job are
  removed; the Google calendar is kept) instead of leaving a dangling job.
- An event Google **permanently rejects** (a malformed body) no longer wedges
  outbound sync: it is now terminal (logged, left one-way) instead of pinning the
  change token and re-attempting every run — fixes the "first push never finishes"
  failure mode for a newly-linked calendar with one bad event.
- Error toasts now show the **server's actual message** (e.g. "reconnect your
  Google account") instead of a generic "Request failed with status code N".

### Added

- **Sync a Nextcloud calendar to Google** (the other direction). A new "Your
  Nextcloud calendars" section in Personal settings lets you create a matching
  Google calendar from one of your own Nextcloud calendars and two-way-sync it.
  Its existing events are pushed to Google in the background (cap-and-drain, so a
  large calendar drains over several runs without hitting Google's rate limits).
  Includes **Disconnect** (unlink, keep both calendars) and a confirmed **Delete
  both calendars** (Google permanently, Nextcloud to the calendar trash).
  Requires reconnecting your Google account once to grant the minimal
  `calendar.app.created` scope. Event details sync; attendee lists do not.

## [4.4.0]

### Added

- Groundwork for **calendar-level sync** (create a Google calendar from a
  Nextcloud one — see `docs/CALENDAR_LEVEL_SYNC.md`): a `calbridge_calendar_map`
  table + a map-first calendar-resolution seam in the importer (backward-compatible
  — existing Google-originated calendars are unaffected), and the
  `calendar.app.created` OAuth scope is now requested (granted on reconnect; least
  privilege — the app can only manage calendars it creates). **No user-facing
  change yet** — the NC → Google create flow lands in a later release.

## [4.3.0]

### Added

- **Operational hardening — periodic "trust-but-verify" map reconcile** (`MapVerifyService`). At most once every ~6 hours per two-way calendar, the app re-derives the Nextcloud↔Google event map from *both* live sides and repairs only provably loss-proof drift (drops a row whose Nextcloud object and Google event are both gone; rebinds a dangling `google_id` to the Google event still carrying our `ncOrigin` tag). Everything else is logged to `nextcloud.log` and the row's `last_error`. It is read + bookkeeping-only — it never creates, edits, or deletes a Google event or a Nextcloud object.
- **End-user [User Manual](docs/USER_MANUAL.md)** and **administrator [Install & Admin Guide](docs/INSTALL.md)**.

### Changed

- Calendar import job now logs failures to `nextcloud.log` (visible under system cron), not only to `occ` console output.
- README updated to reflect two-way sync and link the new guides; `composer.json` now declares `AGPL-3.0-or-later`.

## [4.2.0]

### Added

- **Two-way (bidirectional) sync.** In addition to the existing Google → Nextcloud import, the app can push Nextcloud changes back to Google. It is **opt-in per calendar and off by default**, requires write access to the calendar and the read-write OAuth scope, and covers new events, edits, deletions, and recurring series (including individually moved/renamed/cancelled occurrences). Conflicts resolve last-writer-wins, ties to Nextcloud.
- Renamed the app to **Calendar Bridge** (`outside_provider_calendar_bridge`).

## [4.1.0] - 2025-10-25

### Added

- Support timezones in calendar events #276 @MarcelRobitaille
- Support recurrence exceptions #281 @MarcelRobitaille
- Add configurable "shared with me" output directory #285 @Bungeefan

### Changed

- Removing url encoding from calendar names #280 @lukasdotcom
- Add logging to when job execution is delayed for drive import #284 @Bungeefan

### Fixed

- Replaced mdi download icon with Material Symbol variant #273 @AndyScherzinger

## [4.0.1] - 2025-08-26

### Fixed

- Fixed "Sync calendar" checkbox having no effect (#36)

## [4.0.0] - 2025-08-23

### Breaking changes

- Drop support for Nextcloud 29
- Drop support for Nextcloud 28
- Drop support for Google Photos #246

### Added

- Allow disabling of imports of birthday events in calendar #258 @lukasdotcom
- Use outlined icons in the UI #254 @lukasdotcom
- Support importing other contacts #245
- Support for Google Drawings #244
- Vue 3 and Vite #242

### Fixed

- Added warning if using an IP as a redirect URI (#32)
- Cleaned up setup page
- Fix large office file exports #243
- Improve sanitation of folder and file names #209

## [3.2.0] - 2025-06-11

### New

- Support for up to Nextcloud 32

## Fixed

* fix(AdminSettings): mention that google site verification may be necessary
* Fix(l10n): Update translations from Transifex
* fix(GooglePhotosAPIService): Allow multiple photos with the same name
* fix: Safer settings

## [3.0.0] - 2024-09-26

### Breaking changes

- Drop support for Nextcloud 27
- Drop support for Nextcloud 26

### New

- Add support for Nextcloud 30
- Updated UI components library

## [2.2.0] - 2024-06-29

### Changed

 - Further improve error messages in browser popup

### New ported from upstream 2.2.0

- Adding prefix, suffix and middle name to contacts

### Fixes ported from upstream 2.2.0
 - fix(GoogleDriveAPIService): Make sure target path is not a shared folder
 - fix(GoogleCalendarAPIService): Sanitize calendar name
 - fix(GoogleDriveAPIService): Don't break if a file causes hiccups
 - Fix(l10n): Update translations from Transifex

## [2.1.1] - 2024-04-21

### Changed

 - Improve error messages in browser popup
 - Possible appName fix
 - Documentation and related changes


## [2.1.0-1] - 2024-02-28

### Changed

 - Add support for Nextcloud 28
 - Fix bugs related to synchronization features
 - Add ability to unregister background sync and show the current
   sync status in the UI
 - Add a button to unregister all jobs from the admin dashboard

### Fixed

 - Fix(l10n): Update translations from Transifex

## [2.0.2] - 2023-05-31

### Fixed
- fix build

## [2.0.1] - 2023-05-31

### Fixed
- fix(PersonalSettings): Correctly check result of json_decode

## [2.0.0] - 2023-05-10

### Breaking changes

- Drop support for Nextcloud 22
- Drop support for Nextcloud 23
- Drop support for Nextcloud 24
- Drop support for Netxcloud 25
- Drop support for PHP <8.0

### Fixed
 - fix plural translation in notifier
 - Fix(l10n): 🔠 Update translations from Transifex

## 1.0.9 – 2023-01-08
### Added
- import contact groups
  [#124](https://github.com/nextcloud/integration_google/issues/124) @zgypa
- import contact notes
- import contact websites
- set last modified date of imported directories

### Changed
- update npm pkgs, adjust to @nextcloud/vue 7.3.0
- improve and speedup calendar import, update existing events if needed
- speedup drive size calculation
- improve contact import, update existing ones if needed

### Fixed
- import photos/albums with slashes in their name
  [#122](https://github.com/nextcloud/integration_google/pull/122) @Gp2mv3
- recover after an import job is brutally stopped with a 1h timeout before everything can start again
  [#35](https://github.com/nextcloud/integration_google/issues/35)
  [#115](https://github.com/nextcloud/integration_google/issues/115)
  [#116](https://github.com/nextcloud/integration_google/issues/116)
- preserve exif data when downloading photos (all except geolocation which is stripped by google)
  [#119](https://github.com/nextcloud/integration_google/issues/119) @Sid127
- only add file name suffix (google file id) for duplicated names (yes, google allows multiple files with the same name in a directory)
  [#127](https://github.com/nextcloud/integration_google/issues/127) @Mezgrman
- don't skip contacts with no names

## 1.0.8 – 2022-08-24
### Added
- admin option to use a popup during the OAuth flow rather than a redirect

### Changed
- adjust to NC 25 (style, icons, no more svg api etc...)
- implement proper token expiration check
- use node 16, adjust to new eslint config
- improve perso/admin settings style, use NC components etc...

### Fixed
- drive pagination to count files
  [#94](https://github.com/nextcloud/integration_google/pull/94) @hjylewis
- remove new lines from file names
  [#94](https://github.com/nextcloud/integration_google/pull/94) @hjylewis
- contact photo import, correctly get photo file type so photo is not skipped

## 1.0.6 – 2021-11-21
### Added
- list download failures in `failed-downloads.md` file
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508

### Changed
- improve permission management, don't fail on missing permission
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508
- remove private information in logs
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508
- improve photo count
  [#84](https://github.com/nextcloud/integration_google/pull/84) @akhil1508
- improve release action and clarify package.json

### Fixed
- urlencode calendar ids and fileItem ids
  [#89](https://github.com/nextcloud/integration_google/pull/89) @akhil1508
- multiple files having the same name
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508
- google signin button
  [#78](https://github.com/nextcloud/integration_google/issues/78) @Niveshkrishna
- change connection button to comply with Google's branding guidelines
  [#70](https://github.com/nextcloud/integration_google/issues/70) @tabp0le
- handle unknown job Exceptions to avoid blocking import process
  [#60](https://github.com/nextcloud/integration_google/issues/60) @StaceZ @ancow
- drive/photo import with SSE enabled
  [#71](https://github.com/nextcloud/integration_google/issues/71) @Niveshkrishna @arnaudvp

## 1.0.3 – 2021-06-28
### Changed
- bump js libs
- get rid of all deprecated stuff
- bump min NC version to 22
- cleanup backend code

## 1.0.2 – 2021-04-20
### Changed
- bump js libs

### Fixed
- concurrent import jobs
[#51](https://github.com/nextcloud/integration_google/issues/51) @seanodea

## 1.0.0 – 2021-03-19
### Changed
- bump js libs

## 0.1.10 – 2021-02-16
### Changed
- app certificate
- optimize drive import

## 0.1.9 – 2021-02-12
### Changed
- bump js libs
- bump max NC version

### Fixed
- import nc dialog style

## 0.1.7 – 2021-01-27
### Fixed
- incorrect exclusions in makefile leading to missing Php libs in release

## 0.1.6 – 2021-01-27
### Changed
- import calendar event colors
[#49](https://github.com/nextcloud/integration_google/issues/49) @burnhard93
- bump js libs

## 0.1.5 – 2021-01-20
### Changed
- use contact incomplete birthday
[#45](https://github.com/nextcloud/integration_google/issues/45) @PhysicsFabi
- preserve files 'last modified date' and photos 'date taken'
[#42](https://github.com/nextcloud/integration_google/issues/42) @dommtardif @jrial
[#46](https://github.com/nextcloud/integration_google/issues/46) @dommtardif @jrial

### Fixed
- try to deal with locked files issue
[#43](https://github.com/nextcloud/integration_google/issues/43) @kusma @sarunaskas

## 0.1.4 – 2021-01-04
### Added
- configurable output dir for drive and photos import

### Changed
- bump js libs

### Fixed
- photo in imported contacts
[#44](https://github.com/nextcloud/integration_google/issues/44) @hegocre

## 0.1.2 – 2020-12-16
### Fixed
- issue with unlimited quota, now properly detected
[#38](https://github.com/nextcloud/integration_google/issues/38) @dommtardif
- address book request was restricted to admins

## 0.1.0 – 2020-12-15
### Added
- option to choose google docs import format (OpenXML or OpenDocument)

### Changed
- add hint about photo api not providing location data
- bump js libs

## 0.0.25 – 2020-11-24
### Changed
- add log when drive file can't be directly downloaded and it's not a 'document'

## 0.0.24 – 2020-11-18
### Fixed
- be resistant to missing photo file name
- don't crash when drive target file is impossible to create in NC

## 0.0.23 – 2020-11-18
### Fixed
- get full resolution photos and hq videos
[#32](https://github.com/nextcloud/integration_google/issues/32) @Ruzken

## 0.0.22 – 2020-11-16
### Fixed
- be more defensive when getting contacts
[#31](https://github.com/nextcloud/integration_google/issues/31) @mike-lloyd03

## 0.0.21 – 2020-11-10
### Fixed
- be more defensive when checking if a contact already exists
[#27](https://github.com/nextcloud/integration_google/issues/27) @Bergum

## 0.0.20 – 2020-11-09
### Fixed
- don't close resource that is already closed
- fallback title for private calendar events
- don't display photo percent progress as we don't know the exact photo number

## 0.0.19 – 2020-11-09
### Fixed
- be more defensive when getting shared files size
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal
- safer resource closing on download error
- typo

## 0.0.18 – 2020-11-07
### Fixed
- make less requests when getting photo number
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal

## 0.0.17 – 2020-11-07
### Changed
- try to make contact photo import safer
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal
- be more defensive when getting photo number
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal

### Fixed
- truncate calendar string values because db field is varchar(255)
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal
- mistake leading to crash when "updated" calendar event prop was found
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal

## 0.0.16 – 2020-11-07
### Added
- optionally import shared photo albums and shared drive files/folders

### Changed
- import in existing calendar if there is one
- improve personal settings style, don't expose token
- directly download to target file (with resource) instead of using temporary files

### Fixed
- log instead of crash on event import error

## 0.0.15 – 2020-11-05
### Changed
- more logs, try not to crash on download problems

### Fixed
- delete photo temp file after having copied it

## 0.0.14 – 2020-11-05
### Fixed
- delete tmp file after having copied it
[#24](https://github.com/nextcloud/integration_google/issues/24) @oncletom

## 0.0.13 – 2020-11-03
### Fixed
- set client timeout to 0 to allow big file download
[#24](https://github.com/nextcloud/integration_google/issues/24) @oncletom

## 0.0.12 – 2020-11-01
### Fixed
- export google docs to files instead of just ignoring them
[#21](https://github.com/nextcloud/integration_google/issues/21) @oncletom
- avoid loading entire downloaded files in memory, use temp file and chunk copy
[#22](https://github.com/nextcloud/integration_google/issues/22) @oncletom

## 0.0.11 – 2020-10-31
### Fixed
- get rid of slashes in file/folder names
[#19](https://github.com/nextcloud/integration_google/issues/19) @oncletom

## 0.0.10 – 2020-10-29
### Changed
- bump all js libs

### Fixed
- timestamp of calendar events
[#17](https://github.com/nextcloud/integration_google/issues/17) @duckunix

## 0.0.9 – 2020-10-21
### Fixed
- get free space independently from photo service

## 0.0.8 – 2020-10-21
### Changed
- import contact photos

### Fixed
- mismatch redirect url, use the one generated by the browser

## 0.0.7 – 2020-10-16
### Fixed
- calendar import crashing for events with not dates
[#11](https://github.com/nextcloud/integration_google/issues/11) @cairobraga

## 0.0.6 – 2020-10-16
### Changed
- improve webpack config
- real time photo/drive import progress
[#14](https://github.com/nextcloud/integration_google/issues/14) @sebvil

### Fixed
- crash when importing calendar with new lines in event description
[#11](https://github.com/nextcloud/integration_google/issues/11) @slayerbrk @cairobraga @JimmyKater @aelethian

## 0.0.5 – 2020-10-15
### Changed
- use webpack 5
- split service in 5 ones
- improve request error mamangement
- refactor some loops

### Fixed
- stylelint error

## 0.0.4 – 2020-10-12
### Added
- photos import
- drive import

### Changed
- cleaner code

### Fixed
- avoid empty migration settings when OAuth config is not set

## 0.0.3 – 2020-10-03
### Fixed
- avoid crash when refresh_token is not given and be more explicit on this error
- always ask for user consent when authentication to make sure we get the refresh_token
[#4](https://github.com/nextcloud/integration_google/issues/4) @Ludovicis
[#5](https://github.com/nextcloud/integration_google/issues/5) @Ludovicis

## 0.0.2 – 2020-10-02
### Added
- lots of translations

### Fixed
- suggested redirect URI
[#3](https://github.com/nextcloud/integration_google/issues/3) @Ludovicis

## 0.0.1 – 2020-10-01
### Added
* the app
