# Spec 016: SEO Health Pass

**Status:** Draft (architect handoff)
**Authors:** @architect (2026-05-14)
**Depends on:** Spec 014 (shipped)
**Closes:** F19 (cron under-ingestion), partial F20 (live-API smoke), new ops doc
**Branch suggestion:** `feat/seo-health-pass`

---

## 1. Motivation

Spec 014 (`docs/specs/014-seo-monitoring-integration.md`) shipped a nightly SEO pipeline, but a real-world run on 2026-04-11 surfaced two operational gaps:

1. **F19 — Cron under-ingests GSC every night.** Google Search Console has a 2–3 day data lag. The nightly cron defaults to `--date=yesterday`, which almost always returns **0 GSC rows**. GA4 has no lag, so its "yesterday" pull is fine. Net effect: GSC table grows in fits and starts (only when admins manually run dated backfills) instead of nightly.
2. **F20-adjacent — Zero operational visibility.** There is no single command an admin can run to answer "is SEO healthy?". Status today is inferred by opening `/admin/seo` per tenant and squinting at row counts — and even that doesn't reveal credential issues, missing tenant config, or whether the cron has fired recently.

This spec closes both gaps with a small, targeted change.

---

## 2. Goals (in scope)

1. **Fix F19**: nightly cron must pull a rolling **4-day GSC window** so GSC data is captured as soon as Google publishes it. Idempotent upserts make repeat-day pulls free.
2. **Ship `pw2d:seo:status`** — a read-only diagnostic artisan command that prints a per-tenant health table. Single source of truth for "is SEO healthy?".
3. **Ship `docs/seo/operations.md`** — operational runbook covering the dashboard, the status command, manual backfills, and common failure modes.

## 3. Non-goals (out of scope)

- F20 full fix (live-API integration tests for `makeClient()`/`runReport()`). The status command is a partial mitigation by surfacing real-world health, but recorded-response tests stay open.
- F15 (GA4 timezone mismatch).
- Filament gate (`view_seo_dashboard`, F13).
- F7 (GSC per-URL top_query lookup).
- Any new dashboard widgets (F8/F9/F10).

---

## 4. Component A — F19 GSC backfill window

### 4.1 Behavior

| Flag | Default | Effect |
|---|---|---|
| `--date=yesterday` (default) | — | GSC pulls a window of 4 dates: `[yesterday, 2d ago, 3d ago, 4d ago]`. GA4 pulls **just yesterday**. |
| `--date=today` | — | Same window logic relative to today: GSC `[today, 1d ago, 2d ago, 3d ago]`, GA4 `[today]`. |
| `--date=2026-04-01` (explicit YYYY-MM-DD) | — | Backward-compatible: both GSC and GA4 pull **only that single date** (manual backfill use case). |
| `--gsc-window-days=N` | 4 | Override the GSC window size. Applies even with explicit `--date=YYYY-MM-DD`. |
| `--ga4-window-days=N` | 1 | Override the GA4 window size. Rare; GA4 has no lag. |

**Why a window and not just "3 days ago"?** Each night, the cron picks up whichever GSC days are now available. Days already in `seo_metrics` get upserted (no-op cost). Days that finalize over the next 1–3 nights are eventually captured. Self-healing, no missed days.

### 4.2 Files to modify

#### `app/Actions/Seo/PullSeoMetrics.php`

**Current signature:**
```php
public function execute(Tenant $tenant, CarbonImmutable $date): PullSeoMetricsResult
```

**New signature:**
```php
/**
 * @param array<int, CarbonImmutable> $gscDates Dates to pull for GSC. Each is upserted.
 * @param array<int, CarbonImmutable> $ga4Dates Dates to pull for GA4. Each is upserted.
 */
public function execute(Tenant $tenant, array $gscDates, array $ga4Dates): PullSeoMetricsResult
```

