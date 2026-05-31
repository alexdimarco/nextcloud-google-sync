# Manual lab tests

These scripts are **not** part of the PHPUnit suite and **do not** run in CI. The
unit suite is pure (no Nextcloud bootstrap, no Sabre, no network); these cover the
paths that can only be exercised against a live Nextcloud with a connected Google
account — specifically the two Phase-4 recurrence failure paths that are reasoned
about in the design doc but need a real round-trip to prove.

They drive the real `OutboundRecurrenceService` / `OutboundReconcileService` through
a **fault-injecting `GoogleAPIService`** (an anonymous subclass overriding
`request()`), so the production services are exercised unmodified. Each script
creates a throwaway recurring event on a sacrificial calendar and deletes it on the
way out.

## Scripts

| Script | Verifies |
| --- | --- |
| `p4-transient-error-resume.php` | A transient `503` on one override PATCH **holds** the change token and leaves `nc_etag` at its pre-edit value, so the series re-classifies `LOCAL_EDIT` and the **next** reconcile RESUMES and converges (Google override reaches `ov v2`, inbound echo = 0). |
| `p4-budget-overflow.php` | The per-tick `instanceOpBudget()` circuit breaker, two scenarios: **(A)** the first N writes sync and the token **ADVANCES** (no wedge), but the overflow beyond N stays **one-way** — it does NOT resume without a fresh NC edit (no cursor); **(B)** write-only counting — two already-cancelled EXDATEs re-asserted on a later edit are free no-ops, so a new override **still syncs** under budget=2 (the old count-every-op behavior would have starved it). Documents, not endorses, the v1 limitation in `docs/RECURRENCE_OUTBOUND.md`. |

`p4-budget-overflow.php` lowers the breaker by subclassing the service and
overriding `instanceOpBudget()` to return `2`; production stays at the default
`100`. (That method is `protected` precisely so this test can lower it without
touching the source.)

## Running

Run inside the app container as the web user:

```sh
docker exec -u www-data <app-container> \
  php /var/www/html/custom_apps/outside_provider_calendar_bridge/tests/manual/p4-transient-error-resume.php
```

Environment overrides (defaults target the lab in `memory/lab-access.md`):

| Var | Default | Meaning |
| --- | --- | --- |
| `CB_LAB_CAL` | `dimarcotech@gmail.com` | Google calendar id (must be writable + two-way enabled) |
| `CB_LAB_USER` | `admin` | Nextcloud user id |
| `CB_LAB_NCCAL` | `3` | Nextcloud calendar id backing that Google calendar |

Each script prints `(expect ...)` annotations inline; eyeball them against the
actual output.
