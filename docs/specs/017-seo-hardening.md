# Spec 017: SEO Hardening (F23–F26)

**Status:** Draft (architect handoff)
**Authors:** @architect (2026-05-14)
**Depends on:** Spec 016 (shipped + deployed)
**Closes:** F23, F24, F25, F26
**Branch suggestion:** `feat/seo-hardening-017`

---

## 1. Motivation

Spec 016 shipped and immediately surfaced a 34-day silent failure (no system cron hook for `php artisan schedule:run`). The hook was installed manually, but several growth-trigger items and a real semantic wart remain from the audit. This spec bundles them into a single hardening pass.

**Honest framing:** F23 is **premature for the current scale (2 tenants on prod)** — the 4x GSC API call cost only matters at >200 tenants. Implementing it now adds complexity to a freshly-verified live path. The user explicitly asked for it; this spec includes it but flags the trade-off so reviewers can decide whether to merge or split it out.

F24, F25, F26 are all genuinely valuable now.

## 2. Goals

| ID | Change | Risk | Value at current scale |
|---|---|---|---|
| **F23** | Single ranged GSC call instead of N per-date calls | Medium (live API path) | Low (no current scale pain) |
| **F24a** | Drop `whereIn` in `fetchAggregates` when no tenant filter | Low | Low (invisible at <500 tenants) |
| **F24b** | Two-subquery LEFT JOIN for windowed count | Low | Low (invisible at <5M rows) |
| **F25** | Distinguish "no data" from "errors" in `pw2d:seo:pull` exit code | Low | **Medium** (eliminates false FAILURE on fresh installs / quiet days) |
| **F26** | Document cron hook in `/deploy` skill + ops runbook | Trivial | **High** (prevents next 34-day silent failure) |

---

## 3. F23 — Single ranged GSC call

### 3.1 Design

Today: `PullSeoMetrics::execute()` loops 4 dates, each call invokes `PullGscMetrics::execute($tenant, $date)`, each of those calls `GoogleSearchConsoleService::fetchUrlMetrics($date)` which issues one GSC API call. Result: 4 API calls per tenant.

New: One GSC API call for the full window. Add the `date` dimension to the request so the response contains per-(date, url) rows. Bucket in PHP.

### 3.2 Service changes — `app/Services/Seo/GoogleSearchConsoleService.php`

Add a new method (keep `fetchUrlMetrics()` for back-compat — `PullGscMetricsRange` is the only new caller):

```php
/**
 * Fetch per-(date, URL) GSC metrics for a date range in a single API call.
 *
 * Adds the 'date' dimension to the query so the response contains per-day
 * rows. Buckets the result into a date-keyed collection.
 *
 * @return Collection<string, Collection<int, array{url: string, ...}>>  keyed by Y-m-d
 */
public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
```

Implementation:
- Same as `fetchUrlMetrics()` but `dimensions = ['date', 'page']` and `startDate/endDate` are the bounds.
- Response row shape: `keys = [dateString, pageUrl]` — index 0 = date, index 1 = URL.
- Group rows by date string into a `Collection<string, Collection>`.
- Each inner collection has the same shape as the existing return type minus the date.

**Row-limit consideration:** `chunk_size` config (default 500) is the **per-response** GSC limit. With multiple dates, we may need pagination. The 4-day default × ~100 URLs/day = 400 rows, well under 500. For wider windows (35-day backfill = 3500+ rows), pagination is required: GSC returns rows in chunks, use `startRow` offset to page. **Implement pagination in the new method.**

### 3.3 Action changes — `app/Actions/Seo/PullGscMetrics.php`

Change signature from per-date to multi-date:

```php
// OLD:
public function execute(Tenant $tenant, CarbonImmutable $date): PullResult

// NEW:
/** @param array<int, CarbonImmutable> $dates */
public function execute(Tenant $tenant, array $dates): PullResult
```

Internally:
- Sort `$dates` ascending; use `$dates[0]` as startDate and `end($dates)` as endDate.
- Call `$service->fetchUrlMetricsForRange($startDate, $endDate)`.
- For each requested date, look up `$result->get($dateString, collect())` and upsert that bucket.
- Sum upserted counts across all dates; return `PullResult(upserted: $total, errors: [])`.
- Error handling: a single API failure means all dates fail. The catch block returns `PullResult::fromThrowable($e, $upserted)` — same as today, but the partial-success behavior (some dates succeed, others fail) is **lost** because the whole window is one API call. Document this trade-off.

### 3.4 Orchestrator changes — `app/Actions/Seo/PullSeoMetrics.php`

