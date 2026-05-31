# Calendar Bridge

*(formerly "Google Synchronization"; app id `outside_provider_calendar_bridge`)*

**This is a fork of the [Google Integration][integration_google] app. Do not use both at the same time!!**
This integration supports both personal Google accounts and Google Workspace (formerly G Suite) accounts.

**Use at your own risk. This app is still in early development. Users are effectively beta testers.**

## 📖 Documentation

- **[User Manual](docs/USER_MANUAL.md)** — everyday use: connect Google, sync a calendar, turn on two-way sync, conflicts & limitations.
- **[Install & Admin Guide](docs/INSTALL.md)** — administrators: requirements, Google Cloud OAuth setup, install, cron, troubleshooting.

If all you need to do is import all of your data from Google once and permanently migrate to Nextcloud (lucky you),
you should use the [Google Integration][integration_google] app (of which this app is a fork).

However, if you're like me, you're part of a team or group that has shared a Google Calendar with you,
and you would like to keep it up to date with your Nextcloud calendar.
That's exactly what this app does.

This is a fork of [Google Integration][integration_google]
that creates a background task that will periodically import all changes from Google Calendar to your Nextcloud calendar.
As such, all functionality of [Google Integration][integration_google]
is still implemented, so you can still import Contacts, Photos, Drive manually.
However, currently, **only Google Calendar background synchronization is supported**.
Please let me know if you would like to continuously synchronize other services.
This also means that this app should not be used at the same time as [Google Integration][integration_google].

Synchronization is **Google → Nextcloud by default**, and **optionally two-way**
(Nextcloud → Google) per calendar. Two-way sync is **opt-in and off by default**:
nothing is written to Google until you enable it for a specific calendar you can
write to. See the **[User Manual](docs/USER_MANUAL.md)** for details.

This App supports:
1. **New events**: adding an event on either side creates it on the other.
1. **Modified events**: edits propagate in whichever direction is enabled.
1. **Deleted events**: deletions propagate (and, two-way, in both directions).
1. **Recurring events**: series, plus individually moved/renamed/cancelled occurrences (two-way).
1. **Conflicts**: last-writer-wins, ties go to Nextcloud.
1. **Calendars you own** and **calendars shared with you** (two-way requires write access).

## ⚠️ Disclaimers

- **If you own the Google Calendar** and only need a read-only copy, you may be better off subscribing to its private iCal address in Nextcloud Calendar instead. This fork exists for the case where a calendar is **shared with you** (no private iCal link) and/or you want **two-way** sync.
- **Two-way (Nextcloud → Google) sync is opt-in and off by default.** It must be enabled per calendar, requires write access to that Google calendar, and requires the read-write OAuth scope (see the [Install & Admin Guide](docs/INSTALL.md)).

[integration_google]: https://github.com/nextcloud/integration_google

## 🚀 Installation & setup

Enable **Calendar Bridge** through Nextcloud's Apps management (supported on
Nextcloud 32–33). It requires a one-time Google Cloud OAuth setup by an
administrator.

➡️ **Full step-by-step: [Install & Admin Guide](docs/INSTALL.md)** (requirements,
Google Cloud OAuth client, redirect URI, cron). The OAuth click-through is in
[SETUP_GOOGLE_CLOUD.md](docs/SETUP_GOOGLE_CLOUD.md).

In short: an admin enables the app, creates a Google OAuth client (Calendar API
enabled) and pastes the Client ID/Secret under **Administration → Connected
accounts**; each user then connects from **Personal → Calendar Bridge**.

## 🔥 Usage

Once signed in (Personal settings), each Google calendar shows:
- **Import calendar** — a one-time import of all its events into Nextcloud.
- **Sync calendar** — schedules a background job to keep importing changes
  continuously (runs on each cron tick — Administration → Basic settings →
  Background jobs).
- **Two-way sync (Nextcloud → Google)** — opt-in, off by default; pushes your
  Nextcloud edits back to Google for calendars you can write to.

➡️ **Everyday guide: [User Manual](docs/USER_MANUAL.md).**

![Screenshot of the app settings page](./docs/images/settings.png)

