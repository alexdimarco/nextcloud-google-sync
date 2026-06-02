<!--
  - SPDX-FileCopyrightText: 2026 Alex DiMarco
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Calendar Bridge — User Manual

Calendar Bridge keeps your **Nextcloud calendars** and your **Google
calendars** in sync. It can pull your Google events into Nextcloud, push your
Nextcloud changes back up to Google, and even create a brand-new Google
calendar that mirrors one you already have in Nextcloud.

The app also inherits one **import** feature from the upstream Google
integration it was forked from — **Contacts** — which is documented here for
completeness (section 9).

This guide is for **end users**. If you are a Nextcloud administrator setting
the app up for the first time (creating the Google OAuth app, configuring the
Client ID / Client secret, choosing pop-up vs. redirect, enabling Cron and the
required Google APIs), read the [Install & Admin Guide](INSTALL.md) first — this
manual does **not** duplicate that server setup (see section 15 for a pointer).

> **What's actively developed and tested.** This fork's focus is the **calendar**
> sync described in sections 5–8 — that is the part that is actively developed
> and verified. The **Contacts** importer (section 9) is carried over unchanged
> from the upstream app; it requires your administrator to have enabled the
> Google People API, and it is **outside this fork's calendar focus** (not
> independently verified here). It is documented so you understand every control
> you may see on the screen.

---

## Table of contents

