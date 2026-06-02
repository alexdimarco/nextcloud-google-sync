# Google Cloud OAuth setup — lab notes

This is the procedure used to wire up a sacrificial Google account to the lab's
`google_synchronization` app at `http://localhost:8080`. It's specific to our
dev/test setup; production setup will differ (HTTPS, verified domain, etc.).

## What the app needs from Google

- An **OAuth 2.0 Web application** client (Client ID + Client Secret).
- The following Google APIs enabled in the project:
  - Google Calendar API (required for Phase 0)
  - Google People API (used for contacts — not under test in Phase 0 but the
    consent screen scopes include it if enabled)
  - Google Drive API and Google Photos API are **not used** in this fork (Drive
    removed in 4.8.0; Photos dropped in 4.0.0).
- The OAuth consent screen configured in **Testing** mode with the
  sacrificial Google account added as a Test user.
- The exact redirect URI:
  ```
  http://localhost:8080/apps/google_synchronization/oauth-redirect
  ```
  (Google permits `http://localhost` redirect URIs for OAuth; no HTTPS needed
  for the lab.)

## Step-by-step (fill in as you go)

### 1. Create a Google Cloud project

- Console: https://console.cloud.google.com/
- Click the project dropdown → **New Project**.
- Name: `nextcloud-lab-sync` (or whatever you prefer; it's local to your
  Google account).
- Organization: leave as-is.
- Click **Create**, wait for it, then switch the dropdown to that project.

Done? [ ]

### 2. Enable APIs

- Left nav: **APIs & Services → Library**.
- Search for and **Enable**:
  - **Google Calendar API**
  - **People API** (only needed if you'll also exercise the contacts importer)

Done? [ ]

### 3. Configure the OAuth consent screen

- Left nav: **APIs & Services → OAuth consent screen**.
- User Type: **External** (you're not part of a Google Workspace, or you are
  but you want a personal-account test path). Click **Create**.
- App information:
  - App name: `nextcloud-lab-sync`
  - User support email: your email
  - App logo: skip
- App domain section: skip (it's for verified production apps).
- Developer contact: your email.
- Click **Save and Continue**.
- **Scopes**: click **Add or Remove Scopes** and add:
  - `https://www.googleapis.com/auth/calendar.readonly`
  - `https://www.googleapis.com/auth/calendar.events.readonly`
  - (Optional for Phase 0) `https://www.googleapis.com/auth/contacts.readonly`,
    `https://www.googleapis.com/auth/contacts.other.readonly`
  - (Always) `openid`, `email`, `profile` — required so the app can pull
    `userinfo` to display "connected as X".
- Save and continue.
- **Test users**: add the sacrificial Google account email. Save and continue.
- Review summary → Back to Dashboard.

Done? [ ]

### 4. Create the OAuth 2.0 Client ID

- Left nav: **APIs & Services → Credentials**.
- Click **Create credentials → OAuth client ID**.
- Application type: **Web application**.
- Name: `nextcloud-lab-sync-client`.
- **Authorized JavaScript origins**: leave empty (the app doesn't use the JS
  client flow).
- **Authorized redirect URIs** → **Add URI**:
  ```
  http://localhost:8080/apps/google_synchronization/oauth-redirect
  ```
- Click **Create**.
- A dialog will show the **Client ID** and **Client Secret**. Copy both
  somewhere private. Do **not** paste them into this file. Do **not** commit
  them to git.

Storage location for credentials:
- `pass google/test-calendar-bridge/client_id`
- `pass google/test-calendar-bridge/client_secret`

Done? [ ]

### 5. Plug credentials into Nextcloud

- Log in to http://localhost:8080 as `admin` (password: `localadminpass`).
- Top-right avatar → **Administration settings**.
- Left side: scroll to the **Administration** section → **Google Synchronization**.
- Paste the Client ID and Client Secret. Save (PasswordConfirmationRequired —
  you'll be re-prompted for your admin password).
- The "Use a popup for the OAuth redirect" toggle: leave **off** for the lab
  (popup mode tends to be flaky with localhost).

Done? [ ]

### 6. Connect a user account

- Still as `admin` (or as a test user — for Phase 0 we're using admin for
  simplicity).
- Top-right avatar → **Personal settings**.
- Left side: **Google Synchronization** section.
- Click **Sign in with Google**.
- Pick the sacrificial test Google account.
- Google will warn that the app is unverified ("Google hasn't verified this
  app") — that's expected in Testing mode. Click **Advanced → Go to
  nextcloud-lab-sync (unsafe)** and proceed.
- Approve the requested scopes.
- You should be redirected back to the personal settings page with
  `?googleToken=success` and the username should display.

If you hit issue tracker bug #44 ("Failed to save Google admin options:
Request failed with status code 500"), it's almost always a
`PasswordConfirmationRequired` timeout — try re-entering admin password and
saving again.

Done? [ ]

### 7. Notes / surprises / things to capture

Use this space to record anything the docs above missed. Common things to
note:

- Whether the consent screen flow worked in one go or needed retries.
- Whether the redirect landed back at NC successfully.
- Whether the scopes shown on the Google consent screen matched what you
  expected.
- Whether you saw any "blocked sign-in attempt" emails from Google.

```
[fill in]
```

## Where the secrets actually live in Nextcloud

For reference (these are what the migration in `lib/Migration/Version03001001Date20241111105515.php` set up):

| Secret                   | Storage                              | Encrypted? |
| ------------------------ | ------------------------------------ | ---------- |
| Google client_id         | `oc_appconfig` key `client_id`       | yes (ICrypto) |
| Google client_secret     | `oc_appconfig` key `client_secret`   | yes (ICrypto) |
| Per-user access token    | `oc_preferences` key `token`         | yes (ICrypto) |
| Per-user refresh token   | `oc_preferences` key `refresh_token` | yes (ICrypto) |
| Per-user expires_at      | `oc_preferences` key `token_expires_at` | no (timestamp) |
| Per-user user_id/name    | `oc_preferences`                     | no |
| Per-user granted scopes  | `oc_preferences` key `user_scopes`   | no (JSON) |

To rotate secrets later, the easiest path is **Disconnect** in the personal
settings UI (which deletes the user values) or re-enter `client_id` /
`client_secret` as admin (which overwrites the appconfig values).
