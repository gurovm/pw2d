# Spec 016 Code Review — 2026-05-14

## Verdict
Approve with nits (1 medium spec deviation, 4 nits, several test-quality observations). Nothing blocks merge.

## Critical (block merge)
- None.

## High (fix before deploy)
- None.

## Medium (next sprint)
- **`app/Console/Commands/Seo/SeoStatusCommand.php:97-102` — `seo_enabled=false` tenants never increment the `UNCONFIGURED` summary counter.** The handler `continue`s after printing the per-tenant `→ UNCONFIGURED` line but skips updating `$summaryCounts`. Spec §5.2 sample output reads `Summary: 3 HEALTHY · 1 STALE · 1 UNCONFIGURED · …` where the `1 UNCONFIGURED` corresponds to a `seo_enabled=NO` tenant. Today the summary banner under-counts. Fix: `$summaryCounts[self::UNCONFIGURED] += 2;` (one per source, to match the per-source granularity model) or `+= 1` if you treat tenant-level disable as a single UNCONFIGURED — pick a rule and document. The exit-code contract is unaffected (UNCONFIGURED never triggers FAILURE), but the diagnostic banner is misleading.

## Nits / Style
- **`app/Console/Commands/Seo/SeoStatusCommand.php:268` — `diffInDays()` return type drift.** Newer Carbon returns `float`. The explicit `(int)` cast is fine, but consider `->diffInDays(..., absolute: true)` for clarity (Carbon ≥ 2.62). Not a bug, just future-proofing.
- **`app/Console/Commands/Seo/SeoStatusCommand.php:199` — `Tenant::all()->values()` is unbounded.** Same pattern as `PullSeoMetricsCommand::resolveTenants()`. Fine at this project's scale (a handful of tenants), but if tenant count grows, switch to `chunk()`. Already flagged in the prior Spec 014 perf audit; status command inherits the same constraint.
- **`app/Console/Commands/Seo/SeoStatusCommand.php:270-274` — `match` default branch is unreachable.** The outer loop only passes `'gsc'` / `'ga4'` (line 113). The `default => 2` arm is dead code. Either remove (force `match (true)` exhaustiveness via assertion) or document that `default` is a safety net. Minor.
- **`app/Console/Commands/Seo/SeoStatusCommand.php:104-106` — type juggling on tenant data keys.** `(string) ($tenant->gsc_site_url ?? '')` is fine, but inconsistent with `PullGscMetrics:39` which uses `empty(tenant('gsc_site_url'))`. Both work, just different idioms. No fix needed.
- **`app/Actions/Seo/PullSeoMetrics.php:53,72` — `array_push($errors, ...$result->errors)`.** Slightly less idiomatic than `$errors = [...$errors, ...$result->errors]` (matches the readonly/spread style used elsewhere). Aesthetic.
- **`app/Console/Commands/Seo/PullSeoMetricsCommand.php:99` — `assertSuccessful` semantics changed.** The command returns `SUCCESS` only when at least one tenant×source produced upserts AND no errors. That's correct per the original Spec 014 contract, but with the new 4-day window a brand-new install may exit FAILURE on first run even though the system is healthy (most days return 0 GSC rows due to lag). Tests don't exercise this. Not a blocker — operations.md should call it out. Spec 014 had the same semantics, so it's not a regression.

## Spec deviations
- **Summary counter undercount (see Medium above).** Spec §5.2 sample output shows `seo_enabled=false` tenants contributing to the `UNCONFIGURED` total. Implementation skips that increment.
- **Spec §4.1 / `hasParameterOption` claim — verified correct.** The builder's report is accurate: `hasParameterOption('--gsc-window-days')` inspects raw input tokens, so explicit `--gsc-window-days=4` (equal to the default) is correctly detected as "passed". Symfony Console behaves as the spec assumes. No deviation.
- **Spec §4.2 `If GSC fails for date D, log it and continue` — implemented.** `PullSeoMetrics.php:54-63` catches `\Throwable`, logs at `warning` level, appends a structured error string, and continues. Good.
- **Spec §5.3 ERROR status is reachable from `computeSourceStatus()` exceptions** (`SeoStatusCommand.php:131-135`) — the only path that increments ERROR. Acceptable, though no test exercises it. Spec §5.6 didn't require it.