## Versioning

The following table shows the version of this app compared to the upstream version it's based on.
I was previously using the 3 semver digits to show the upstream version and `-n` to show how many releases I had made to this fork on top of that,
but it caused my releases to not be installable through the Nextcloud UI.
Going forward, I will use a semver version and I will keep this table up to date to show the version it's based on.
This also allows me to decide for myself if my releases are major, minor, or patch.

| Google Synchronization version | Google Integration version |
| ------------------------------ | -------------------------- |
| 4.1.0                          | 4.2.0                      |
| 4.0.1                          | 4.1.0                      |
| 4.0.0                          | 4.1.0                      |
| 3.2.0                          | 3.2.0                      |
| 3.0.0                          | 3.1.0                      |
| 2.2.0                          | 2.2.0                      |
| 2.1.1                          | 2.1.0                      |
| 2.1.0-2-nightly                | 2.1.0                      |
| 2.1.0-1                        | 2.1.0                      |
| 1.0.9.0                        | 1.0.9                      |

## **🛠️ State of maintenance**

While there are many things that could be done to further improve this app, the app is currently maintained with **limited effort**. This means:

- The main functionality works for the majority of the use cases
- We will ensure that the app will continue to work like this for future releases and we will fix bugs that we classify as 'critical'
- We will not invest further development resources ourselves in advancing the app with new features
- We do review and enthusiastically welcome community PR's

We would be more than excited if you would like to collaborate with us. We will merge pull requests for new features and fixes. We also would love to welcome co-maintainers.

If there is a strong business case for any development of this app, we will consider your wishes for our roadmap. Please [contact your account manager](https://nextcloud.com/enterprise/) to talk about the possibilities.

## Limitations

This app can not migrate Google photos files due to limitations in the Google Photos API making it too complex for end users.
For more information please visit [the Google Photos Documentation.](https://developers.google.com/photos/support/updates#affected-scopes-methods)

## Development guide

Use the docker compose file in this repo:

1. Install PHP dependencies (install [Composer](https://getcomposer.org/), run `composer install`)
1. Install Node dependencies (install [Node.js](https://nodejs.org/en/), run `npm install`)
1. Build JavaScript bundle: `npm run dev` or `npm run watch`
1. Change the version of nextcloud in `./docker-compose.yml` to the desired version.
1. `sudo docker compose up -d`
1. `sudo docker compose exec -u www-data app php occ app:enable outside_provider_calendar_bridge`
1. Go to localhost:8080

### Logging

The easiest way I've found for simple logging is just to append to a file in `/tmp`.
1. Add some logs:
    ```php
    file_put_contents("/tmp/bal", "Hello world", FILE_APPEND);
    ```
1. Connect to the container (if you're using `nextcloud-docker-dev`):
    ```
    docker exec -it master_stable28_1 bash
    ```
1. Tail the log file:
    ```
    tail -f /tmp/blah
    ```

This is unorthodox, but easier than using the Nextcloud logging mechanism.
- Only shows you your messages
- Don't have to figure out how to view the official logs
- Don't have to deal with JSON
- Don't have to instantiate the logger everywhere in the code

### Database

1. `sudo docker exec -it master_database-mysql_1 bash`
1. `mysql -u nextcloud -d nextcloud -p`


### Creating a release

#### Packaging a release

1. Update `CHANGELOG.md`, `appinfo/info.xml`, and `package.json` with the new version
1. Create a Git tag
1. Run the following:
    ```
    make build
    version=<version> make appstore  # will create a tar.gz in ./build/artifacts/appstore/*.tar.gz
    ```

#### Publishing a release

1. Create a GitHub release and attach the tar.gz
1. Obtain a signature of the archive:
    ```
    openssl dgst -sha512 -sign ~/.nextcloud/certificates/outside_provider_calendar_bridge.key \
        build/artifacts/appstore/*.tar.gz | openssl base64
    ```
1. Go to https://apps.nextcloud.com/developer/apps/releases/new. Paste the signature and the link to the file from the GitHub release.

[Relevant guide](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#uploading-an-app-release)