Replace the per-date GSC loop with a single call:

```php
// Old: foreach ($gscDates as $date) { (new PullGscMetrics)->execute($tenant, $date); ... }
// New:
$gscResult = (new PullGscMetrics)->execute($tenant, $gscDates);
$gscRowsUpserted = $gscResult->upserted;
// gscDailyCounts is now best-effort: we lose per-date granularity unless we
// re-derive it from the upsert response, which is feasible but adds complexity.
// Acceptable simplification: keep gscDailyCounts as empty array OR populate
// from the service response.
```

**Decision:** populate `gscDailyCounts` by having `PullGscMetrics` return a struct that includes per-date counts. This requires a minor `PullResult` extension OR a new `PullGscResult` type. Pick the cleaner path during build.

GA4 loop stays unchanged (GA4 has no lag → window of 1 is the default).

### 3.5 Tests

- `tests/Feature/Seo/Services/GoogleSearchConsoleServiceTest.php` (new or extended): test `fetchUrlMetricsForRange` returns date-keyed buckets given a multi-date response shape.
- `tests/Feature/Seo/Actions/PullGscMetricsTest.php`: migrate existing tests to the new array signature. Add a test for multi-date upsert + per-date count breakdown.
- `tests/Feature/Seo/Actions/PullSeoMetricsTest.php`: update — the GSC service is now called **once** per tenant, not 4×. Update the spy assertions accordingly.

---

## 4. F24 — `SeoStatusCommand::fetchAggregates` SQL polish

### 4.1 F24a — Drop `whereIn` when no tenant filter

`SeoStatusCommand.php:fetchAggregates()`:

```php
// New:
$query = DB::table('seo_metrics')
    ->selectRaw(...)
    ->groupBy('tenant_id', 'source');

if ($tenantArg !== null) {
    $query->whereIn('tenant_id', $tenantIds);
}

$rows = $query->get()->groupBy('tenant_id');
```

When no tenant arg is supplied, MySQL can do a clean range scan over `idx_tenant_source_date` without the IN-list lookup overhead.

### 4.2 F24b — Two-subquery LEFT JOIN

Replace the `SUM(CASE WHEN metric_date >= ?)` pattern with the two-subquery JOIN from the audit:

```sql
SELECT a.tenant_id, a.source, a.max_date, COALESCE(b.windowed_count, 0) AS rows_in_window
FROM (
  SELECT tenant_id, source, MAX(metric_date) AS max_date
  FROM seo_metrics
  [WHERE tenant_id IN (?)]    -- only when filtered
  GROUP BY tenant_id, source
) a
LEFT JOIN (
  SELECT tenant_id, source, COUNT(*) AS windowed_count
  FROM seo_metrics
  WHERE metric_date >= ?
  [AND tenant_id IN (?)]
  GROUP BY tenant_id, source
) b USING (tenant_id, source);
```

Implementation: use Laravel's `DB::table()->fromSub()` for the outer FROM and `joinSub()` for the LEFT JOIN. Keep the result row shape identical (no consumer changes needed in `handle()`).

### 4.3 Tests

- Existing `SeoStatusCommandTest` tests should pass unchanged (output is identical).
- Add a test that with no tenant filter, the query does NOT contain `IN` (use `DB::listen` to capture the SQL and assert on the string).

---

## 5. F25 — `pw2d:seo:pull` exit code semantics

### 5.1 Current behavior

`PullSeoMetricsCommand::handle()`:

```php
if ($result->totalUpserted() > 0 && ! $result->hasErrors()) {
    $anySucceeded = true;
}
...
return $anySucceeded ? self::SUCCESS : self::FAILURE;
```

Problem: A run where every tenant returns 0 rows with no errors exits FAILURE. On a fresh install (GSC lag → 0 rows for the first 3 nights), this looks like a failure every night.

### 5.2 New rule

| Scenario | Exit code |
|---|---|
| All tenants successful (any row counts including 0), no errors | **SUCCESS** |
| Any tenant had errors | **FAILURE** |
| No tenants matched (`resolveTenants()` returned empty) | **FAILURE** (preserved — config/CLI problem) |

```php
$anyErrors = false;
foreach ($tenants as $tenant) {
    $result = $action->execute(...);
    if ($result->hasErrors()) $anyErrors = true;
    // ... display table, warnings, etc.
}
return $anyErrors ? self::FAILURE : self::SUCCESS;
```

### 5.3 Tests

