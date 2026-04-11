# SEO Phase 2a Performance Audit — 2026-04-10

## TL;DR

At pw2d's current scale (1 tenant, ~1,050 URLs, ~30K rows after 28 days) the widget queries themselves are fine — they all hit `idx_tenant_source_date` or `idx_tenant_date` cleanly and the aggregates run over single-digit-thousand row slices. The nightly pull is well-built: batched upserts, single API call per source, idempotent via the unique key. **There is one real blocker:** every widget on `/admin/seo` polls every 5 seconds because Filament's `CanPoll` trait defaults to `'5s'` and none of the 5 widgets override it. That turns a 5-widget dashboard into a continuous ~1 query/second per viewer against `seo_metrics`. Fix is a one-line override per widget. **Recommendation: APPROVE WITH FIXES.**

## Blockers (will hurt at current scale)

### B1 — All 5 widgets poll every 5 seconds

- **Location:** `app/Filament/Widgets/Seo/{KpiCardsWidget,TopMoversWidget,UrlCoverageWidget,QueryExplorerWidget,PageTypeBreakdownWidget}.php`
- **Evidence:** `vendor/filament/widgets/src/Concerns/CanPoll.php` sets `protected static ?string $pollingInterval = '5s'`. `StatsOverviewWidget` and `ChartWidget` both `use CanPoll`. None of the 5 new widgets override it. `$isLazy = true` only defers first render, not polling.
- **Impact:** While any admin has `/admin/seo` open, the dashboard fires ~5 widgets × every 5s = 1 DB-query-per-second baseline. `KpiCardsWidget` alone runs 4 range-aggregate queries per tick (16/min). `TopMoversWidget` runs 2 `GROUP BY url` aggregates per tick. `UrlCoverageWidget` re-runs the sitemap-builder every tick (`Category::all()`, `Product::where(...)` against the main store tables) — that is ~1,050 product rows + category/preset scans on an unrelated table every 5s. Dashboard data only changes once per day at 03:00.
- **Fix:** Add `protected static ?string $pollingInterval = null;` to each of the 5 widgets. Or set to `'5m'` for eventual-consistency refresh. Kills ~95% of widget query load.

## Medium-term concerns (will hurt at 10x scale)

### M1 — `TopMoversWidget` does in-PHP join

- **Location:** `app/Filament/Widgets/Seo/TopMoversWidget.php:42-82`
- **Current behavior:** Pulls full `GROUP BY url` result sets for both 7-day windows into PHP, then joins in memory and sorts by `ABS(delta)`.
- **Impact:** At current scale (~500 URLs × 2 windows = 1,000 rows into PHP) fine — both queries use `idx_tenant_source_date` for `WHERE` and do an index-order `GROUP BY url`. At 10× URLs (10K+) the in-PHP sort + join adds noticeable latency vs. a single `JOIN`.
- **Fix:** Rewrite as one SQL query — derived tables for `current` and `prior` joined on `url`, ordered by `ABS(delta) DESC LIMIT 20`. Not needed until URL count crosses ~5K.

### M2 — `UrlCoverageWidget` rebuilds sitemap URL set on every render

- **Location:** `app/Filament/Widgets/Seo/UrlCoverageWidget.php:94-133` (`buildSitemapUrlSet()`)
- **Current behavior:** Runs 3 unrelated queries (`Category::get()`, `Product::where(...)->get()`, `Preset::whereIn(...)->get()`) plus a `Category::doesntHave('children')->pluck('id')` NOT EXISTS subquery, independent of `seo_metrics`, on every dashboard render.
- **Impact:** Amplified by B1 polling. Even with B1 fixed, runs on every page visit. Wasteful since sitemap changes daily at most.
- **Fix:** `Cache::remember("seo_sitemap_urls_{$tenantId}", 3600, fn() => ...)` with 1-hour TTL. The `F11` refactor to extract `SitemapBuilder` is tracked separately; this caching fix is independent.

### M3 — `QueryExplorerWidget` LIKE filter not index-backed