Internals:
- Initialize tenancy once.
- Loop `$gscDates` calling `(new PullGscMetrics)->execute($tenant, $date)` — aggregate upsert counts and errors. Track per-date counts for the new `gscDailyCounts` field on the result.
- Same for GA4 with `$ga4Dates`.
- `finally` block still ends tenancy.
- If GSC fails for date D, log it and continue with date D+1 — partial GSC days should not block other dates or GA4.

#### `app/Actions/Seo/PullSeoMetricsResult.php`

Add two new public readonly properties (default to empty arrays for backward compat):

```php
/** @param array<string, int> $gscDailyCounts metric_date string → rows upserted that day */
/** @param array<string, int> $ga4DailyCounts metric_date string → rows upserted that day */
public function __construct(
    public string $tenantId,
    public CarbonImmutable $date,        // ← keep for backward compat; set to MAX of all gsc/ga4 dates
    public int $gscRowsUpserted,
    public int $ga4RowsUpserted,
    public array $errors,
    public array $gscDailyCounts = [],
    public array $ga4DailyCounts = [],
) {}
```

`$date` semantics shift slightly — it now represents the latest date in the pull window. Existing tests/widgets that read `$result->date` still get a sensible value.

#### `app/Console/Commands/Seo/PullSeoMetricsCommand.php`

Add options:

```php
protected $signature = 'pw2d:seo:pull
                        {tenant? : Tenant ID — if omitted, runs for all tenants with seo_enabled=true}
                        {--date=yesterday : Anchor date: yesterday|today|YYYY-MM-DD}
                        {--gsc-window-days=4 : Number of days to pull for GSC ending at anchor date}
                        {--ga4-window-days=1 : Number of days to pull for GA4 ending at anchor date}';
```

Logic in `handle()`:

1. Resolve anchor date as today.
2. **If `--date=YYYY-MM-DD` was explicit AND neither window flag was passed,** force both windows to 1 (preserves manual single-date backfill behavior).
3. Otherwise use the resolved window-days options.
4. Build `$gscDates = [anchor, anchor-1d, …, anchor-(N-1)d]` and `$ga4Dates = […]` similarly.
5. Call `$action->execute($tenant, $gscDates, $ga4Dates)`.
6. Print the existing per-tenant table; if `verbose`, also print the per-date breakdown.

### 4.3 Tests

Modify `tests/Feature/Seo/Actions/PullSeoMetricsTest.php`:

- New signature test: `pulls each date in the gsc window and aggregates counts` — pass `[d1, d2, d3]` for GSC and `[d1]` for GA4, assert PullGscMetrics is called 3× with each date and PullGa4Metrics is called 1×.
- `gscDailyCounts populates per-date upsert counts`.
- `gsc failure for one date does not block other dates` — fake GSC service throws for d2, succeeds for d1/d3; assert d1 and d3 still upsert and errors array contains one entry.
- Backward-compat shim: existing single-date tests get one-element arrays.

Modify `tests/Feature/Seo/Commands/PullSeoMetricsCommandTest.php`:

- `--date=yesterday triggers 4-day gsc window and 1-day ga4 window` — assert `$action->execute()` receives 4-element gscDates and 1-element ga4Dates.
- `explicit --date=YYYY-MM-DD defaults both windows to 1` (backward compat).
- `--gsc-window-days=2 overrides default` — even with `--date=yesterday`, the GSC array has 2 elements.

---

## 5. Component B — `pw2d:seo:status` command

### 5.1 Signature & options

```
php artisan pw2d:seo:status
php artisan pw2d:seo:status acme              # scope to one tenant
php artisan pw2d:seo:status --days=28          # window for "rows in last N days" counts
```

```php
protected $signature = 'pw2d:seo:status
                        {tenant? : Optional tenant ID to scope the report to}
                        {--days=14 : Days of history to summarize per tenant}';
```

### 5.2 Output format

One section per tenant, then a summary banner. Per tenant:

```
Tenant: acme  (seo_enabled=YES)
  Service account JSON: ✓ /var/www/pw2d/storage/app/google/seo-service-account.json (readable)
  ┌────────┬──────────────┬─────────────┬─────────┬───────────────┬──────────┐
  │ Source │ Configured?  │ Latest date │ Age     │ Rows (14d)    │ Status   │
  ├────────┼──────────────┼─────────────┼─────────┼───────────────┼──────────┤
  │ GSC    │ ✓            │ 2026-05-10  │ 4 days  │ 1,247         │ HEALTHY  │
  │ GA4    │ ✓            │ 2026-05-13  │ 1 day   │   823         │ HEALTHY  │
  └────────┴──────────────┴─────────────┴─────────┴───────────────┴──────────┘

Tenant: pw2d  (seo_enabled=NO)
  → UNCONFIGURED (seo_enabled=false; skipping)

Summary: 3 HEALTHY · 1 STALE · 1 UNCONFIGURED · 0 NO_DATA · 0 ERROR
```

### 5.3 Health rules

| Status | Rule |
|---|---|
| **UNCONFIGURED** | `seo_enabled` is falsy OR the source's config key (`gsc_site_url` / `ga4_property_id`) is empty. Per-source granularity: a tenant may have HEALTHY GSC and UNCONFIGURED GA4. |
| **NO_DATA** | Configured, but 0 rows in `seo_metrics` for this tenant + source. |
| **STALE** | Latest row is older than: GSC: 5 days · GA4: 2 days. (GSC threshold accounts for the lag — 5d = 2d lag + 3d cron-firing slop.) |
| **HEALTHY** | Configured and within freshness threshold. |
| **ERROR** | Reserved for any unexpected exception during status collection (e.g. DB unreachable). |

