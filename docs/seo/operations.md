# SEO Operations Runbook

> On-call reference for the Pw2D SEO monitoring pipeline. See [Spec 014](../specs/014-seo-monitoring-integration.md) for architecture decisions and [Spec 016](../specs/016-seo-health-pass.md) for the health-pass additions covered here.

---

## 1. Pipeline Overview

Each night, a scheduled artisan command queries Google Search Console and Google Analytics 4 for every tenant that has `seo_enabled = true`, and writes the results into the `seo_metrics` table. The Filament dashboard at `/admin/seo` reads exclusively from that table — no live API calls happen in the browser.

```
┌──────────────────────────────────────────────────────────────────┐
│  Laravel Scheduler  (routes/console.php, 03:00 UTC daily)        │
└────────────────────────────┬─────────────────────────────────────┘
                             │  pw2d:seo:pull
                             ▼
                 ┌───────────────────────┐
                 │  PullSeoMetricsCommand │
                 │  resolves tenants with │
                 │  seo_enabled = true    │
                 └─────────┬─────────────┘
                           │  per tenant
              ┌────────────┴────────────┐
              ▼                         ▼
  ┌─────────────────────┐   ┌─────────────────────┐
  │ PullGscMetrics      │   │ PullGa4Metrics       │
  │ (4-day window*)     │   │ (1-day window*)      │
  └──────────┬──────────┘   └──────────┬───────────┘
             │ upsert                  │ upsert
             ▼                         ▼
  ┌─────────────────────────────────────────────────┐
  │              seo_metrics (MySQL)                │
  │  PK: (tenant_id, source, url_hash, metric_date) │
  └──────────────────────────┬──────────────────────┘
                             │
                             ▼
              ┌──────────────────────────┐
              │  Filament: /admin/seo    │
              │  5 widgets, read-only    │
              └──────────────────────────┘
```

\* Default window sizes introduced in Spec 016 to fix [F19](../tasks/todo.md). An explicit `--date=YYYY-MM-DD` (manual backfill) forces both windows to 1 day.

---

## 2. Daily Ops

### Schedule

The cron entry (from `routes/console.php`):

```php
Schedule::command('pw2d:seo:pull')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
```

- **03:00 UTC** — Google's GSC data for day D is typically finalized 1–2 hours after midnight UTC; 03:00 gives a comfortable buffer.
- `withoutOverlapping()` — if a previous run is still in progress (e.g. many tenants, slow API), the new invocation exits immediately without queueing. Prevents pile-up.
- `runInBackground()` — the scheduler process itself returns immediately; the command runs in a forked process. Required so the scheduler loop is not blocked by a slow API call.

### What "succeeded" means

The command returns exit code `0` (SUCCESS) only if **at least one tenant upserted at least one row without errors**. A run that processes zero tenants (all disabled) or produces only errors returns exit code `1` (FAILURE). Check supervisor/cron logs if the job fires but returns failure consistently.

### Log location

```
storage/logs/laravel.log
```

Each pull logs at `info` level with tenant ID and row counts. API errors log at `error` level with the tenant ID and source. Token/credential strings are never logged (see Spec 014 §Security).

To tail in real time on the production server:

```bash
tail -f /var/www/pw2d/storage/logs/laravel.log | grep -i seo
```

---

## 3. Checking Health — `pw2d:seo:status`

> This command is specified in [Spec 016 §5](../specs/016-seo-health-pass.md) and is being built in parallel. Do not attempt to run it until the spec 016 branch is merged and deployed.

```bash
php artisan pw2d:seo:status              # all tenants
php artisan pw2d:seo:status acme         # scope to one tenant
php artisan pw2d:seo:status --days=28    # extend history window for row counts
```

The command prints a per-tenant table and exits `0` if every configured tenant+source is HEALTHY, or `1` if any configured tenant+source is STALE, NO_DATA, or ERROR.

### Status definitions

