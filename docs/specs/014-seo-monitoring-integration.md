# Spec 014: SEO Monitoring Integration (GSC + GA4)

## Goal

Give Pw2D the ability to **measure and monitor** per-tenant SEO health by pulling data from Google Search Console and Google Analytics 4 into a local `seo_metrics` table, and surfacing trends in a Filament admin dashboard.

**Strict prerequisite:** [Spec 015](./015-seo-brand-bleed-fix.md) must be merged and deployed first. Without Phase 1, metrics will capture the broken pre-fix state and muddy the before/after comparison.

## Scope Phases

- **Phase 2a (this spec):** GSC + GA4 nightly pull + Filament dashboard. Zero backfill — starts capturing from the day the command is scheduled.
- **Phase 2b (follow-up spec, not blocked):** PostHog integration once the project moves off the free plan (free tier's API is too limited for per-URL breakdowns anyway).
- **Phase 2c (follow-up spec, after 2-4 weeks of baseline data):** Regression alerting — nightly check compares week-over-week metrics and sends Slack/email when a URL's position drops by >X or impressions drop by >Y%. Can only set sensible thresholds once baseline noise floor is known.

## Non-Goals

- **Not building** an SEO *optimization* tool (keyword research, competitor crawl, etc).
- **Not replacing** GSC/GA4 dashboards — login still works. This is aggregated, filterable, tenant-scoped visibility inside Pw2D's own admin.
- **Not real-time** — all data is pulled on a nightly schedule. Latency: data for day D is available by D+1 morning.
- **Not writing** to GSC/GA4 — read-only.
- **No historical backfill.** Start from day-zero forward. Project is less than a month old; no meaningful history to recover.
- **No PostHog** in this phase. Deferred to Phase 2b.

## Current State

- [app/Support/SeoSchema.php](../../app/Support/SeoSchema.php) emits meta + JSON-LD for category / preset / product pages.
- [resources/views/components/layouts/app.blade.php](../../resources/views/components/layouts/app.blade.php) lines 47–105 conditionally loads PostHog + GA4 + GSC verification from tenant-scoped `Setting::get()` values.
- No code currently calls any SEO data APIs — all observation is manual (login to each console).
- Composer `google/apiclient` and `google/analytics-data` are **not yet installed**.

## Prerequisites (one-time setup outside this codebase)

Michael handles these before any code ships:

1. **Create a Google Cloud Project** named `pw2d-seo` (or reuse an existing project).
2. In that project, **enable these APIs**:
   - Google Search Console API
   - Google Analytics Data API (GA4)
3. **Create a service account** named `pw2d-seo-reader`. Download its JSON key → save as `storage/app/seo/google-service-account.json` on the production server (chmod 600, owned by `www-data`). Also keep a copy in 1Password / password manager.
4. **Grant the service account read access** in each tenant's GSC property:
   - GSC → Settings → Users and permissions → Add user → paste the service account email (`pw2d-seo-reader@pw2d-seo.iam.gserviceaccount.com`) → Restricted role.
5. **Grant the service account read access** in each tenant's GA4 property:
   - GA4 → Admin → Property Access Management → Add → paste service account email → Viewer role.
6. **Record the property IDs** for each tenant and fill them in via the Filament TenantResource SEO section (added by this spec):
   - `gsc_site_url` — format: `sc-domain:coffee2decide.com` (domain property) or `https://coffee2decide.com/` (URL prefix property, must match exactly including trailing slash)
   - `ga4_property_id` — format: `properties/123456789` — find in GA4 Admin → Property Settings.

## Design

### Architecture

```
┌──────────────────┐   ┌──────────────────┐
│ GSC API          │   │ GA4 Data API     │
└────────┬─────────┘   └────────┬─────────┘
         │                      │
         ▼                      ▼
  ┌──────────────┐       ┌──────────────┐
  │ GoogleSearch │       │ GoogleAnalyti│
  │ ConsoleServic│       │ csService    │
  └──────┬───────┘       └──────┬───────┘
         │                      │
         └──────────────┬───────┘
                        │
                        ▼
                  ┌───────────────────────────────┐
                  │ PullSeoMetrics Action         │
                  │ (per-tenant, per-day)         │
                  └──────────────┬────────────────┘
                                 │
                                 ▼
                         ┌───────────────┐
                         │ seo_metrics   │
                         │ (MySQL)       │
                         └───────┬───────┘
                                 │
                                 ▼
                  ┌───────────────────────────────┐
                  │ Filament: SeoDashboardPage    │
                  └───────────────────────────────┘
```

### File Structure

All code follows project conventions (Action Pattern, strict types, PHPDoc, Pest tests).

```
app/
├── Actions/
│   └── Seo/
│       ├── PullSeoMetrics.php          # Orchestrates a per-tenant pull
│       ├── PullGscMetrics.php          # Child action: GSC → DB
│       └── PullGa4Metrics.php          # Child action: GA4 → DB
├── Services/
│   └── Seo/
│       ├── GoogleSearchConsoleService.php
│       └── GoogleAnalyticsService.php
├── Console/Commands/
│   └── Seo/
│       └── PullSeoMetricsCommand.php   # pw2d:seo:pull {tenant?} {--date=}
├── Models/
│   └── SeoMetric.php
├── Filament/
│   └── Pages/
│       └── SeoDashboard.php            # Tenant-scoped dashboard
database/
└── migrations/
    └── 2026_04_XX_create_seo_metrics_table.php
config/
└── seo.php                             # Service account path, rate limits
tests/Feature/Seo/
    ├── PullGscMetricsTest.php
    ├── PullGa4MetricsTest.php
    ├── PullSeoMetricsTest.php
    └── SeoDashboardTest.php
```

## Database Schema

### New table: `seo_metrics`

Single wide table, partitioned by `(tenant_id, source, url, metric_date)`. All three sources write to it.

```sql
CREATE TABLE seo_metrics (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(255) NOT NULL,
    source           ENUM('gsc', 'ga4') NOT NULL,       -- 'posthog' added in Phase 2b
    url              VARCHAR(500) NOT NULL,
    url_hash         CHAR(64) NOT NULL,                  -- sha256(url) for indexed equality
    metric_date      DATE NOT NULL,

    -- GSC fields (nullable for other sources)
    gsc_impressions  INT UNSIGNED NULL,
    gsc_clicks       INT UNSIGNED NULL,
    gsc_ctr          DECIMAL(6,4) NULL,                  -- 0.0000 to 1.0000
    gsc_position     DECIMAL(6,2) NULL,                  -- average position
    gsc_top_query    VARCHAR(500) NULL,                  -- single highest-impression query

    -- GA4 fields
    ga4_sessions     INT UNSIGNED NULL,
    ga4_users        INT UNSIGNED NULL,
    ga4_engaged_sess INT UNSIGNED NULL,
    ga4_conversions  INT UNSIGNED NULL,
    ga4_bounce_rate  DECIMAL(6,4) NULL,

    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,

    UNIQUE KEY uniq_tenant_source_urlhash_date (tenant_id, source, url_hash, metric_date),
    KEY idx_tenant_date (tenant_id, metric_date),
    KEY idx_tenant_source_date (tenant_id, source, metric_date)
);
```

**Adding PostHog later:** the Phase 2b spec will `ALTER TABLE` to add `ph_pageviews`, `ph_uniq_visitors`, `ph_avg_duration` columns and widen the `source` enum. The shape of this table was chosen to make that extension trivial.

**Rationale for `url_hash`:** The `url` column is 500 chars (VARCHAR) and varies by query string (`?preset=`). A `CHAR(64)` sha256 column gives fast indexed equality for the unique constraint. This mirrors the pattern proposed in [docs/tasks/todo.md Q12](../tasks/todo.md) for `product_offers`.

**Retention:** Keep 13 months of data (enough for YoY comparison). Add a monthly pruning command later if table grows unbounded.

**Tenancy:** `tenant_id` is **explicit** on this table, not via `BelongsToTenant` scoping. The pull jobs run from the central scheduler against a specific tenant, so explicit `tenant_id` is clearer than initializing tenancy for each job.

### New tenant `data` JSON keys

Added to `tenants.data` via direct JSON updates (no migration needed — stancl/tenancy stores these as flexible JSON):

| Key | Type | Example | Used by |
|---|---|---|---|
| `gsc_site_url` | string | `sc-domain:coffee2decide.com` or `https://coffee2decide.com/` | `GoogleSearchConsoleService` |
| `ga4_property_id` | string | `properties/123456789` | `GoogleAnalyticsService` |
| `seo_enabled` | bool | `true` | Scheduler gating |

A single **service account JSON** for Google APIs lives at `storage/app/seo/google-service-account.json` (gitignored) and is shared across tenants. Each tenant grants that service account read access in their respective GSC + GA4 property. Path is configurable via `config/seo.php`.

## Service Contracts

### GoogleSearchConsoleService

```php
final class GoogleSearchConsoleService
{
    public function __construct(
        private readonly string $siteUrl,
        private readonly string $serviceAccountPath,
    ) {}

    /**
     * Fetch per-URL search performance for a single day.
     *
     * @return Collection<int, array{url: string, impressions: int, clicks: int, ctr: float, position: float, top_query: string|null}>
     */
    public function fetchUrlMetrics(CarbonImmutable $date): Collection;
}
```

### GoogleAnalyticsService

```php
final class GoogleAnalyticsService
{
    public function __construct(
        private readonly string $propertyId,
        private readonly string $serviceAccountPath,
    ) {}

    /**
     * Fetch per-landing-page metrics for a single day.
     *
     * @return Collection<int, array{url: string, sessions: int, users: int, engaged_sessions: int, conversions: int, bounce_rate: float}>
     */
    public function fetchLandingPageMetrics(CarbonImmutable $date): Collection;
}
```

Each service is instantiated **per-tenant** inside the pull action, reading config from `tenant('gsc_site_url')` etc. Services themselves are stateless and not singletons.

## Action: PullSeoMetrics

```php
final class PullSeoMetrics
{
    public function execute(Tenant $tenant, CarbonImmutable $date): PullSeoMetricsResult
    {
        tenancy()->initialize($tenant);

        try {
            $gsc = (new PullGscMetrics)->execute($tenant, $date);
            $ga4 = (new PullGa4Metrics)->execute($tenant, $date);
        } finally {
            tenancy()->end();
        }

        return new PullSeoMetricsResult(
            tenantId: $tenant->id,
            date: $date,
            gscRowsUpserted: $gsc->upserted,
            ga4RowsUpserted: $ga4->upserted,
            errors: [...$gsc->errors, ...$ga4->errors],
        );
    }
}
```

**Error isolation:** Each source pull is wrapped in try/catch. If GSC fails, GA4 still runs. Partial failures are logged + returned in `errors`, not thrown, so one broken tenant does not block the scheduler's other tenants.

**Idempotency:** `updateOrCreate` (or bulk `upsert()` via the unique constraint) — re-running for the same `(tenant, source, url_hash, date)` is safe.

## Command

```
php artisan pw2d:seo:pull [tenant?] [--date=yesterday]
```

- **No args**: pulls for all tenants where `tenant('seo_enabled') === true`, for yesterday.
- **Single tenant**: pulls for that tenant only.
- **`--date=`**: `yesterday` (default), `today`, or `YYYY-MM-DD`. A single arbitrary date only — no multi-day backfill. If you need to re-pull a missed day, run the command with that date explicitly.

Scheduled in `routes/console.php` at `03:00` daily.

## Filament Dashboard

### `SeoDashboard` page

- Route: `/admin/seo`
- Permission: `view_seo_dashboard` gate (same group as other admin pages)
- Tenant scoping: uses current Filament tenant (pw2d's admin is already tenant-scoped per existing patterns)

### Widgets

1. **KpiCards**: last-28-days totals vs previous 28 days for clicks, impressions, avg position, sessions, conversions.
2. **TopMovers**: 20 URLs with biggest position delta (absolute). Sortable.
3. **UrlCoverage**: how many URLs in the sitemap have any GSC data vs. how many have zero (= never indexed / never searched).
4. **QueryExplorer**: filter by URL prefix (e.g. `/compare/espresso-machines-grinders`), show last 28 days of impressions + clicks as a line chart.
5. **PageTypeBreakdown**: bucket URLs by pattern (`/`, `/compare/*`, `/compare/*?preset=*`, `/product/*`) and compare performance per type.

All widgets read from `seo_metrics` only — no live API calls from the dashboard.

## Configuration

### `config/seo.php` (new)

```php
return [
    'google' => [
        'service_account_path' => env('SEO_GOOGLE_SA_PATH', storage_path('app/seo/google-service-account.json')),
    ],
    'gsc' => [
        'rate_limit_per_minute' => 1200, // Google default
    ],
    'ga4' => [
        'rate_limit_per_minute' => 1200,
    ],
    'pull' => [
        'chunk_size' => 500, // URLs per API page
    ],
];
```

### `.env` additions

```
SEO_GOOGLE_SA_PATH=/var/www/pw2d/storage/app/seo/google-service-account.json
```

### Composer

```
composer require google/apiclient:^2.15 google/analytics-data:^0.21
```

Note: `google/apiclient` is heavy (~30MB). Consider `google/apiclient --prefer-dist --with-all-dependencies` and run `google-api-php-client-services` slimdown (not mandatory for this spec).

## Security

1. **Service account JSON**: stored in `storage/app/seo/` (gitignored), chmod 600, owned by `www-data`. Never logged. Never copied to dev environments unless Michael explicitly wants to run the pull locally — if so, use a *separate* dev service account with access only to a dev GSC/GA4 property.
2. **Rate limiting**: the command enforces a `usleep` between API calls to stay well under GSC's 1200 req/min limit.
3. **No cross-tenant leaks**: `seo_metrics` has explicit `tenant_id` filtering in every Filament widget query. Widget tests must cover this.
4. **Log sanitization**: when logging API errors, redact any tokens or full request URLs that may contain property IDs.

## Testing Requirements

Per CLAUDE.md "Testing Requirements" — Pest, `RefreshDatabase`, no mocks except external APIs.

### Unit / Feature Tests

1. **`PullGscMetricsTest`**:
   - Mocks `GoogleSearchConsoleService` to return a fixed payload
   - Asserts `seo_metrics` rows are upserted with correct tenant_id + source=gsc
   - Asserts re-running is idempotent (no duplicate rows)
   - Asserts partial failure (one URL errors) doesn't rollback the others

2. **`PullGa4MetricsTest`**: symmetric to GSC

3. **`PullSeoMetricsTest`**:
   - Mocks both child services
   - Asserts tenancy is initialized for the target tenant and ended afterwards
   - Asserts GSC failure does NOT block GA4
   - Asserts `seo_metrics` rows from multiple tenants stay cleanly separated

4. **`SeoDashboardTest`**:
   - Authenticated admin can view the dashboard
   - Widgets return ONLY current-tenant rows (seed 2 tenants with overlapping URLs, assert no leakage)
   - KPI deltas compute correctly against a seeded 56-day dataset

5. **`PullSeoMetricsCommandTest`**:
   - `--date=yesterday` picks yesterday in tenant timezone
   - `--date=2026-04-01` works
   - Invalid date format errors out cleanly

### Manual Verification

- Run against `coffee2decide` tenant with live credentials after service account is set up in GSC + GA4
- Compare DB row counts against numbers shown in the respective web dashboards (allow ~5% drift for sampling)

## Delivery Plan (task breakdown)

Each task goes to a sub-agent. Order matters where marked.

| # | Task | Agent | Depends on |
|---|---|---|---|
| T1 | Create migration + `SeoMetric` model with factory, PHPDoc | `builder` | — |
| T2 | Install composer packages, create `config/seo.php`, `.env.example` | `builder` | — |
| T3 | Write `GoogleSearchConsoleService` + tests (no live API — use fixture) | `builder` | T2 |
| T4 | Write `GoogleAnalyticsService` + tests | `builder` | T2 |
| T5 | Write `PullGscMetrics` + `PullGa4Metrics` actions + tests | `builder` | T1, T3, T4 |
| T6 | Write `PullSeoMetrics` orchestrator + `PullSeoMetricsCommand` + test | `builder` | T5 |
| T7 | Schedule command in `routes/console.php` at 03:00 daily | `builder` | T6 |
| T8 | Add tenant data JSON keys (`gsc_site_url`, `ga4_property_id`, `seo_enabled`) to Filament TenantResource SEO section | `frontend` | T1 |
| T9 | Build Filament `SeoDashboard` page + 5 widgets + tests | `frontend` | T1, T8 |
| T10 | Security audit of credential handling + log sanitization | `security` | T8 |
| T11 | Performance audit of widget queries (N+1, index usage) | `performance` | T9 |
| T12 | Reviewer pass on entire PR | `reviewer` | T1–T11 |
| T13 | Documenter pass: README section + PHPDoc completeness | `documenter` | T1–T11 |

**Execution strategy:** architect spawns T1 + T2 in parallel (background). T3 + T4 in parallel once T2 is done. T5 + T8 in parallel after T4. T6 → T7 sequential. T9 after T8. Final quality gates (T10–T13) in parallel.

## Success Criteria

- Scheduled job runs nightly without errors for 7 consecutive days
- `seo_metrics` has non-zero rows for every live tenant with `seo_enabled=true`
- Filament dashboard shows meaningful KPI deltas post-Phase-1-fix (expected: coffee2decide impressions climbing as Google re-indexes with correct branding)
- All Pest tests green, reviewer agent signs off, security agent signs off on credential handling

## Future Phases (not in this spec)

**Phase 2b — PostHog integration:** `PosthogService`, `PullPosthogMetrics` action, new columns on `seo_metrics`, dashboard widget showing pageviews vs GSC impressions (measures CTR accuracy vs actual behavior). Ships when Michael moves off PostHog free plan.

**Phase 2c — Regression alerting:** nightly check comparing current-week to prior-week metrics, sends Slack/email when:
- A URL's GSC position drops by more than N places (default 5)
- Impressions drop by more than X% week-over-week (default 50%)
- A previously-indexed URL disappears from GSC entirely
Baseline thresholds will be calibrated after 2–4 weeks of Phase 2a data so alerts aren't noisy. Small follow-up spec.

---

**Related:**
- [docs/seo/audit-2026-04-08.md](../seo/audit-2026-04-08.md) (audit findings)
- [docs/specs/015-seo-brand-bleed-fix.md](./015-seo-brand-bleed-fix.md) (Phase 1 — **must ship first**)
