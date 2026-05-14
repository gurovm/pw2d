# Spec 016 Performance Audit — 2026-05-14

## Verdict
Minor concerns. No blockers. Two items merit operational awareness (GSC quota multiplier; future-scale index hint), one cosmetic refactor.

## Critical (would degrade prod)
None.

## High (degrades under growth)

- **`PullSeoMetrics::execute()` GSC call multiplier — external API quota risk.** `app/Actions/Seo/PullSeoMetrics.php:48-64`
  - Current behavior: with default `--gsc-window-days=4`, each tenant triggers 4 GSC API calls per nightly run (was 1 pre-Spec 016). A separate upsert runs per date (4 upserts/tenant — each cheap because of the `uniq_tenant_source_urlhash_date` index, idempotent.)
  - Cost projection at N tenants:
    - N=10: 10 → 40 GSC calls/night. Trivial.
    - N=50: 50 → 200 GSC calls/night. Still inside Google's typical default project quota (1,200 queries / minute / project; daily soft limits much larger), but it now sequentially issues bursts.
    - N=200: 200 → 800 GSC calls/night. Approaches the bursty-throughput end if the loop is tight (no jitter / backoff).
    - N=1000: 1000 → 4000 GSC calls/night. Would need explicit quota management.
  - Fix / threshold trigger: at N > ~100 tenants, add (a) a `usleep`/jitter between GSC calls inside the loop, or (b) call `searchanalytics.query` with a `startDate`/`endDate` range covering the full 4-day window in a single request and bucket results client-side. Option (b) cuts API calls 4x and is the right long-term solution.
  - Note: no shared retry-with-backoff visible at this layer; relies on whatever `PullGscMetrics` does internally.

## Medium (worth a follow-up)

- **`SeoStatusCommand::fetchAggregates()` — `whereIn` redundant when no tenant filter is in play.** `app/Console/Commands/Seo/SeoStatusCommand.php:223-232`
  - For the common case (`pw2d:seo:status` with no tenant arg), `$tenantIds` contains every tenant in the DB. `whereIn('tenant_id', $tenantIds)` then forces MySQL to check each row against the IN list, defeating cheap range scanning.
  - At ~10–100 tenants this is invisible. At 1000+ tenants, dropping the `whereIn` in the "all tenants" branch (only adding it when a single tenant is requested) lets the optimizer pick a clean range scan over `idx_tenant_source_date`.
  - Fix: branch on `$tenantArg`. When null, omit `whereIn`. When set, keep it. Trigger threshold: ~500 tenants or when `EXPLAIN` shows `range` → `ref` regression.

- **Single grouped query reads full 14-day index range from disk for the windowed count.** `app/Console/Commands/Seo/SeoStatusCommand.php:223-232`
  - The `SUM(CASE WHEN metric_date >= ? THEN 1 ELSE 0 END)` is applied across **all** rows per `(tenant_id, source)` group — not just the windowed slice. The optimizer cannot push the date predicate into the index scan; it must scan every row in every `(tenant_id, source)` partition of `idx_tenant_source_date`.
  - Current cost at 100k rows: ~100k row evaluations. Likely <100ms on warm MySQL. Acceptable.
  - Projected cost at 1M rows / 5-year retention: ~1M row evaluations per status invocation. Still fine for an on-demand admin command (target: <1s), but if status is ever wired into a per-minute health probe, switch to two grouped subqueries:
    ```sql
    SELECT a.tenant_id, a.source, a.max_date, COALESCE(b.windowed_count, 0)
    FROM (
      SELECT tenant_id, source, MAX(metric_date) AS max_date
      FROM seo_metrics GROUP BY tenant_id, source
    ) a
    LEFT JOIN (
      SELECT tenant_id, source, COUNT(*) AS windowed_count
      FROM seo_metrics WHERE metric_date >= ?
      GROUP BY tenant_id, source
    ) b USING (tenant_id, source);
    ```
    The `b` subquery uses `idx_tenant_source_date` as a true range scan; only the windowed slice is touched.
  - Trigger threshold: row count >5M, or status command becomes a hot path.

## Future considerations

- **Memory at 1000+ tenants.** `app/Console/Commands/Seo/SeoStatusCommand.php:69, 199`
  - `Tenant::all()` plus `pluck('id')` plus the aggregates collection holds (2 × tenant_count) rows. At 1000 tenants → 2000 small rows. At 10,000 tenants → 20,000 rows. Still well under memory limits but flagged so it isn't forgotten when tenant growth hits 4 digits.
  - Threshold: >5000 tenants → switch to `Tenant::cursor()` and stream the aggregates result rather than materializing.

- **`PullSeoMetricsCommand::resolveTenants()` loads ALL tenants then filters in PHP.** `app/Console/Commands/Seo/PullSeoMetricsCommand.php:229-235`
  - Out of strict Spec 016 scope (pre-existing pattern, retained), but: `Tenant::all()->filter(...)` cannot push the `seo_enabled` filter to MySQL because it lives in the `data` JSON column behind `VirtualColumn`. At 100 tenants this is fine; at 1000+ tenants on the nightly cron path, a JSON-path WHERE (`whereRaw("JSON_EXTRACT(data, '$.seo_enabled') = true")`) or a materialized `seo_enabled` column would let MySQL filter server-side.
  - Trigger threshold: nightly cron tenant-resolution step takes >200ms. Note as F23 if/when that happens.