| Status | Meaning |
|--------|---------|
| **HEALTHY** | Configured, data present, within freshness threshold (GSC: ≤5 days old; GA4: ≤2 days old) |
| **STALE** | Configured, data present, but latest row exceeds the freshness threshold |
| **NO_DATA** | Configured (`gsc_site_url` / `ga4_property_id` set), but zero rows in `seo_metrics` |
| **UNCONFIGURED** | `seo_enabled = false`, or the source's config key is empty |
| **ERROR** | Unexpected exception during status collection (e.g. DB unreachable) |

UNCONFIGURED never triggers a non-zero exit code — it is an active admin choice, not a failure.

### Sample outputs

**All HEALTHY (exit 0)**

```
Tenant: acme  (seo_enabled=YES)
  Service account JSON: ✓ /var/www/pw2d/storage/app/seo/google-service-account.json (readable)
  ┌────────┬──────────────┬─────────────┬─────────┬───────────────┬──────────┐
  │ Source │ Configured?  │ Latest date │ Age     │ Rows (14d)    │ Status   │
  ├────────┼──────────────┼─────────────┼─────────┼───────────────┼──────────┤
  │ GSC    │ ✓            │ 2026-05-10  │ 4 days  │ 1,247         │ HEALTHY  │
  │ GA4    │ ✓            │ 2026-05-13  │ 1 day   │   823         │ HEALTHY  │
  └────────┴──────────────┴─────────────┴─────────┴───────────────┴──────────┘

Summary: 1 HEALTHY · 0 STALE · 0 UNCONFIGURED · 0 NO_DATA · 0 ERROR
```

**GSC STALE (exit 1)**

```
Tenant: acme  (seo_enabled=YES)
  Service account JSON: ✓ /var/www/pw2d/storage/app/seo/google-service-account.json (readable)
  ┌────────┬──────────────┬─────────────┬──────────┬───────────────┬──────────┐
  │ Source │ Configured?  │ Latest date │ Age      │ Rows (14d)    │ Status   │
  ├────────┼──────────────┼─────────────┼──────────┼───────────────┼──────────┤
  │ GSC    │ ✓            │ 2026-05-04  │ 10 days  │    62         │ STALE    │
  │ GA4    │ ✓            │ 2026-05-13  │ 1 day    │   823         │ HEALTHY  │
  └────────┴──────────────┴─────────────┴──────────┴───────────────┴──────────┘

Summary: 1 HEALTHY · 1 STALE · 0 UNCONFIGURED · 0 NO_DATA · 0 ERROR
```

**Tenant UNCONFIGURED (exit 0)**

```
Tenant: pw2d  (seo_enabled=NO)
  → UNCONFIGURED (seo_enabled=false; skipping)

Summary: 0 HEALTHY · 0 STALE · 1 UNCONFIGURED · 0 NO_DATA · 0 ERROR
```

**Configured but NO_DATA (exit 1)**

```
Tenant: beta  (seo_enabled=YES)
  Service account JSON: ✓ /var/www/pw2d/storage/app/seo/google-service-account.json (readable)
  ┌────────┬──────────────┬─────────────┬─────────┬───────────────┬──────────┐
  │ Source │ Configured?  │ Latest date │ Age     │ Rows (14d)    │ Status   │
  ├────────┼──────────────┼─────────────┼─────────┼───────────────┼──────────┤
  │ GSC    │ ✓            │ —           │ —       │     0         │ NO_DATA  │
  │ GA4    │ ✓            │ —           │ —       │     0         │ NO_DATA  │
  └────────┴──────────────┴─────────────┴─────────┴───────────────┴──────────┘

Summary: 0 HEALTHY · 0 STALE · 0 UNCONFIGURED · 2 NO_DATA · 0 ERROR
```

---

## 4. Filament Dashboard Tour

Navigate to `/admin/{tenant}/seo` (the Filament route resolves to `/admin/seo` once a tenant is selected in the header dropdown).

> **F12** ([todo.md](../tasks/todo.md)): `ProblemProducts::getNavigationBadge()` uses a raw `REGEXP` SQL call that fails on SQLite. This blocks Filament admin HTTP tests against an SQLite test database. Two tests in `SeoDashboardTest` are currently skipped until F12 is resolved.