## Test quality observations
- **Pest vs PHPUnit.** Project rule (`CLAUDE.md`): "Use Pest (preferred) or PHPUnit." All three audited tests are PHPUnit-style classes extending `Tests\TestCase`. Migrated tests retained their original style (consistent with `tests/Feature/Seo/Actions/PullGscMetricsTest.php`). Acceptable, but new tests (esp. `SeoStatusCommandTest.php`) could have been Pest. Style consistency wins here, no change needed.
- **Factories vs raw inserts.** `SeoStatusCommandTest.php:48-71` uses `DB::table('seo_metrics')->insert(...)` directly. Per `.claude/rules/standards.md`: "Use Model Factories (`::factory()->create()`), not raw DB inserts." Counter-point: there's no `SeoMetric` Eloquent model in this codebase (DB-table-only design — confirmed by `app/Actions/Seo/PullGscMetrics.php:81` using `DB::table('seo_metrics')->upsert(...)`). Without a model, no factory exists. Either create `app/Models/SeoMetric.php` + a factory (clean fix) or accept the raw inserts as pragmatic. Recommend filing a small follow-up: add a `SeoMetricFactory` for test ergonomics.
- **`SeoStatusCommandTest.php:140-164` — brittle string assertion.** `expectsOutputToContain('5')` and `('10')` match any "5" / "10" anywhere in output (positions, percentages, ages). Survives only because the table currently has no other "5" or "10". A future column addition could flake this. Consider regex assertions on the Rows column, or `assertExitCode` + a separate DB-state assertion.
- **`SeoStatusCommandTest.php:191-202` — `test_missing_service_account_file_is_flagged` doesn't restore config.** `config(['seo.google.service_account_path' => '/nonexistent/...'])` mutates global state. `RefreshDatabase` doesn't reset config. Subsequent tests in the same process inherit the bad path. Tests today don't read it after this one, so it passes — but adding tests in the wrong order would break this. Fix: wrap in `try/finally` or use `config()->set()` + tear-down restore.
- **`SeoStatusCommandTest.php:209-226` — `test_per_source_independence` doesn't assert per-source independence rigorously.** It asserts the output *contains* both `HEALTHY` and `UNCONFIGURED` somewhere, but doesn't verify they're on the GSC and GA4 rows respectively. A test for the inverse case (healthy GA4 + unconfigured GSC) would solidify coverage.
- **`PullSeoMetricsTest.php:210-272` — `\stdClass` mutable counter pattern is awkward.** Works, but a closure-captured `array` via `&$ref` or a dedicated spy class would read cleaner. Not blocking; the comment at :214 explains the choice.
- **No test covers the `ERROR` status path** in `SeoStatusCommand`. Spec §5.3 lists ERROR as a real status (DB unreachable, etc.). A test that injects a `DB::statement` failure or mocks a throw would close this. Minor — the path is small and obvious by inspection.
- **No test for the summary-banner counts.** Tests cover exit codes and individual status strings, but no assertion verifies the `Summary: X HEALTHY · Y STALE · …` line. This is exactly where the Medium-severity counter bug would have been caught.
- **`PullSeoMetricsCommandTest` — three new window tests are solid.** Each binds a date-capturing fake, asserts the exact dates Symfony passes through, and checks both GSC and GA4 windows independently. Good coverage.
- **`PullSeoMetricsTest::test_gsc_failure_for_one_date_does_not_block_other_dates`** — proves error isolation at the multi-date level. Verifies both `errors` array content and DB state. Excellent.