Service-account credential check is **separate** from status (it's a system-level fact, not per-tenant), reported once at the top.

### 5.4 Exit codes

- `0` — every configured tenant×source is HEALTHY.
- `1` — at least one configured tenant×source is STALE / NO_DATA / ERROR.

UNCONFIGURED rows never trigger non-zero exit (they are an active admin choice). This lets the command be wired into a cron alert later without false alarms from explicitly-disabled tenants.

### 5.5 Files

- **New:** `app/Console/Commands/Seo/SeoStatusCommand.php`
- **New:** `tests/Feature/Seo/Commands/SeoStatusCommandTest.php`

### 5.6 Tests (Pest)

Use `RefreshDatabase`. Each test creates tenants + `SeoMetric` rows via factory, then asserts on `Artisan::call('pw2d:seo:status')` output and exit code.

1. **Healthy tenant returns 0 exit code** — tenant with both sources configured, latest GSC 2d ago + latest GA4 1d ago → exit 0, output contains "HEALTHY".
2. **Stale GSC returns 1 exit code** — GSC latest 10d ago → exit 1, output contains "STALE".
3. **Unconfigured tenant doesn't break exit code** — `seo_enabled=false` → output contains "UNCONFIGURED" but exit is still 0 (no other tenants present).
4. **NO_DATA when configured but zero rows** — tenant has gsc_site_url set but no SeoMetric rows → "NO_DATA" + exit 1.
5. **`--days=N` adjusts row count summary** — insert 5 rows in last 7 days, 10 rows in last 30 days; `--days=7` reports 5, `--days=30` reports 10.
6. **Single-tenant filter works** — `pw2d:seo:status acme` only reports acme.
7. **Missing service account file is flagged** — point `config('seo.google.service_account_path')` to a nonexistent path → output contains a warning line at the top.
8. **Per-source independence** — tenant with healthy GSC but missing `ga4_property_id` → GSC=HEALTHY, GA4=UNCONFIGURED on the same tenant.

---

## 6. Component C — `docs/seo/operations.md`

A 200–400 line operational runbook. Owner: documenter agent.

Required sections:

1. **Pipeline overview** — one paragraph + a flow diagram (ASCII or mermaid) showing: cron → `pw2d:seo:pull` → tenants → GSC/GA4 services → `seo_metrics` → Filament dashboard.
2. **Daily ops** — what nightly cron does, when it fires, where logs live.
3. **Checking health** — how to read `pw2d:seo:status` output. Include sample outputs for HEALTHY, STALE, UNCONFIGURED, NO_DATA scenarios.
4. **Filament dashboard tour** — `/admin/seo` route, each widget's purpose, gotchas (F12 sqlite issue, F13 missing gate).
5. **Manual backfill** — `pw2d:seo:pull <tenant> --date=YYYY-MM-DD` runs single-date for both sources. To backfill a wider GSC window: `--gsc-window-days=14 --ga4-window-days=0` (note: 0 means skip GA4 entirely — confirm this is supported or use `--ga4-window-days=1` with idempotent upsert).
6. **Tenant onboarding** — how to wire a new tenant into SEO monitoring: Filament admin → Tenant edit → SEO section → fill `gsc_site_url`, `ga4_property_id`, toggle `seo_enabled`. Note that the GSC `siteUrl` must match exactly (including `sc-domain:` prefix vs `https://` prefix — link to Google docs).
7. **Common failure modes** — table format: symptom (status output) → likely cause → fix.
8. **Cron config** — exact entry in `routes/console.php`, what `withoutOverlapping()` does, supervisor/systemd reference.
9. **Known gotchas** — link to F15 (GA4 timezone), F19 (GSC lag — closed by this spec), F20 (live-API test gap).

Style: terse runbook, tables for failure modes, code blocks for commands. No marketing. The reader is an on-call admin at 2 AM.

---

## 7. Acceptance criteria

- [ ] All new tests pass (`php artisan test --filter='Seo'`).
- [ ] Existing Spec 014 tests still pass.
- [ ] `pw2d:seo:pull` (no args, no flags) runs locally and pulls the 4-day GSC window (visible in `--verbose` output).
- [ ] `pw2d:seo:pull acme --date=2026-04-01` (explicit single date) still pulls only that one day (backward compat).
- [ ] `pw2d:seo:status` runs locally and produces a readable table without errors against an empty DB and against a seeded DB.
- [ ] `docs/seo/operations.md` exists and renders cleanly on GitHub.
- [ ] `docs/tasks/todo.md` updated: F19 marked `[x]` with reference to this spec; F21 and F22 added.

---

## 8. Rollout

1. PR titled `feat(seo): health pass (status command + F19 backfill) — spec 016` against `main`.
2. Reviewer agent pass.
3. Squash-merge to `main`.
4. Deploy via `/deploy`.
5. On prod, run `php artisan pw2d:seo:status` — confirm the post-deploy snapshot. Expect at least one previously-stale GSC tenant to show fresh data within 1–2 nights.
6. Optional: wire `pw2d:seo:status` exit code into ops alerting (out of scope here).

## 9. File-level summary

| File | Action | Owner |
|---|---|---|
| `app/Console/Commands/Seo/SeoStatusCommand.php` | **CREATE** | builder |
| `app/Console/Commands/Seo/PullSeoMetricsCommand.php` | MODIFY | builder |
| `app/Actions/Seo/PullSeoMetrics.php` | MODIFY (signature change) | builder |
| `app/Actions/Seo/PullSeoMetricsResult.php` | MODIFY (add 2 fields) | builder |
| `tests/Feature/Seo/Commands/SeoStatusCommandTest.php` | **CREATE** | tester |
| `tests/Feature/Seo/Commands/PullSeoMetricsCommandTest.php` | MODIFY | tester |
| `tests/Feature/Seo/Actions/PullSeoMetricsTest.php` | MODIFY | tester |
| `docs/seo/operations.md` | **CREATE** | documenter |
| `docs/tasks/todo.md` | UPDATE (F19 status, add F21/F22) | architect |