> **F13** ([todo.md](../tasks/todo.md)): There is no `view_seo_dashboard` gate. Any authenticated admin can access `/admin/seo`, which matches the access model of every other admin page in the project. A system-wide gate infrastructure is needed before this can be restricted.

### Widgets

| Widget | Class | Purpose |
|--------|-------|---------|
| **KPI Cards** | `KpiCardsWidget` | Last-28-day totals for GSC clicks, impressions, avg position, GA4 sessions, and conversions. Each card shows a delta vs the prior 28-day window. |
| **Top Movers** | `TopMoversWidget` | Up to 20 URLs with the biggest absolute GSC position change (7d vs prior 7d). Negative delta = improved rank. |
| **URL Coverage** | `UrlCoverageWidget` | Compares the sitemap URL set against URLs that have appeared in GSC. Shows counts for: in-sitemap-with-data, in-sitemap-without-data, and indexed-but-not-in-sitemap. |
| **Query Explorer** | `QueryExplorerWidget` | 28-day line chart of daily GSC impressions + clicks. Use the filter dropdown to narrow by page type (`/compare/*`, `/product/*`, etc.). |
| **Page Type Breakdown** | `PageTypeBreakdownWidget` | Buckets all GSC rows into Home / Category / Preset / Product / Other and shows impressions, clicks, and avg position per bucket. |

All widgets read from `seo_metrics` only — no live API calls are made from the browser.

**F8, F9** ([todo.md](../tasks/todo.md)): `TopMoversWidget` and `PageTypeBreakdownWidget` are currently implemented as `StatsOverviewWidget` cards rather than proper paginated table widgets. This is a known limitation tracked as F8 and F9 respectively.

**F10** ([todo.md](../tasks/todo.md)): `QueryExplorerWidget` uses a fixed dropdown for URL prefix filtering rather than a free-text input. Arbitrary prefix filtering is tracked as F10.

---

## 5. Manual Backfill

### Pull all enabled tenants, default window (nightly equivalent)

```bash
php artisan pw2d:seo:pull
```

Pulls GSC with a 4-day window and GA4 for yesterday only. Upserts are idempotent — safe to re-run.

### Pull a single tenant, single date (both sources)

```bash
php artisan pw2d:seo:pull acme --date=2026-04-01
```

When `--date=YYYY-MM-DD` is specified explicitly and neither `--gsc-window-days` nor `--ga4-window-days` is passed, both windows default to 1 (backward-compatible single-date pull). The named tenant argument bypasses the `seo_enabled` check — useful for testing a newly onboarded tenant before enabling nightly pulls.

### Pull a wider GSC backfill window

```bash
php artisan pw2d:seo:pull acme --gsc-window-days=14
```

Pulls 14 days of GSC data ending at yesterday, plus 1 day of GA4. Use this after a credential outage or when first onboarding a tenant with existing GSC history. GA4 has no data lag, so widening its window is rarely useful.

### Pull only GSC (skip GA4 this run)

```bash
php artisan pw2d:seo:pull acme --gsc-window-days=14 --ga4-window-days=1
```

There is no explicit "skip GA4" flag. Use `--ga4-window-days=1` — GA4 upserts for the same day are idempotent and cheap.

### Verbose output (per-date breakdown)

```bash
php artisan pw2d:seo:pull acme -v
```

With `--verbose`, the command prints per-date upsert counts for each source in addition to the standard summary table.

---

## 6. Tenant Onboarding

### Step-by-step in Filament

1. Log in at `https://pw2d.com/admin`.
2. In the sidebar: **Multi-Tenancy → Niche Sites**.
3. Click **Edit** on the target tenant.
4. Scroll to the **SEO** section.
5. Fill in:
   - **GSC Site URL** (`gsc_site_url`)
   - **GA4 Property ID** (`ga4_property_id`)
6. Toggle **Enable nightly SEO pull** to ON.
7. Click **Save**.