## Backward compatibility audit
- **No external callers of `PullSeoMetrics::execute()` other than `PullSeoMetricsCommand` and tests.** Grepped `PullSeoMetrics` across the entire repo — only the command, the action itself, the result class, and the two test files. No jobs, no controllers, no Livewire components reference it. The breaking signature change is fully contained.
- **`$date` property semantics softened (now "latest in window")** — `PullSeoMetricsResult.php:34`. No external readers grepped; safe.
- **The command preserves explicit-single-date behavior** (`PullSeoMetricsCommand.php:155-158`) via `hasParameterOption` gating. Verified by `test_explicit_date_defaults_both_windows_to_1`. Spec §4.1 contract upheld.

## Multi-tenancy compliance
- **`SeoStatusCommand` never calls `tenancy()->initialize()`** — correct per spec §1. Every `seo_metrics` query is explicitly filtered by `whereIn('tenant_id', $tenantIds)` (`SeoStatusCommand.php:230`). No cross-tenant leak.
- **`PullSeoMetrics` still wraps tenancy init in `try/finally`** (`PullSeoMetrics.php:37, 84-86`). The `finally` block runs even on uncaught throws from the new per-date loops. Good.
- **`PullSeoMetricsCommand::resolveTenants()`** reads `$tenant->seo_enabled` from the central context via the `VirtualColumn` trait — no per-tenant init in the loop. No N+1. Comment at :223-228 documents why this works.

## Performance / N+1
- **`SeoStatusCommand::fetchAggregates()` is genuinely one query** (`SeoStatusCommand.php:223-232`) — confirmed by reading: single `selectRaw` with `MAX(metric_date)`, conditional `SUM(CASE WHEN …)`, `whereIn(tenant_id, …)`, `groupBy(tenant_id, source)`. Returns one row per tenant×source. The downstream loop only `firstWhere`s in the pre-grouped collection. **Builder's N+1 claim verified.**
- **The conditional `SUM(CASE WHEN metric_date >= ? …)` is index-friendly** given `idx_tenant_source_date` on `(tenant_id, source, metric_date)`. MySQL will range-scan within the grouped partitions. Good.
- **`PullSeoMetricsCommand::resolveTenants()`** uses `Tenant::all()->filter(…)` — one query, in-memory filter. No N+1.

## PHP 8.3 idioms
- `final readonly class PullSeoMetricsResult` — good.
- `match` used in `resolveAnchorDate` and `formatSourceErrors` — good.
- Named arguments in test calls (`bindFakeGscThrowingOnDates(throwOnDates: …, rows: …)`) — good.
- Arrow functions for predicates (`fn (CarbonImmutable $d) => …`) — good.
- `declare(strict_types=1)` present on all new/modified files — good.

## Praise (what was done well)
- The per-date GSC error isolation is implemented exactly as the spec demands and proven by a focused test.
- `fetchAggregates` is a textbook example of avoiding N+1 — one query, two aggregations, `groupBy` for O(1) lookup downstream.
- The `hasParameterOption` trick to detect "default vs explicit" without polluting the option signature is clean and tested.
- Service-account credential check is correctly separated from per-tenant status (system-level concern, reported once).
- Comments explain *why*, not *what* (e.g., the VirtualColumn justification at `PullSeoMetricsCommand.php:223-228`).
- Result object is `final readonly` — immutable inputs and outputs throughout the action.

## File-path references
- `/Users/mg/projects/power_to_decide/pw2d/app/Actions/Seo/PullSeoMetrics.php`
- `/Users/mg/projects/power_to_decide/pw2d/app/Actions/Seo/PullSeoMetricsResult.php`
- `/Users/mg/projects/power_to_decide/pw2d/app/Console/Commands/Seo/PullSeoMetricsCommand.php`
- `/Users/mg/projects/power_to_decide/pw2d/app/Console/Commands/Seo/SeoStatusCommand.php`
- `/Users/mg/projects/power_to_decide/pw2d/tests/Feature/Seo/Actions/PullSeoMetricsTest.php`
- `/Users/mg/projects/power_to_decide/pw2d/tests/Feature/Seo/Commands/PullSeoMetricsCommandTest.php`
- `/Users/mg/projects/power_to_decide/pw2d/tests/Feature/Seo/Commands/SeoStatusCommandTest.php`
