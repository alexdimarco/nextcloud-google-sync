<!--
  - SPDX-FileCopyrightText: 2026 Alex DiMarco
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Calendar Bridge — User Manual

Calendar Bridge keeps a **Nextcloud calendar** and a **Google calendar** in sync.
This guide is for **end users**. If you are a Nextcloud administrator setting the
app up for the first time, read the [Install & Admin Guide](INSTALL.md) first.

---

## 1. What it does

- **Google → Nextcloud (always):** events you add, change, or delete in Google
  Calendar are copied into your Nextcloud calendar automatically, in the
  background.
- **Nextcloud → Google (optional, per calendar):** if you turn on **Two-way
  sync** for a calendar, changes you make in Nextcloud are also pushed back to
  Google — new events, edits, deletions, and recurring series.

Two-way sync is **off by default**. Nothing you do in Nextcloud is ever sent to
Google until you explicitly enable it for a specific calendar.

> **Tip — do you even need this app?** If you *own* the Google calendar, it is
> usually simpler to subscribe to its private iCal link in Nextcloud Calendar.
> Calendar Bridge is most useful when a calendar has been **shared with you** (so
> you can't subscribe to it the normal way), or when you genuinely want changes
> to flow **both ways**.

---

## 2. Connecting your Google account

1. In Nextcloud, open **Settings → Personal → Calendar Bridge** (the section may
   still be titled *Google Synchronization*).
   - If you see *"No Google OAuth app configured"*, your administrator has not
     finished setup yet — send them the [Install & Admin Guide](INSTALL.md).
2. Click **Sign in with Google** and follow Google's prompts.
3. Grant the access it asks for. There are two possibilities, decided by your
   administrator:
   - **Read-only** access — enough for Google → Nextcloud import.
   - **Read & write** access — also lets you turn on **Two-way sync**.
4. When you return, you should see *"Successfully connected to Google!"* and a
   list of your Google calendars.

To stop syncing entirely, click **Disconnect from Google**.

---

## 3. Syncing a calendar (Google → Nextcloud)

Each Google calendar in the list has two buttons:

| Button | What it does |
| --- | --- |
| **Import calendar** | A **one-time** copy of every event from that Google calendar into Nextcloud, right now. |
| **Sync calendar** | Schedules a **background job** that keeps importing changes from that Google calendar **continuously**. This is what you usually want. |

After you click **Sync calendar**, updates appear in Nextcloud automatically
whenever your server runs its background jobs (typically every few minutes — see
[How often does it sync?](#7-how-often-does-it-sync)).

---

## 4. Turning on Two-way sync (Nextcloud → Google)

Below each calendar you'll see a **Two-way sync (Nextcloud → Google)** switch.

To use it, **all** of these must be true:

1. Your administrator enabled **read & write** access, and you **reconnected** to
   Google after that (so Google granted the write permission). If you connected
   before write access was available, click **Disconnect from Google** and sign
   in again.
2. You have **owner or editor** access to that calendar on Google. The switch is
   hidden or shows **(read-only calendar)** for calendars you can only view.
3. You've turned on **Sync calendar** first. Until you do, the switch shows
   **(enable Sync calendar first)**.

Flip the switch on, and from then on your Nextcloud edits to that calendar are
pushed to Google as well. Turning it back off stops outbound pushes immediately
(inbound import continues).

**What syncs both ways:** new events, edits, deletions, all-day and timed events,
and recurring series — including individually moved, renamed, or cancelled
occurrences.

---

## 5. Syncing a Nextcloud calendar *to* Google

You can also go the other way: take one of *your* Nextcloud calendars, create a
matching calendar in Google, and keep them in two-way sync.

In **Settings → Personal → Calendar Bridge**, scroll to **Your Nextcloud
calendars**. Each of your own calendars has a **Create in Google + sync** button.
Clicking it:

1. creates a new calendar in your Google account (with the same name),
2. links the two, and
3. copies your existing events up to Google and keeps them in sync from then on.

A large calendar's events are pushed gradually over a few background runs (to stay
within Google's limits), so they may take a little while to all appear.

**You must reconnect once.** Creating Google calendars needs an extra permission
(`calendar.app.created`). If you connected before this feature existed, click
**Disconnect from Google** and **Sign in with Google** again to grant it — until
then the button is disabled with a hint.

Two controls appear once a calendar is linked:

- **Disconnect** — stop syncing but **keep both** calendars and their events.
- **Delete both calendars** — *(destructive; asks for confirmation)* deletes the
  Google calendar permanently and moves the Nextcloud one to your calendar trash
  (recoverable from there).

If you simply **delete a linked Nextcloud calendar the normal way** (it moves to
the calendar trash), syncing for it is **paused** while it sits in the trash — the
Google calendar is left untouched. **Restore** it from the trash and syncing
resumes automatically. Only when the trash is finally emptied (the calendar is
permanently removed) is the link dropped for good.

As with the other direction, **event details sync but guest lists (attendees) do
not**, and only calendars you own can be synced this way.

## 6. How conflicts are handled

If the *same* event is changed on *both* sides, Calendar Bridge uses
**last-writer-wins**: the most recent change wins, and if the timing is a tie,
**Nextcloud wins**. The losing side is updated to match on the next sync. No event
is silently merged or duplicated.

Deletions are authoritative: if you delete an event in Nextcloud (with two-way on)
it is deleted in Google, and vice-versa.

---

## 7. How often does it sync?

Calendar Bridge runs inside Nextcloud's **background jobs**. Each time background
jobs run, it imports the latest Google changes and (if two-way is on) pushes your
latest Nextcloud changes.

For timely, reliable syncing, your administrator should have background jobs set
to **Cron** (not *AJAX*). If background jobs stop running for a long time, syncing
pauses until they resume — see the limitations below.

---

## 8. Limitations & good-to-knows

These are intentional, non-destructive trade-offs. None of them lose or corrupt
your data — at worst, one change syncs later or stays one-directional.

- **Read-only / shared-view calendars can't be two-way.** You can only push to
  Google calendars you own or can edit.
- **A few recurring-event changes are not pushed to Google** (they stay as they
  were on Google, and a note is written to the server log). These are edits that
  can't be translated safely:
  - moving the **start** of a whole series,
  - "**this and following**" splits,
  - switching a series between **all-day and timed**,
  - changing the **recurrence rule's shape** (e.g. weekly ↔ monthly) in certain
    ways, or using `RDATE`,
  - an **unknown time zone**.

  If you need one of these, make the change on the Google side too.
- **Very heavily-customized recurring events.** If a single recurring event has
  more than ~100 individually-edited occurrences changed in one go, the extras
  may not all push in that cycle. This is rare in practice.
- **Deleting one occurrence of a Google series** may take until the next full
  refresh (rather than the next few minutes) to disappear from Nextcloud.
- **Simultaneous edits in the same cycle.** If you edit the same event on both
  sides between two syncs, Nextcloud's version wins that cycle; Google reconciles
  on the next one.
- **Keep background jobs running.** Nextcloud only remembers recent local changes
  for a limited window. If background jobs are paused for a long time, edits made
  during that gap might not get pushed to Google (they remain correct in
  Nextcloud). Just keep cron running and you'll never notice this.

### Self-healing

A few times a day, Calendar Bridge quietly **double-checks its own bookkeeping**
against both Google and Nextcloud and repairs harmless drift on its own. It never
deletes or rewrites your events to do this — anything it isn't 100% sure about is
written to the server log for your administrator to look at, not "fixed"
automatically.

---

## 9. Troubleshooting

| Symptom | What to try |
| --- | --- |
| *"No Google OAuth app configured"* | Your admin hasn't set up the Google connection yet. Point them to [INSTALL.md](INSTALL.md). |
| The **Two-way sync** switch is missing or greyed out | You have read-only access to that calendar, you haven't clicked **Sync calendar** yet, or your admin hasn't enabled write access (reconnect after they do). |
| Changes aren't appearing | Confirm background jobs are running (**Settings → Administration → Basic settings → Background jobs**); ask your admin to check **Cron** is configured. |
| It shows **Disconnect** but you were never asked to sign in / it stopped working | Your Google session may have expired. Click **Disconnect from Google**, then **Sign in with Google** again. |
| A recurring-event change didn't reach Google | It may be one of the unsupported recurrence changes above — make that change on Google directly. |

If something still looks wrong, your administrator can find detailed messages in
the Nextcloud log (search for `Calendar Bridge`).

---

*Calendar Bridge is free software (AGPL-3.0-or-later), a fork of the Google
Synchronization / Google Integration apps. Use at your own risk.*
