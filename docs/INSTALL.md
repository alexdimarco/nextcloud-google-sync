<!--
  - SPDX-FileCopyrightText: 2026 Alex DiMarco
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Calendar Bridge — Install & Admin Guide

This guide is for **Nextcloud administrators**. It covers requirements, the
one-time Google Cloud setup, installing and configuring the app, and keeping it
healthy. End users should read the [User Manual](USER_MANUAL.md) once you've
finished here.

> **What the app does, in one line:** it syncs Google Calendar into Nextcloud in
> the background, and — per calendar, opt-in — pushes Nextcloud changes back to
> Google (two-way). It is a calendar-focused fork of the *Google Synchronization*
> / *Google Integration* apps; **do not run it alongside the upstream Google
> integration app.**

---

## 1. Requirements

| Requirement | Notes |
| --- | --- |
| **Nextcloud 32–33** | See `max-version` in `appinfo/info.xml`; bump it when you test newer releases. |
| **PHP 8.1+** | Matches `composer.json`. |
| **Background jobs = Cron** | Strongly recommended. *AJAX* mode syncs only while someone is browsing and is unreliable for a sync app. |
| **A Google account** | The account whose calendars you want to sync. |
| **A Google Cloud project** | To create the OAuth client below. One project can serve all your users. |

This fork is **calendar-focused**. The Google **Drive** importer has been
removed; an inherited **Contacts** importer remains (optional — it needs the
Google People API enabled).

---

## 2. One-time Google Cloud setup (OAuth)

The app talks to Google as an **OAuth 2.0 Web application**. You create the client
once; all users share it.

A detailed, click-by-click walkthrough (written against a test account) lives in
[SETUP_GOOGLE_CLOUD.md](SETUP_GOOGLE_CLOUD.md). The essentials:

1. **Create / pick a Google Cloud project** at <https://console.cloud.google.com/>.
2. **Enable the Google Calendar API** (APIs & Services → Library).
3. **Configure the OAuth consent screen** (External). While your project is in
   *Testing*, add each user's Google address as a **Test user**.
4. **Choose the scope** you'll request (this decides read-only vs two-way):
   - `https://www.googleapis.com/auth/calendar.readonly` — Google → Nextcloud only.
   - `https://www.googleapis.com/auth/calendar.events` — **read & write**, required
     for **two-way** sync (Nextcloud → Google).
5. **Create an OAuth client ID** of type **Web application** and add the exact
   **Authorized redirect URI**:
   ```
   https://<your-nextcloud-domain>/apps/outside_provider_calendar_bridge/oauth-redirect
   ```
   - Use `http://localhost:8080/...` for a local lab (Google allows `localhost`
     over HTTP).
   - The path segment **must** be the app id `outside_provider_calendar_bridge`.
     A mismatch here is the #1 setup error (`Error 400: redirect_uri_mismatch`).
6. Copy the **Client ID** and **Client Secret** for the next step.

---

## 3. Install the app

**From the App Store** (once published): Nextcloud → **Apps** → search
*Calendar Bridge* → **Download and enable**.

**From source / a release tarball:**

```sh
# place the app under custom_apps/ (or apps/) as outside_provider_calendar_bridge
occ app:enable outside_provider_calendar_bridge
```

The app id (directory name) **must** be `outside_provider_calendar_bridge` so the
OAuth redirect URI matches.

Enabling runs the database migrations (they create the `calbridge_event_map`
bookkeeping table). After any version bump, run `occ upgrade` if Nextcloud reports
the app needs it.

---

## 4. Configure the OAuth client in Nextcloud

1. Go to **Settings → Administration → Connected accounts** (the *Calendar
   Bridge* / *Google* section).
2. Paste the **Client ID** and **Client Secret** from step 2.
3. Save.

That's the only admin-side configuration. Users now self-connect from their
Personal settings (see the [User Manual](USER_MANUAL.md)).

### Read-only vs. two-way, and re-consent

- If you only requested the **read-only** scope, users get Google → Nextcloud
  import and the **Two-way sync** switch stays unavailable.
- To allow **two-way**, request the **`calendar.events`** scope (step 2.4). Users
  who connected *before* you did this must **Disconnect** and **Sign in** again so
  Google grants the write permission. The app stores a `can_write_calendar` flag
  per user once the write scope is present.

