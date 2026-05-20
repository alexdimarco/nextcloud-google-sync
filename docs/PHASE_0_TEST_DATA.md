# Phase 0 test data

Seven events to seed in the sacrificial Google account's **primary** calendar.
Reference dates use today as 2026-05-19 (lab clock) — adjust as needed.

After seeding, run the sync (Step 6 of the Phase 0 recipe) and fill in the
**Result** column.

## Events to create

| #  | Title                         | Date / time                                                                        | Other settings                                                       |
| -- | ----------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| 1  | `Test simple timed`           | 2026-05-20, 14:00–15:00 local (America/Toronto, EDT)                              | Default reminder. Single occurrence.                                  |
| 2  | `Test all day`                | 2026-05-20, all-day                                                                | Single occurrence.                                                    |
| 3  | `Test recurring weekly`       | Wednesdays, 10:00–11:00 local. First instance 2026-05-20. End after 4 occurrences. | None.                                                                 |
| 4  | `Test recurring with exception` | Mondays, 09:00–10:00. First instance 2026-05-25. End after 4 occurrences. Then move the **2nd** instance (2026-06-01) to start at 11:00 (1h later). | Tests RECURRENCE-ID override.                                       |
| 5  | `Test recurring with cancellation` | Fridays, 15:00–16:00. First instance 2026-05-22. End after 4 occurrences. Then delete only the **3rd** instance (2026-06-05). | Tests STATUS:CANCELLED override.                                      |
| 6  | `Test DST boundary`           | 2027-03-13 23:30 – 2027-03-14 01:00 (America/Toronto, spans US/Canada DST start). | Single occurrence. Wall-clock duration 1h30; UTC duration is 30 min. |
| 7  | `Test multi-attendee`         | 2026-05-21, 16:00–16:30 local                                                      | Add two attendees: `fake1@example.com`, `fake2@example.com`.          |

Notes:
- For event 7, Google will warn you the attendees aren't in your contacts and
  may fail to send invites; you can just **Send anyway** since they're fake
  addresses. The point is to record the `attendees` field via the API, not
  actually invite anyone.
- For event 6, intentionally chose 2027 so DST is real (March 13 2027 is the
  second Sunday — Spring Forward at 02:00). Wall clock 23:30→01:00 looks like
  1h30 but with the clock jump at 02:00 the elapsed real time is 30 min. The
  app's `mapTime()` should emit `TZID=America/Toronto` references — the
  question is whether NC's Calendar UI then renders the right wall-clock
  duration.

## Seeding checklist

- [ ] Created event 1
- [ ] Created event 2
- [ ] Created event 3
- [ ] Created event 4 (and moved the 2nd instance)
- [ ] Created event 5 (and deleted the 3rd instance)
- [ ] Created event 6
- [ ] Created event 7 (with both fake attendees)

## Sync trigger used

User toggled **"Sync calendar"** in Personal Settings on the primary calendar
(`dimarcotech@gmail.com`) plus four others (`Family`, `Smartweek Fundraising
Event`, `Holidays in Canada`, `School Dates`). This registered five
`ImportCalendarJob` rows in `oc_jobs`. The cron container picked them up
automatically.

Sync runs observed:
- **Initial cron-driven run**: `2026-05-20T03:15:00–03:15:03 UTC`. Imported
  721 events into the primary calendar including 5 of 7 test events.
- **Forced re-run** (`occ background-job:execute 85759893563146241
  --force-execute`) at `2026-05-20T03:19:57 UTC`: imported 2 more events
  (`Test DST boundary` and `Test multi-attendee`). These had been created in
  Google Calendar ~30 seconds before the first cron run (timestamps
  `03:15:40` and `03:17:11`) and apparently weren't visible to Google's
  `events.list` API at the moment the first sync read the calendar. Total
  primary-calendar event count after re-run: **723**.

## Results

After the sync runs, check the **Calendar** app at
http://localhost:8080/apps/calendar and verify each event. Note specifics for
anything that didn't import cleanly.

Results read directly out of `oc_calendarobjects.calendardata` for the 7
seeded events. Calendar UI verification is still pending — these notes are
from the stored ICS, which is what the calendar UI renders from.