1. [Overview and requirements](#1-overview-and-requirements)
2. [Connecting your Google account](#2-connecting-your-google-account)
3. [The permission (scope) model and when to reconnect](#3-the-permission-scope-model-and-when-to-reconnect)
4. [A tour of the Personal settings screen](#4-a-tour-of-the-personal-settings-screen)
5. [Google → Nextcloud: Import vs. Sync, Sync all, status markers](#5-google--nextcloud-import-vs-sync-sync-all-status-markers)
6. [Turning on two-way sync for an imported calendar](#6-turning-on-two-way-sync-for-an-imported-calendar)
7. [Creating a Google calendar from a Nextcloud one](#7-creating-a-google-calendar-from-a-nextcloud-one)
8. [Trashing and restoring a linked calendar](#8-trashing-and-restoring-a-linked-calendar)
9. [Importing Google Contacts (inherited feature)](#9-importing-google-contacts-inherited-feature)
10. [How conflicts are resolved](#10-how-conflicts-are-resolved)
11. [How often it syncs](#11-how-often-it-syncs)
12. [Limitations and good-to-knows](#12-limitations-and-good-to-knows)
13. [Troubleshooting](#13-troubleshooting)
14. [Privacy: what is sent to Google](#14-privacy-what-is-sent-to-google)
15. [For administrators](#15-for-administrators)

---

## 1. Overview and requirements

Calendar Bridge lives in your Nextcloud **Personal settings**, in a section
titled **"Google Synchronization"** (it shows a small Google icon next to the
title). From there you can:

- **Import** a Google calendar into Nextcloud as a one-time copy.
- **Sync** a Google calendar continuously into Nextcloud (one-way, Google →
  Nextcloud).
- Enable **two-way sync** so your Nextcloud edits flow back up to Google
  (Nextcloud ↔ Google).
- **Create** a new Google calendar from one of your existing Nextcloud
  calendars and keep them paired.
- **Disconnect** or **delete** a linked calendar pair.
- (Inherited) **Import Google Contacts** into a Nextcloud address book.

What you need before you start:

- A Nextcloud account with the Calendar Bridge app enabled.
- Your administrator must have configured the Google OAuth app. If they
  haven't, the settings screen will show:
  > **"No Google OAuth app configured. Ask your Nextcloud administrator to
  > configure Google connected accounts admin section."**
  When you see that message, there is nothing you can do until your admin
  finishes the setup described in [INSTALL.md](INSTALL.md).
- A Google account with one or more calendars.
- For the Contacts importer, your administrator must additionally have enabled
  the **Google People API** in the Google Cloud project (see section 13 if you
  hit a "this API … is disabled" error).

> **Important design promise:** Calendar Bridge does **nothing** with your
> Google account until you personally click **"Sign in with Google"**. There
> are no automatic or background operations before you connect. And even once
> connected, **nothing is written to Google** until you explicitly turn on
> two-way sync for a specific calendar. See
> [Privacy](#14-privacy-what-is-sent-to-google).

---

## 2. Connecting your Google account

When your admin has configured the OAuth app, the settings screen shows an
**"Authentication"** subsection.

### Sign in

1. Open **Settings → Personal → Google Synchronization** (the exact location of
   personal settings depends on your Nextcloud theme; look for the
   "Google Synchronization" heading).
2. Under **"Authentication"**, click **"Sign in with Google"**.
   - This button only appears when the OAuth app is configured **and** you are
     not already connected.
3. You are taken to Google's standard sign-in and consent screen (either as a
   full-page redirect or in a pop-up window — your admin chooses which). Review
   the permissions Google lists and approve them.
4. Google sends you back to Nextcloud. On success, the screen updates to show
   your connection.

### What you'll see once connected

- A label reading **"Connected as {user}"**, where `{user}` is your Google
  account name.
- A **"Disconnect from Google"** button (see below).
- Depending on the permissions you granted, additional subsections appear:
  **"Contacts"**, **"Calendars"** (your Google calendars), and **"Your Nextcloud
  calendars"**.

Connecting typically takes only a few seconds after you approve on Google's
side.

### Disconnect

1. Under **"Authentication"**, click **"Disconnect from Google"** (visible only
   while connected).
2. This logs you out of the Google integration and clears your stored Google
   account name and tokens.

Disconnecting stops the integration from talking to Google. It does **not**
delete any calendars or events that were already imported, and it preserves the
internal links between paired calendars, so signing back in re-links them
cleanly.

---

## 3. The permission (scope) model and when to reconnect

When you sign in, Calendar Bridge asks Google for a set of permissions
("scopes"). The features you can use depend on which permissions you granted —
and your admin controls which permissions are requested.

The permissions that matter for the **calendar** features are:

- **Read-write calendar events** — lets the app create, edit and delete events
  in Google. This unlocks outbound **two-way sync (Nextcloud → Google)**.
  Internally this is tracked as the **`can_write_calendar`** flag.
- **Create app calendars** — a least-privilege permission that lets the app
  create and manage **only the calendars it creates itself** (it never touches
  your pre-existing Google calendars). This unlocks the **"Create in Google +
  sync"** feature. Internally this is the **`can_create_calendar`** flag.

The inherited **Contacts** importer uses its own contacts-access permission,
which is also requested at sign-in.

> **Widen-and-gate / when to reconnect.** Your granted permissions are recorded
> at the moment you sign in and are **completely overwritten only when you sign
> in again**. So if your admin later turns on two-way sync or calendar creation
> (adding new scopes to the request), **existing connections do not upgrade
> automatically** — you keep whatever you originally granted until you
> re-consent.
>
> To gain the new permissions you must **Disconnect from Google** and then
> **Sign in with Google** again. This is the only way to grant new scopes;
> automatic upgrades are impossible because Google requires you to re-consent.

You'll know you need to reconnect because the app tells you. For example, in
the **"Your Nextcloud calendars"** section you may see:
> **"Reconnect your Google account to enable creating Google calendars (needs
> calendar create + write access)."**
This hint appears whenever you are connected but are missing the create and/or
write permission.

---

## 4. A tour of the Personal settings screen

Everything below lives under the **"Google Synchronization"** heading. Sections
appear and disappear depending on what you've connected and what permissions
you granted.

### 4.1 "Authentication" subsection

- Shown when the OAuth app is configured.
- Controls: **"Sign in with Google"** (when not connected) and, once connected,
  the **"Connected as {user}"** label plus the **"Disconnect from Google"**
  button. See [section 2](#2-connecting-your-google-account).

### 4.2 "Contacts" subsection (inherited)

- Shown once you are connected (and your contact count could be read).
- An **"Include other contacts"** toggle, a contact count, and an **"Import
  Google Contacts in Nextcloud"** button. See
  [section 9](#9-importing-google-contacts-inherited-feature).

### 4.3 "Calendars" subsection (your Google calendars)

- Shown once you are connected and at least one Google calendar is available.
- **"Import all events including Birthdays"** toggle — when on, includes
  Birthday calendars and other non-standard event calendars in imports and
  syncs. Off by default.
- **"Sync all ({count} not synced)"** button — bulk-enables continuous sync for
  every Google calendar that isn't already synced (see
  [section 5](#5-google--nextcloud-import-vs-sync-sync-all-status-markers)).
- A list of your Google calendars. Each row shows a colored bullet and the
  calendar's name, plus per-calendar controls:
  - **"Import calendar"** button
  - **"Sync calendar"** toggle
  - **"Two-way sync (Nextcloud → Google)"** toggle (only when you have write
    permission)
  - A **"(not synced)"** marker when the calendar has no active sync job

  > Calendars that were *created from* a Nextcloud calendar (see
  > [section 7](#7-creating-a-google-calendar-from-a-nextcloud-one)) are **not**
  > listed here — they are managed in the "Your Nextcloud calendars" section
  > instead.

### 4.4 "Your Nextcloud calendars" subsection (Nextcloud → Google)

- Shown when you are connected and have permission to read your calendars.
- Hint text: **"Create a matching Google calendar from one of your Nextcloud
  calendars and keep them in two-way sync."**
- If you're missing the create/write permission, the reconnect hint from
  [section 3](#3-the-permission-scope-model-and-when-to-reconnect) appears here.
- Lists your own writable Nextcloud calendars eligible for linking, each with a
  colored bullet (if the calendar has a color) and its name. Per-calendar
  controls depend on whether it's already linked:
  - Not linked: **"Create in Google + sync"** button.
  - Linked: a **"Linked & syncing"** label plus **"Disconnect"** and
    **"Delete both calendars"** buttons.
- If none of your calendars are eligible, you'll see: **"No eligible Nextcloud
  calendars to sync."**

See [section 7](#7-creating-a-google-calendar-from-a-nextcloud-one) for full
instructions on each of these.

---

## 5. Google → Nextcloud: Import vs. Sync, Sync all, status markers

This is the most common use: bringing your Google calendars into Nextcloud.
There are two ways to do it, and they are different.

### Import vs. Sync — what's the difference?

| | **"Import calendar"** | **"Sync calendar"** |
|---|---|---|
| What it does | A **one-time** copy of all events from a Google calendar into the matching Nextcloud calendar. | Sets up a **continuous** background job that keeps pulling Google changes into Nextcloud. |
| When events update | Only at the moment you click. | On every background run, indefinitely, until you turn it off. |
| Direction | Google → Nextcloud (one-time). | Google → Nextcloud (ongoing). |

### Import a Google calendar once

1. In the **"Calendars"** section, find the calendar you want.
2. Click its **"Import calendar"** button.
3. The button is disabled while the import is running for that calendar. When it
   finishes, the events appear in the matching Nextcloud calendar. The app
   reports how many events were **added** and **updated**.

Re-importing later is safe: existing events are updated in place rather than
duplicated.

### Continuously sync a Google calendar

1. In the **"Calendars"** section, turn on the **"Sync calendar"** toggle for
   that calendar.
2. The moment you enable it, an **initial import** runs once (it fetches all
   events from that Google calendar). After that, a background job keeps
   **incrementally** pulling changes on every cron tick.
3. To stop, turn the **"Sync calendar"** toggle back off. The background job is
   unregistered.

What gets imported for each event: the summary/title, location, description,
colors, time zones, organizer, reminders, recurrence rules (repeating events),
and recurrence exceptions (deleted/moved single occurrences). Attendees are
imported for display, but attendee/guest-list edits are **not** synced back to
Google (see [Limitations](#12-limitations-and-good-to-knows)). If Google marks
an event as **private**, it is imported with a generic title.

### "Sync all"

1. If one or more Google calendars are not yet synced, a **"Sync all ({count}
   not synced)"** button appears (the count is how many are unsynced).
2. Click it to register the continuous sync job for **all** of those calendars
   at once.
3. The button is disabled while the bulk operation runs.

### "Import all events including Birthdays"

- This toggle (top of the **"Calendars"** section) controls whether Birthday
  calendars and other non-standard event calendars are included when you import
  or sync. Turn it on if you want those; leave it off to skip them.

### Status markers you'll see

- **"(not synced)"** — appears next to a calendar that has no active sync job.
  Once you enable **"Sync calendar"** (or use **"Sync all"**), this marker goes
  away for that calendar.

---

## 6. Turning on two-way sync for an imported calendar

By default, syncing only flows **into** Nextcloud. To also push your Nextcloud
edits **up to Google**, enable two-way sync — but only on calendars you own or
can edit.

### Requirements (all must be true)

- You granted the **read-write calendar** permission when you signed in (the
  `can_write_calendar` flag). If you didn't, reconnect first
  ([section 3](#3-the-permission-scope-model-and-when-to-reconnect)).
- The Google calendar is **writable** by you (your access is *owner* or
  *writer*). Read-only calendars (shared to you as *viewer* or *free/busy*)
  cannot be pushed to.
- **"Sync calendar"** is already enabled for that calendar.

### How to enable it

1. In the **"Calendars"** section, make sure **"Sync calendar"** is on for the
   calendar.
2. Turn on the **"Two-way sync (Nextcloud → Google)"** toggle for that calendar.
3. From now on, events you create, edit, or delete in that Nextcloud calendar
   are pushed up to Google on the next background run.

The two-way toggle starts **off** and is per-calendar — you opt in one calendar
at a time.

### The hints next to the two-way toggle

The **"Two-way sync (Nextcloud → Google)"** toggle only appears when you have
the write permission. Depending on the calendar's state, you may also see:

- **"(enable Sync calendar first)"** — shown when the calendar isn't synced
  yet. Turn on **"Sync calendar"** to unlock the two-way toggle.
- **"(read-only calendar)"** — shown when the calendar is synced but you only
  have read access to it on Google. Two-way sync can't be enabled because your
  edits would be rejected by Google.

Even if you somehow bypass the UI, the server independently refuses to enable
two-way sync without the write permission (you'd get a *"The read-write
calendar scope has not been granted; reconnect your Google account to enable
two-way sync."* error). This is a deliberate safety net.

### What happens behind the scenes

- New Nextcloud events are created in Google. Edits are pushed with safe
  conflict checking. Deletions in Nextcloud delete the event in Google.
- The first time two-way sync runs on an **imported** (Google-origin) calendar,
  all existing events are recognized as already-present (no duplicates are
  created).
- Turning two-way **off** and back **on** later does not re-duplicate your
  events; the internal event map is preserved while it's off.

---

## 7. Creating a Google calendar from a Nextcloud one

If you have a calendar that lives only in Nextcloud, you can create a matching
Google calendar and keep the two paired in two-way sync. This is managed in the
**"Your Nextcloud calendars"** section.

### Requirements

- You must be connected, and have permission to read your calendars (so the
  section shows up at all).
- For the **"Create in Google + sync"** button to be **enabled**, you need
  **both** the **create-calendar** permission (`can_create_calendar`) **and**
  the **read-write** permission (`can_write_calendar`). If either is missing,
  the button is disabled and you'll see the reconnect hint from
  [section 3](#3-the-permission-scope-model-and-when-to-reconnect). (If a request
  somehow reaches the server without both, it is refused with *"The
  calendar-create and read-write scopes are not both granted; reconnect your
  Google account to enable this."*)
- Only calendars you **own** are eligible — not calendars shared into your
  account, not Birthday calendars, and not the app-created calendars that came
  from Google imports.

### Create in Google + sync

1. In **"Your Nextcloud calendars"**, find your calendar in the list (shown with
   a colored bullet and its name).
2. Click **"Create in Google + sync"**.
   - The button is disabled while it's working, and stays disabled entirely if
     you lack the create or write permission.
3. The app creates a **new, empty Google calendar** with the same name and
   turns on two-way sync automatically. Your existing Nextcloud events are then
   pushed up to Google **gradually in the background** (by default up to 50
   events per background run), so a large calendar fills in over several cron
   ticks rather than all at once.
4. Once linked, the row changes to show **"Linked & syncing"** along with the
   **"Disconnect"** and **"Delete both calendars"** buttons.

### Disconnect (keep both calendars)

1. On a linked calendar, click **"Disconnect"** (disabled briefly while it
   works).
2. This **unlinks** the pair: syncing stops and the internal event map is
   cleared, but **both** the Nextcloud calendar and the Google calendar remain
   exactly where they are. Nothing is deleted.

### Delete both calendars (destructive)

1. On a linked calendar, click **"Delete both calendars"** (a red button).
2. A **confirmation dialog** appears first — you must confirm.
3. On confirmation: the **Google calendar is deleted permanently**, and the
   **Nextcloud calendar is moved to the trash**.

> Use this only when you're sure. The Google side is gone for good; the
> Nextcloud side can still be recovered from trash until trash is emptied.

### "No eligible Nextcloud calendars to sync."

If you have no calendars that qualify (e.g., everything you have is shared-in or
already linked), this message appears in place of the list.

---

## 8. Trashing and restoring a linked calendar

You can manage a linked Nextcloud calendar from the regular **Nextcloud
Calendar app** (or via admin `occ` commands), and Calendar Bridge reacts safely.

### Trash (soft-delete) a linked calendar

When you move a linked Nextcloud calendar to the trash:

- The import/sync job is unregistered and two-way sync is turned **off**.
- The internal event map is **kept**.
- The Google calendar is left completely untouched.

Syncing simply **pauses** while the calendar sits in trash. Any pending
deletions you'd made before trashing are not lost — the sync token is preserved
so nothing is dropped.

### Restore from trash

1. Restore the calendar from the Nextcloud Calendar app's trash.
2. The sync job is automatically re-registered and two-way sync is re-enabled
   using the preserved sync token. **No action is needed in Calendar Bridge.**
3. Because the per-event map survived, every event is recognized as already
   synced — **nothing is re-pushed or duplicated**.

### Permanently delete (empty trash / force-delete)

When you permanently delete a linked Nextcloud calendar (emptying trash or a
force-delete):

- The link is severed and the event map is dropped; the sync job is
  unregistered.
- The **Google calendar is deliberately kept** on Google's side. A Nextcloud
  deletion will never silently destroy your Google copy.

> Contrast this with **"Delete both calendars"** in
> [section 7](#7-creating-a-google-calendar-from-a-nextcloud-one), which *does*
> delete the Google calendar — but only after you explicitly confirm.

---

## 9. Importing Google Contacts (inherited feature)

> **Inherited / not part of the calendar focus.** This importer is carried over
> from the upstream Google integration. It requires your administrator to have
> enabled the **Google People API** in the Google Cloud project and to have left
> the contacts permission in the sign-in request. It is documented for
> completeness and has **not** been independently verified in this fork. If you
> only need calendars, you can ignore this section.

The **"Contacts"** subsection lets you copy your Google contacts into a
Nextcloud address book. It is a **one-time import** (not a continuous sync).

Controls:

- **"Include other contacts"** toggle — when on, Google's "other contacts"
  (people you've interacted with but haven't saved) are included in the count
  and the import. This appears only if you granted the matching permission.
- A contact count, shown as either **"{amount} Google contacts"** or, with the
  toggle on, **"{amount} Google + {otherAmount} other contacts"**.
- **"Import Google Contacts in Nextcloud"** button.

To import:

1. (Optional) turn on **"Include other contacts"**.
2. Click **"Import Google Contacts in Nextcloud"**.
3. A destination picker appears (**"Choose where to import the contacts"**).
   Pick an existing address book, or choose **"➕ New address book"** and type
   an **"address book name"**.
4. Click **"Import in "{name}" address book"** to start. Your Google contacts
   are copied into that address book.

If the People API isn't enabled for the project, this section will report a
Google error instead of a contact count — see
[Troubleshooting](#13-troubleshooting).

---

## 10. How conflicts are resolved

When the same event changes on both sides, Calendar Bridge uses
**last-writer-wins (LWW)** based on each side's update time:

- If Google's change is newer, it is pulled into Nextcloud.
- If Nextcloud's change is newer, Google's incoming change is ignored and the
  next outbound push re-asserts your Nextcloud version.
- **Ties go to Nextcloud.** If the same event is edited on both sides within the
  same sync cycle, **Nextcloud's version wins** that cycle and Google reconciles
  on the next one.

Other safety behaviors:

- **No duplicates from echoes.** When your own Nextcloud event comes back from
  Google during import, the app recognizes it (via an internal origin tag) and
  updates the existing Nextcloud object instead of creating a second copy.
- **Deletes win cleanly.** A Nextcloud deletion deletes the Google event; if
  Google already removed it, that's treated as success.
- **Periodic self-heal.** At most about once every six hours per two-way
  calendar, the app quietly re-checks the Nextcloud↔Google map against both
  live sides and repairs only the changes that are provably safe (for example,
  removing a map entry when both the Nextcloud object and Google event are
  already gone). Anything uncertain is **only logged**, never silently changed.
  You will almost never notice this happening.

---

## 11. How often it syncs

- **Imports** (the **"Import calendar"** button) happen immediately when you
  click, once.
- **Continuous sync** runs on Nextcloud's **background jobs** (cron). After the
  one-time initial import, each cron tick pulls incremental Google changes and
  pushes any pending Nextcloud changes (if two-way is on).
- Practical timing depends entirely on how often your server runs background
  jobs. For reliable two-way sync, your administrator should have Nextcloud set
  to **Cron** (not AJAX). If it's set to AJAX, syncing may only happen while
  someone is actively using Nextcloud and can stall for long periods. See
  [Troubleshooting](#13-troubleshooting).

---

## 12. Limitations and good-to-knows

- **Read-only calendars can't be two-way.** You need *owner* or *writer* access
  on the Google calendar. Read-only calendars can only be imported/synced
  inbound; the two-way toggle is hidden or shows **"(read-only calendar)"**.
- **Attendees and guest lists are not synced.** Event attendees, reminders and
  alarms are not mirrored between sides. They stay safely on whichever side they
  were created — they're just not copied across. This is intentional.
- **Some recurring-series edits aren't pushed to Google.** The following edits
  to a repeating series are detected and **refused** for outbound sync (they
  stay one-way and are logged, but nothing is corrupted or lost):
  - Moving the **start** of the whole series (DTSTART move).
  - A **"this and following"** split.
  - Switching a series between **all-day and timed** at the master event.
  - Changing the **shape** of the recurrence (e.g. single ↔ recurring, weekly ↔
    monthly).
  - Adding an **RDATE** (extra explicit dates).
  - An **unresolvable time-zone** ID.

  If you need one of these changes, make it **directly on the Google side**. The
  series remains fully readable and importable in the meantime.
- **Very heavily customized recurring events.** If a single sync cycle would
  need to write more than ~100 individually-edited occurrences of one series,
  the first batch is written and the rest are carried over to the next cycle.
  Nothing is lost; the series catches up over subsequent runs. (Rare in
  practice.)
- **Deleting one occurrence of a Google series** may take until the next full
  refresh to disappear from Nextcloud, rather than within a couple of minutes.
  A full pull is forced after certain operations, so the delay is usually short.
- **Background jobs must keep running.** Nextcloud only remembers recent local
  changes for a limited window (often around 60 days). If background jobs stop
  for longer than that, edits made during the gap might not get pushed to Google
  — but they remain **safe and correct in Nextcloud**. The next change to the
  series restarts normal two-way sync. Keep your server's Cron running.
- **Linking creates an empty Google calendar.** **"Create in Google + sync"**
  makes a *new* Google calendar; it does not merge into an existing one. Your
  Nextcloud events then drain into it gradually.
- **Contacts is a one-time import, not sync** (section 9), and depends on the
  Google People API being enabled by your admin.

---

## 13. Troubleshooting

**Nothing is syncing, or it only syncs when someone is using Nextcloud.**
This usually means background jobs are set to **AJAX** instead of **Cron**. Ask
your administrator to switch Nextcloud to Cron (Settings → Administration →
Basic settings → Background jobs). Cron is required for reliable two-way sync.

**Sync stopped and the log mentions `invalid_grant`.**
Your Google connection expired or was revoked (often after many months of
inactivity, or if you revoked the app's access in your Google account). There's
no automatic recovery. **Fix:** click **"Disconnect from Google"**, then
**"Sign in with Google"** again to refresh your connection.

**I want a feature but its button is disabled or its toggle is missing.**
You're likely missing the matching Google permission. Reconnect to grant it:
**Disconnect from Google**, then **Sign in with Google** again (see
[section 3](#3-the-permission-scope-model-and-when-to-reconnect)). For example,
**"Create in Google + sync"** needs both create and write access; the two-way
toggle needs write access.

**The "Contacts" section shows an error like "Google People API has not been
used in project … before or it is disabled."**
The People API isn't enabled for your deployment. The Contacts importer is
inherited from the upstream app and is outside this fork's calendar focus.
**Fix (admin):** either enable the **Google People API** in the Google Cloud
project (**APIs & Services → Library**), or simply ignore the Contacts section
if you only use the calendar features.

**The screen still shows "Disconnect from Google" but signing in keeps
failing.**
Your stored connection may be stale even though the UI shows you as connected.
You can't always re-consent on your own in this state. **Ask your administrator**
to clear your stored connection record (an `occ user:setting … user_name ''`
command), which preserves your calendar links. After they do that, click
**"Sign in with Google"** again to re-consent.

**Right after sign-in Google shows "Error 400: redirect_uri_mismatch".**
This is a server configuration problem, not something you can fix from your
account. Report it to your administrator — the redirect URI in the Google Cloud
Console must exactly match the app's callback path. See
[INSTALL.md](INSTALL.md).

**Where do errors get logged?**
Background sync results and errors are written to the Nextcloud log. Your
administrator can search it for **"Calendar Bridge"** to find import counts,
classification results, and any warnings.

---

## 14. Privacy: what is sent to Google

Calendar Bridge is built to be conservative about your data:

- **Nothing happens until you connect.** No Google API calls, no imports, and no
  data leaves Nextcloud until you click **"Sign in with Google"** and approve.
- **Nothing is written to Google until you opt in.** Even after connecting with
  write permission, **no events are written to Google** until you explicitly
  enable **"Two-way sync (Nextcloud → Google)"** for a specific calendar — or
  use **"Create in Google + sync"**. Outbound writing is off by default and is
  per-calendar.
- **Least-privilege calendar creation.** The create-calendar permission only
  lets the app manage calendars **it created itself**; it never modifies your
  other Google calendars.
- **Your edits go up only on owned/writable calendars.** Read-only calendars
  are never written to.
- **No silent data destruction.** Whenever the app is unsure about a sync
  decision, it logs the situation and takes no action rather than guessing. A
  Nextcloud-side deletion never silently deletes your Google calendar (only the
  explicit, confirmed **"Delete both calendars"** does that).
- **You stay in control.** Disconnecting at any time stops the integration
  without deleting your calendars or events.

When two-way sync is on for a calendar, the event details you create/edit in
Nextcloud (title, time, location, description, recurrence, etc.) are sent to
Google for that calendar — but **attendee/guest lists and reminders are not**
pushed up. The **Contacts** importer (section 9) only ever reads *from* Google
into Nextcloud.

---

## 15. For administrators

Full setup lives in the [Install & Admin Guide](INSTALL.md). In short, the app
adds an **admin** settings screen (also titled **"Google Synchronization"**)
where you:

- follow the step-by-step instructions to create a Google OAuth "Web
  application" credential in the Google Cloud Console;
- enter the **"Client ID"** and **"Client secret"**;
- choose **"Use a pop-up to authenticate"** (pop-up) vs. the full-page redirect;
- enable the **Google Calendar API** and, only if you want the inherited
  Contacts importer, the **Google People API** in **APIs & Services → Library**;
- and, if needed after an upgrade, use **"Delete all background jobs"** to clear
  every user's synchronization jobs (it warns: *"This will delete Calendar
  synchronization jobs for all users!"*).

Users then see the **"Sign in with Google"** button described in
[section 2](#2-connecting-your-google-account).