- **Status output uses Carbon date diff per row.** `app/Console/Commands/Seo/SeoStatusCommand.php:267-268`
  - `createFromFormat` + `diffInDays` per (tenant × source). At 1000 tenants × 2 sources = 2000 Carbon constructions. Negligible (~10ms). Worth noting only if status moves to a hot path.

## What I verified is OK

- **Tenancy init lives outside the per-date loop.** `app/Actions/Seo/PullSeoMetrics.php:37, 48-83, 84-86` — `tenancy()->initialize()` runs once before the GSC loop; both loops (GSC + GA4) execute inside the single tenancy scope; `tenancy()->end()` is in `finally`. Verified the child `PullGscMetrics` / `PullGa4Metrics` do NOT reinit tenancy (per their docblock at line 16 of each). No per-date tenancy churn.
- **GA4 stays single-call by default.** Command default `--ga4-window-days=1`, `buildDateWindows()` returns a 1-element array (`PullSeoMetricsCommand.php:30, 161, 169-172`). No accidental fan-out.
- **Index used by status query.** `idx_tenant_source_date (tenant_id, source, metric_date)` (migration line 42) covers the `GROUP BY tenant_id, source` with `MAX(metric_date)` — MySQL 8 can do a loose-index scan on the leading two columns and a covering `MAX` on the third. Confirmed correct index choice.
- **`metric_date >= ?` is sargable.** No `DATEDIFF(NOW(), metric_date)` anti-pattern; the comparison is a direct column-to-literal predicate (`SeoStatusCommand.php:228`).
- **Service-account `is_readable()` is called once.** `SeoStatusCommand.php:174`, called from `reportCredentialStatus()` which runs once at top of `handle()` (line 57). Not inside any loop.
- **No N+1 in the tenant output loop.** Aggregates pre-fetched once (`fetchAggregates`, line 72); the per-tenant loop reads from the in-memory grouped collection via `$aggregates->get($tenantId, ...)` and `$tenantAgg->firstWhere('source', $source)`. No queries inside the loop. Confirmed.
- **No inappropriate caching introduced.** Status command is fully live — no `Cache::remember()` calls that could mask stale state. Correct for an admin diagnostic.
- **Idempotent re-pulls are free.** `uniq_tenant_source_urlhash_date` (migration line 38) backs the upsert; repeating yesterday's GSC pull 4 nights in a row produces zero new rows and constant DB cost.
- **Error isolation works as specified.** Per-date `try`/`catch` inside the GSC loop (line 49-63) ensures one bad date doesn't abort the rest of the window or skip GA4.

## Quantified estimates

- **GSC API calls per night (was → now):**
  - N=10 tenants: 10 → **40** (4x). Fine.
  - N=50: 50 → **200** (4x). Fine.
  - N=200: 200 → **800** (4x). Add jitter if calls are tight.
  - N=1000: 1000 → **4000** (4x). Switch to range-based single-call GSC pull.

- **Status command grouped query cost:**
  - At 100k rows / 10 tenants: ~100k row evals across 20 `(tenant, source)` groups → **<50ms warm** with `idx_tenant_source_date`.
  - At 1M rows / 100 tenants: ~1M row evals across 200 groups → **~200–500ms warm**. Acceptable for on-demand admin use.
  - At 10M rows / 1000 tenants: ~10M row evals → **2–5s**. Refactor to two-subquery JOIN at this point.

- **Status command memory:** ~`(tenants × sources)` stdClass rows + tenant models. At 1000 tenants → ~2000 aggregate rows + 1000 tenant models → **<10MB**. Stream when tenants > 5000.

- **PullSeoMetricsCommand tenant filtering:** `Tenant::all()->filter(...)` loads all tenants then PHP-filters. At 1000 tenants → ~50ms. Push to DB at ~5000 tenants.

---

## 5-line summary
- Counts: 0 Critical · 1 High · 2 Medium · 3 Future-considerations · 8 verified-OK.
- Top concern #1: GSC API calls quadruple per tenant per night (1 → 4 with the new rolling window). At current tenant scale (<100) this is fine; at >200 tenants migrate to a single ranged GSC request covering the whole window to cut calls 4x.
- Top concern #2: `SUM(CASE WHEN metric_date >= ?)` in the status query scans the full `(tenant, source)` partition rather than just the windowed slice — invisible up to ~1M rows, but should be refactored to a two-subquery LEFT JOIN once `seo_metrics` exceeds ~5M rows or if the command is wired into automated alerting.
- All tenancy lifecycle, indexing, and N+1 concerns are clean: tenancy initializes once per tenant outside the date loops, the grouped query correctly uses `idx_tenant_source_date`, and no queries appear inside the tenant output loop.
- No blockers — the implementation is production-safe at present tenant scale; flagged items are growth-trigger items, not current defects.