| #  | Title                              | Result    | Notes                                                                                                                                                                                                                                                                                                          |
| -- | ---------------------------------- | --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1  | Test simple timed                  | OK        | DTSTART/DTEND 14:00–15:00 on 2026-05-20 with `TZID=America/New_York`. (User's Google account default tz; Toronto and NY both EDT, same wall-clock.) VALARM at -PT15M as expected.                                                                                                                              |
| 2  | Test all day                       | OK        | `DTSTART;VALUE=DATE:20260520`, `DTEND;VALUE=DATE:20260521`. Correct all-day shape. VALARM at -PT480M is Google's default all-day reminder.                                                                                                                                                                     |
| 3  | Test recurring weekly              | Partial — seeding error, not app | Stored RRULE is `FREQ=DAILY;COUNT=4`, not `FREQ=WEEKLY;BYDAY=WE;COUNT=4`. The user picked "Daily" in Google's recurrence UI instead of weekly. App correctly relayed what Google sent. So this run produced 4 daily instances starting Wed 2026-05-20, not 4 weekly Wednesdays. Recurrence relay itself is verified working. |
| 4  | Test recurring with exception      | OK        | Same DAILY seeding (user mistake again) — 4 daily instances starting Tue 2026-05-26. The override embedded in the same VCALENDAR resource: `RECURRENCE-ID;TZID=America/New_York:20260527T090000` → new DTSTART `20260527T110000`. CalDAV-correct. The recurrence-exception path **works**.                  |
| 5  | Test recurring with cancellation   | **Fail — app bug**          | The cancelled override is **not** emitted in the VCALENDAR. Only the master VEVENT is stored, with no `STATUS:CANCELLED` override and no `EXDATE`. Hypothesis from code read: Google's API returns the cancelled instance with `status: cancelled` but no `start`/`end`. `GoogleCalendarAPIService::generateEventData()` returns `''` when `start` or `end` is missing (line 184–186), which silently drops the cancellation. The deleted instance will still appear in NC. Phase-1 fix. |
| 6  | Test DST boundary                  | Partial — bad test design, plus the VTIMEZONE gap | User created the event 23:00–01:00 (matching the recipe), but **that range doesn't actually cross DST.** US/Canada DST starts at 02:00 local on 2027-03-14, and this event ends at 01:00 — still in EST. A real DST-spanning test would be 01:30→03:30 (which Google may or may not let you create). Beyond that: as predicted in the audit, the imported ICS references `TZID=America/New_York` but does **not** include the VTIMEZONE block. Sabre's internal handling fills in the gap server-side, but external CalDAV consumers / ICS exports will see times as floating. |
| 7  | Test multi-attendee                | Fail (compound: seeding + app bug) | User created the event as **all-day** for 2026-05-21 instead of 16:00–16:30. Setting aside the timing mistake, **no `ATTENDEE` lines were written to the ICS**, even though the user added two attendees in Google Calendar. This confirms the audit finding: the code never reads or writes `attendees`. Confirmed app gap, queued for Phase 1.                                                                                                                                  |

## Observations / surprises

1. **Google API propagation lag matters.** The first sync didn't see events
   `Test DST boundary` / `Test multi-attendee` even though they were created
   in Google ~30 seconds before the sync ran. A forced re-run 4 minutes later
   picked them up. The app has no `nextSyncToken` use and no retry — in a
   real deployment with default 5-min cron, brand-new events from the last
   few seconds may not appear until the next tick. Not a bug per se, but
   worth knowing.
2. **Calendar URI encoding is ugly.** The NC calendar created for the primary
   import has `uri = dimarcotech%40gmail.com+%28Google+Calendar+import%29`
   (URL-encoded `@`, spaces, and parens). DAV clients will see this as the
   raw URI. The 4.1.0 CHANGELOG note "Removing url encoding from calendar
   names #280" was supposed to fix this, but at least the URI piece is still
   doubly-encoded.
3. **No log noise.** `occ log:tail` was clean after the sync. The
   service-level debug logs only appear at `loglevel=0`.
4. **All 5 registered calendars synced successfully.** Beyond the test
   account's primary, the four shared calendars (`Family`, `Smartweek...`,
   `Holidays in Canada`, `School Dates`) all created their NC mirror
   calendars and populated events. Good signal that the multi-calendar /
   shared-calendar code path works.
5. **Lock files behave well.** No stale `nextcloud_google_synchronization_*`
   files left in `/tmp` after the runs. Locks are correctly released in the
   `finally` block.

## Items added to Phase-1 punch list (from this run)

- Emit `STATUS:CANCELLED` override (or `EXDATE`) for cancelled recurring instances, even when Google returns no DTSTART. (Test 5.)
- Emit `ATTENDEE;CN=...;ROLE=...:mailto:...` lines for `attendees[]`. (Test 7.)
- Emit a VTIMEZONE block when DTSTART/DTEND use TZID. (Test 6 + audit item.)
- Make the "force re-sync" experience less surprising: maybe a "Sync now" button per calendar, or honor a `nextSyncToken` so back-to-back ticks aren't redundant pulls.
- Audit calendar URI generation — consider not URL-encoding the display name into the CalDAV URI (use a stable slug instead).