- Existing test `runs successfully for the default --date=yesterday and seo_enabled tenants` may need updating since it likely asserts specific upsert counts to drive SUCCESS. Update to assert SUCCESS even with 0 upserts when no errors occurred.
- Add new test: `zero_rows_no_errors_exits_success` — run against a tenant where both APIs return empty. Assert exit 0.
- Add new test: `errors_during_processing_exit_failure` — bind a fake that throws. Assert exit 1.

---

## 6. F26 — Document cron hook in `/deploy` + runbook

### 6.1 Update `/deploy` slash command

File: `.claude/commands/deploy.md` (or wherever the deploy command lives).

Add a new step **after** "Restart PHP-FPM" (step 8):

```
9. Verify Laravel scheduler cron hook is installed:
   `sudo -u www-data crontab -l | grep -q "schedule:run" || echo "MISSING: install with: echo '* * * * * cd /var/www/pw2d && php artisan schedule:run >> /dev/null 2>&1' | sudo -u www-data crontab -"`

   If the hook is missing, install it and explicitly tell the user.
```

This is idempotent — the grep is read-only. The installation echo is only suggested, not executed automatically (user invokes via /deploy → manual cron setup is a one-time per-server task, not per-deploy).

### 6.2 Update `docs/seo/operations.md` Common Failure Modes table

Add a new row:

| Symptom | Likely cause | Fix |
|---|---|---|
| `pw2d:seo:status` shows STALE on every source for every tenant; latest date never advances; manual `pw2d:seo:pull` works | **Laravel scheduler not hooked into system cron** — `schedule:list` shows the schedule registered, but nothing fires it. | Verify: `sudo -u www-data crontab -l` should contain `* * * * * cd /var/www/pw2d && php artisan schedule:run >> /dev/null 2>&1`. If empty, install it. |

Add a clarifying paragraph above the table noting that `schedule:list` shows **registration** of scheduled tasks, NOT that they are firing. The system cron entry is a separate prerequisite.

### 6.3 No code changes — pure docs

---

## 7. File-level summary

| File | Action | Spec section |
|---|---|---|
| `app/Services/Seo/GoogleSearchConsoleService.php` | MODIFY (add `fetchUrlMetricsForRange`) | §3.2 |
| `app/Actions/Seo/PullGscMetrics.php` | MODIFY (signature change) | §3.3 |
| `app/Actions/Seo/PullSeoMetrics.php` | MODIFY (call GSC once) | §3.4 |
| `app/Actions/Seo/PullResult.php` (or new `PullGscResult`) | MODIFY (per-date counts) | §3.4 |
| `app/Console/Commands/Seo/SeoStatusCommand.php` | MODIFY (`fetchAggregates`) | §4 |
| `app/Console/Commands/Seo/PullSeoMetricsCommand.php` | MODIFY (exit code) | §5 |
| `tests/Feature/Seo/Services/GoogleSearchConsoleServiceTest.php` | CREATE or MODIFY | §3.5 |
| `tests/Feature/Seo/Actions/PullGscMetricsTest.php` | MODIFY (signature migration) | §3.5 |
| `tests/Feature/Seo/Actions/PullSeoMetricsTest.php` | MODIFY (single GSC call) | §3.5 |
| `tests/Feature/Seo/Commands/SeoStatusCommandTest.php` | MODIFY (SQL shape assertion) | §4.3 |
| `tests/Feature/Seo/Commands/PullSeoMetricsCommandTest.php` | MODIFY (exit code tests) | §5.3 |
| `.claude/commands/deploy.md` | MODIFY (cron check step) | §6.1 |
| `docs/seo/operations.md` | MODIFY (failure mode + clarification) | §6.2 |
| `docs/tasks/todo.md` | UPDATE (mark F23-F26 done) | — |

## 8. Acceptance

- [ ] All new + migrated tests pass (`php artisan test --filter='Seo'`)
- [ ] Existing Spec 016 tests still pass
- [ ] `pw2d:seo:pull` against pw2d on prod (manual smoke) issues **1** GSC API call instead of 4 (verify in `storage/logs/laravel.log` or via packet count)
- [ ] `pw2d:seo:status` produces identical output before/after F24
- [ ] `pw2d:seo:pull tenant-with-no-data` returns exit 0 (not 1) when there are no errors
- [ ] `/deploy` skill prints the cron check step when invoked
- [ ] `docs/seo/operations.md` includes the new failure-mode row

## 9. Rollout

1. PR titled `feat(seo): hardening F23–F26 (spec 017)` against `main`
2. Audit pass (reviewer + security + performance) — small enough that one combined audit should suffice
3. Merge
4. `/deploy`
5. On prod: `pw2d:seo:status` should still read HEALTHY. Then verify GSC API call count dropped (next nightly run; check log).