- **Location:** `app/Filament/Widgets/Seo/QueryExplorerWidget.php:69`, migration `database/migrations/2026_04_11_130000_create_seo_metrics_table.php:17-19`
- **Current behavior:** `WHERE url LIKE 'prefix%'`. Only `url_hash` is indexed — `url` is not.
- **Impact:** At 30K rows, MySQL will use `idx_tenant_source_date` for the range scan and post-filter the `LIKE` — tolerable. At 800K rows (13mo retention) this becomes a ~30K-row filter per query.
- **Fix:** Either add prefix index `KEY idx_url_prefix (tenant_id, url(128))`, or — better — add a generated `page_type` enum column populated by `PullGscMetrics` using the same classification as `PageTypeBreakdownWidget`. Not needed for merge.

### M4 — `PullSeoMetricsCommand::resolveTenants()` inits tenancy just to read one JSON key

- **Location:** `app/Console/Commands/Seo/PullSeoMetricsCommand.php:141-148`
- **Current behavior:** `Tenant::all()` then `tenancy()->initialize()` + `tenancy()->end()` per tenant just to read `seo_enabled` from the JSON data bag.
- **Impact:** 1 tenant: irrelevant. 50 tenants: 50 tenancy init/teardown cycles before any work. Tenancy initialization is not free (DB reconnect, event dispatch).
- **Fix:** Read the JSON key directly via `$t->data['seo_enabled'] ?? false` without initializing tenancy. Not urgent.

## Non-issues at current scale

- **`PageTypeBreakdownWidget` regex bucketing** (`PageTypeBreakdownWidget.php:42-49`) pulls all 28-day rows into PHP and classifies in-process. Query uses `idx_tenant_source_date`. ~30K rows → ~5MB in PHP. Acceptable. At 10× scale, push bucket classification into SQL via `CASE WHEN` + `GROUP BY`.
- **`KpiCardsWidget` runs 4 separate queries** instead of one `CASE WHEN metric_date BETWEEN ... THEN ... END` aggregate (`KpiCardsWidget.php:35-76`). Each query is <5ms at current scale. Merging is a micro-optimization.
- **`google/apiclient` and `google/analytics-data` boot cost.** Both libraries autoload on-demand; nothing eagerly instantiated at command boot. Clients built inside the action's `try` block, not at command construction. Memory spike confined to the ~30s the command runs.
- **GSC API single-call with `row_limit=500` but pw2d has ~1,050 URLs.** Correctness/completeness issue (F7 territory — only top 500 URLs ingested), not performance. Adding pagination improves coverage, not speed. Out of scope for this audit.
- **GA4 `BetaAnalyticsDataClient` not closed on exception** (`GoogleAnalyticsService.php:70-71`). `close()` called after `runReport()` succeeds but skipped if it throws. Resource leak only in error paths of a nightly command — the process exits anyway. Note for cleanup.
- **Upserts properly batched** as single multi-row `INSERT ... ON DUPLICATE KEY UPDATE` (`PullGscMetrics.php:81-93`, `PullGa4Metrics.php:75-87`).

## What's solid

- **Indexing is well-chosen.** `idx_tenant_source_date` covers the hot path (all 5 widgets filter by `tenant_id + source + metric_date`). Unique constraint `(tenant_id, source, url_hash, metric_date)` correctly drives idempotent upserts.
- **All widget queries use `DB::table()`** rather than Eloquent. No hidden N+1s from model hydration or relation loading. Confirmed no `foreach ($x as $y) { $y->...->query() }` patterns in any widget.
- **Batched upsert pattern is textbook.** Single `DB::table('seo_metrics')->upsert($batch, ...)` call per source per tenant per day. At ~1,050 rows this is one round-trip vs. 2,100 with `updateOrCreate`.
- **Tenancy initialized once per tenant in `PullSeoMetrics::execute()`**, wrapped in `try/finally` so a mid-pull exception can't leak tenant context into subsequent work.
- **`PullGscMetrics` catches all `Throwable` and returns a `PullResult`** — GA4 still runs if GSC fails. Error isolation clean.
- **`$isLazy = true` on every widget** — first render deferred past Filament's initial page paint. Good UX choice even if it doesn't fix the polling issue.

## Merge recommendation

**APPROVE WITH FIXES.** One required change before merging:

1. **Disable polling on all 5 widgets** (B1). Add `protected static ?string $pollingInterval = null;` to each of `KpiCardsWidget`, `TopMoversWidget`, `UrlCoverageWidget`, `QueryExplorerWidget`, `PageTypeBreakdownWidget`. ~5 lines of code total.

M1–M4 are follow-ups that can ship in a subsequent PR.