### Field reference

| Field | Filament label | Expected format | Notes |
|-------|---------------|-----------------|-------|
| `gsc_site_url` | GSC Site URL | `sc-domain:example.com` OR `https://example.com/` | Must match exactly what Google Search Console shows. Domain properties use the `sc-domain:` prefix. URL-prefix properties require the `https://` scheme and a trailing slash. A mismatch means zero rows returned. |
| `ga4_property_id` | GA4 Property ID | Numeric string, e.g. `123456789` | Do **not** include the `properties/` prefix — the service constructs the full path internally. Find the ID in GA4 Admin → Property Settings. |
| `seo_enabled` | Enable nightly SEO pull | boolean toggle | When OFF, the tenant is excluded from nightly `pw2d:seo:pull` runs (but you can still pull it manually by passing the tenant ID as an argument). |

### Service account access setup

A single GCP service account handles all tenants. Its JSON key lives at the path set by `SEO_GOOGLE_SA_PATH` in `.env` (default: `storage/app/seo/google-service-account.json`).

For each new tenant you must manually grant the service account access in both products:

**Google Search Console**

In the Google Search Console web UI, go to the property for the new tenant, then navigate to **Settings → Users and permissions → Add user**. Enter the service account email address (visible in the JSON key file as the `client_email` field) and assign **Restricted** permission.

**Google Analytics 4**

In the GA4 web UI, go to **Admin → Property Access Management → Add**. Enter the same service account email and assign the **Viewer** role.

There is no API-based way to grant this access from within Pw2D — it must be done manually in the Google web consoles.

After granting access, run a manual pull to verify:

```bash
php artisan pw2d:seo:pull {tenant_id}
```

Then check `pw2d:seo:status {tenant_id}` to confirm both sources show HEALTHY or at least NO_DATA (which means credentials work but Google has no data yet).

---

## 7. Common Failure Modes

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `pw2d:seo:status` shows **UNCONFIGURED** for a tenant | `seo_enabled = false`, or `gsc_site_url` / `ga4_property_id` is blank | Fill in the fields in Filament → Niche Sites → Edit → SEO section, toggle Enable on |
| `pw2d:seo:status` shows **STALE for GSC only** | Service account not granted GSC access, or `gsc_site_url` does not match the property exactly | Confirm the service account email is listed in GSC → Settings → Users and permissions; check for `sc-domain:` vs `https://` mismatch; verify `--gsc-window-days=4` (default) covers Google's 2–3 day lag |
| `pw2d:seo:status` shows **STALE for GA4 only** | Service account not granted GA4 Viewer access, or wrong `ga4_property_id` | Add the service account in GA4 Admin → Property Access Management; double-check the property ID (numbers only, no `properties/` prefix) |
| `pw2d:seo:status` shows **NO_DATA** after onboarding | Credentials or config wrong; nightly pull has not yet succeeded | Check `config('seo.google.service_account_path')` path is readable (`ls -la` on server); check `gsc_site_url` / `ga4_property_id` in Filament; tail `storage/logs/laravel.log` during a manual pull; confirm service account has access in both Google consoles |
| Manual pull exits with an error about the service account JSON | JSON key file missing or not readable by `www-data` | Ensure the file exists at the configured path; `chmod 600` + `chown www-data:www-data` the file |
| Manual pull exits 1, zero rows upserted, no error messages | GSC returns empty for the date range (common for brand-new properties or dates before the site launched) | Normal — GSC needs time to accumulate data. If a tenant has been live for >7 days and still returns zero, double-check the `gsc_site_url` format and confirm site ownership is verified in GSC |
| Cron not firing | Supervisor not running, or scheduler process crashed | SSH to server, run `php artisan schedule:list` to verify the cron is registered; check `sudo supervisorctl status`; ensure a system cron runs `php artisan schedule:run` every minute |
| Filament dashboard shows no data but `seo_metrics` has rows | Wrong tenant selected in Filament header dropdown | Switch to the correct tenant using the dropdown in the Filament top bar |
| Dashboard widgets blank or spinning indefinitely | F12: SQLite REGEXP issue (test environments only) | In production (MySQL) this should not occur. In test environments, the `ProblemProducts` navigation badge query uses `REGEXP` which SQLite does not support. See [F12](../tasks/todo.md). |