Two-way is still **opt-in per calendar** by each user and **off by default** — the
write scope only makes the switch *available*.

---

## 5. Background jobs (cron)

Calendar Bridge does its work from a Nextcloud **background job** that runs on
every cron tick, per synced calendar.

1. **Settings → Administration → Basic settings → Background jobs → Cron**.
2. Make sure system cron actually runs, e.g. every 5 minutes:
   ```cron
   */5 * * * * php -f /var/www/nextcloud/cron.php
   ```

Each tick: import new Google changes, then (for two-way calendars) push Nextcloud
changes, then occasionally run the self-check below.

To force a run while testing:
```sh
occ background-job:list | grep ImportCalendar   # find the job id
occ background-job:execute <id> --force-execute
```

---

## 6. Health, self-healing & logs

- **Errors are logged.** Import failures and sync problems are written to the
  Nextcloud log; search for `Calendar Bridge`. (Under system cron these only
  appear in the log, not on a console.)
- **Self-healing verify pass.** A few times a day (at most once every ~6 hours per
  two-way calendar) the app re-derives its `nc_uri ↔ google_id` map from **both**
  live sides and:
  - **repairs** only provably-safe drift — dropping a bookkeeping row whose
    Nextcloud object *and* Google event are both gone, and re-pointing a stale
    `google_id` to the Google event that still carries our `ncOrigin` tag;
  - **logs (never auto-fixes)** anything ambiguous — a stale baseline, an
    un-baselined row, a foreign-tagged event, etc.

  It **never** creates, edits, or deletes a Google event or a Nextcloud object, so
  it cannot lose data. Its findings are also stamped on the map row's `last_error`
  column. Watch for repeated `verify:` warnings as a signal to investigate.

---

## 7. Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `Error 400: redirect_uri_mismatch` at sign-in | The Authorized redirect URI in Google doesn't exactly match `https://<domain>/apps/outside_provider_calendar_bridge/oauth-redirect`. Fix it in the Cloud Console. |
| Users see *"No Google OAuth app configured"* | Client ID/Secret not saved in **Administration → Connected accounts**. |
| The **Two-way sync** switch never appears | You requested only the read-only scope, or the user hasn't reconnected since you enabled the write scope. |
| `invalid_grant` in the log; sync stops | The user's Google refresh token was revoked or expired (often from long inactivity). The user must **Disconnect** and **Sign in** again. |
| UI shows **Disconnect** but sync is broken / user can't re-consent | Clear the stale connection server-side, then have them reconnect: `occ user:setting <uid> outside_provider_calendar_bridge user_name ''`. |
| Nothing syncs | Background jobs aren't running, or are set to *AJAX*. Switch to **Cron** and confirm system cron fires. |
| App reports *needs upgrade* after an update | Run `occ upgrade`. |
| 403 in the **Contacts** section | Expected unless you enabled the Google **People API** — the Contacts importer is optional and outside the calendar focus. |

---

## 8. Security notes

- **No data leaves Nextcloud until a user connects**, and **nothing is written to
  Google** until a user turns on two-way for a specific writable calendar.
- The generic config endpoint enforces a **key allowlist**: users cannot forge the
  OAuth token, granted scopes, or the two-way flag through it — scopes are set only
  by the OAuth redirect, and the two-way flag only by the dedicated, access-checked
  endpoint.
- Client Secret and per-user tokens are stored as Nextcloud app/user config; treat
  the database and `config/` accordingly.

---

## 9. Uninstalling

```sh
occ app:disable outside_provider_calendar_bridge
```

Disabling stops all syncing. Already-imported events remain in users' Nextcloud
calendars (they are normal calendar objects). The `calbridge_event_map` table is
left in place unless you remove the app entirely.

---

*See also: the [User Manual](USER_MANUAL.md), the design docs
[BIDIRECTIONAL_SYNC.md](BIDIRECTIONAL_SYNC.md) and
[RECURRENCE_OUTBOUND.md](RECURRENCE_OUTBOUND.md), and the OAuth walkthrough
[SETUP_GOOGLE_CLOUD.md](SETUP_GOOGLE_CLOUD.md).*