---

## 8. Cron Config

The full entry from `routes/console.php`:

```php
// Pull SEO metrics (GSC + GA4) nightly at 03:00 for all enabled tenants.
// Data for day D is available from Google ~1–2 hours after midnight UTC,
// so 03:00 gives a comfortable buffer. withoutOverlapping() prevents pile-ups
// if a previous run is still in progress (e.g. a large number of tenants).
Schedule::command('pw2d:seo:pull')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
```

**`withoutOverlapping()`** — acquires a cache lock before running. If the lock is already held (previous run still in progress), the new invocation exits immediately. The lock TTL defaults to 24 hours, so a crashed run that never released the lock will self-clear the next day. To manually release a stuck lock:

```bash
php artisan cache:forget schedule-pw2d:seo:pull
```

**`runInBackground()`** — forks the command as a background process. The scheduler loop returns control immediately and can fire other scheduled jobs on time. Without this, a slow multi-tenant pull would delay every other scheduled command.

### Verifying the schedule is registered

```bash
php artisan schedule:list
```

You should see `pw2d:seo:pull` listed with a `03:00` next-run time and `Without overlapping` noted.

### Supervisor / system cron prerequisite

The Laravel scheduler itself must be invoked every minute by a system-level cron. On the production server:

```
* * * * * www-data php /var/www/pw2d/artisan schedule:run >> /dev/null 2>&1
```

Confirm this entry exists:

```bash
sudo crontab -l -u www-data
```

If Supervisor manages queue workers, note that the **scheduler is not a queue worker** — it runs as a cron-invoked PHP process, not a long-running daemon.

---

## 9. Known Gotchas

| Ref | Gotcha | Detail |
|-----|--------|--------|
| [F15](../tasks/todo.md) | **GA4 timezone mismatch** | `GoogleAnalyticsService` passes dates in UTC. GSC operates in UTC (correct). GA4 operates in the property's configured timezone. For a property set to US/Pacific, "yesterday UTC" can be off by one day during the first 8 hours of a UTC day. Mitigation: set the GA4 property's reporting timezone to UTC in GA4 Admin → Property Settings → Reporting time zone. Fix tracked as F15. |
| [F17](../tasks/todo.md) | **Widget cross-tenant tests don't exercise widget code paths** | The isolation tests in `SeoDashboardTest` re-implement the widget's SQL inline rather than rendering the actual widget classes. A bug inside `KpiCardsWidget::getStats()` would not be caught by these tests. Fix tracked as F17. |
| [F18](../tasks/todo.md) | **GA4 fixture uses a synthetic shape** | `tests/Fixtures/Seo/ga4-sample-response.json` uses simplified array keys that differ from the real GA4 API response shape (`dimensionValues[{value:...}]` / `metricValues[{value:...}]`). Do not use this file as a reference for the real API shape. Fix tracked as F18. |
| [F19](../tasks/todo.md) | **GSC nightly under-ingestion** (closed by Spec 016) | The original Spec 014 cron used `--date=yesterday`, which almost always returned zero GSC rows because GSC has a 2–3 day data lag. Spec 016 fixes this by switching to a rolling 4-day GSC window. GA4 retains a 1-day window (no lag). |
| [F20](../tasks/todo.md) | **Live API paths have no test coverage** | `GoogleSearchConsoleService::makeClient()` and `GoogleAnalyticsService::makeClient()` and their fetch methods are only tested with mocked/fixture data. Bugs in the actual Google SDK interaction go undetected until a real production run. The `pw2d:seo:status` command (Spec 016) is a partial mitigation — it surfaces stale/missing data — but does not exercise the SDK code paths. Full fix (recorded-response integration tests or a `pw2d:seo:test-connection` smoke command) is tracked as F20. |
