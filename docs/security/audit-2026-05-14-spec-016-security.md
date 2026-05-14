# Spec 016 Security Audit — 2026-05-14

## Verdict
**No issues** — Low risk. All Spec 016 code paths use parameterized queries, explicit `tenant_id` filtering, and proper tenancy lifecycle management. The new status command runs in central context and never leaks data across tenants. One Low/Note item for future hardening.

## Critical
- None.

## High
- None.

## Medium
- None.

## Low / Note for future

1. **Information disclosure if status command output is ever piped to a non-admin sink.**
   - File: `app/Console/Commands/Seo/SeoStatusCommand.php:94-151`
   - Threat model: an attacker who gains access to a webhook, CI artifact, or future HTTP endpoint that wraps this command would see per-tenant tenant IDs, row counts, latest metric dates, and the absolute filesystem path of the service-account JSON. None of this is currently reachable by anyone except an SSH/console admin, but the command structure (tenant ID printed before each table) is not safe to re-expose as-is.
   - Suggested fix when this risk materializes: add a `--format=json|table` flag and an `--redact-paths` flag; never let the command emit the raw service-account path in machine-readable output. Today's behavior is fine for admin-only console use; track this as a forward-compat constraint in `docs/seo/operations.md`.

2. **`reportCredentialStatus()` discloses the absolute service-account path in healthy output.**
   - File: `app/Console/Commands/Seo/SeoStatusCommand.php:174-178`
   - Threat model: an admin who screen-shares or pastes the command output into a public bug report inadvertently leaks the on-disk credential location. Path-only (not contents) is fine per the audit checklist, but it is still an "internal infrastructure detail" worth masking by default.
   - Suggested fix (optional): replace the path with `basename($path)` plus an "ok" marker in normal mode, and gate the full path behind `-v` (verbose). Not required for the spec.

3. **`$tenant->seo_enabled` / `gsc_site_url` / `ga4_property_id` are read from tenant JSON `data` and cast with `(string)`.**
   - File: `app/Console/Commands/Seo/SeoStatusCommand.php:105-106`
   - Note: an admin who stores `<script>` or markup in those Filament fields could end up rendering it in future Filament widgets that consume the same values. The status command itself is shell-safe (Symfony Console output is plain text, not HTML), so this is not a finding here — flagged only so the Filament SEO tenant settings form is checked for output escaping in adjacent areas (`{!! !!}` vs `{{ }}`).

## What I verified (positive findings)

1. **Tenant isolation in `SeoStatusCommand` is correct.**
   - `fetchAggregates()` at `app/Console/Commands/Seo/SeoStatusCommand.php:214-235` uses `whereIn('tenant_id', $tenantIds)` against a list derived from Eloquent `Tenant::all()->pluck('id')` (or a single `Tenant::find($tenantId)`). Eloquent binds these as parameters — no SQL injection surface.
   - Results are immediately grouped by `tenant_id` (`$rows->groupBy('tenant_id')`) and each tenant's loop iteration uses `$aggregates->get($tenantId, collect())` (`SeoStatusCommand.php:108`). There is no cross-tenant key collision risk because the GROUP BY is on `(tenant_id, source)` and the lookup key is the same `tenant_id` string. A metric_date from tenant A cannot surface in tenant B's row.
   - Single-tenant filter (`pw2d:seo:status acme`) uses `Tenant::find($tenantId)` — Eloquent parameterized lookup, safe from injection regardless of CLI value.

2. **PullSeoMetrics tenancy lifecycle is bulletproof.**
   - `tenancy()->initialize($tenant)` at `app/Actions/Seo/PullSeoMetrics.php:37` is followed by a single `try` block whose `finally` (line 84-86) runs `tenancy()->end()`. PHP guarantees `finally` execution on any throw, including throws from inside the per-date inner `try/catch` blocks (which themselves swallow exceptions, so the outer `try` rarely sees a throw at all). Even an unexpected fatal-ish exception in the array_sum / array_reduce computation after the GA4 loop would still run `finally` because it sits inside the outer `try`.
   - Verified by test `test_tenancy_is_initialized_and_ended` at `tests/Feature/Seo/Actions/PullSeoMetricsTest.php:120-136`.

3. **No mass assignment risk.**
   - `SeoStatusCommand` uses `DB::table('seo_metrics')` with hardcoded `selectRaw` column list and `whereIn`. No `Model::create()` or `fill()`. No `$fillable` concerns.
   - `PullSeoMetrics` does not call `upsert()` directly — that's delegated to `PullGscMetrics`/`PullGa4Metrics` which build static column whitelists (`app/Actions/Seo/PullGscMetrics.php:60-93`) with no user-controlled keys. Tainted values from a malicious GSC API response would only land in their declared columns; no schema-bypass surface.

4. **CLI argument injection — safe.**
   - `--date` parsed by strict regex `/^\d{4}-\d{2}-\d{2}$/` in `parseDateString()` at `app/Console/Commands/Seo/PullSeoMetricsCommand.php:122-133`. Failed parse returns `null` → command exits with FAILURE.
   - `tenant` argument flows through `Tenant::find($tenantId)` (Eloquent, parameterized) in both `PullSeoMetricsCommand::resolveTenants()` and `SeoStatusCommand::loadTenants()`. No string interpolation into SQL anywhere in either command.
   - `--days` is cast `(int)` then `max(1, …)` clamped (`SeoStatusCommand.php:53`). Used as a `subDays()` argument and bound as a parameter in `selectRaw('... WHERE metric_date >= ?', [$windowStart])` at `SeoStatusCommand.php:224-229`. The only raw SQL in either file is the `CASE WHEN metric_date >= ? THEN 1 ELSE 0 END` — the `?` is bound via the second argument to `selectRaw`, no interpolation.
   - `--gsc-window-days` / `--ga4-window-days` are cast `(int)` and `max(1, …)` clamped, used only as loop bounds.

5. **`hasParameterOption` is not influenced by env vars.**
   - Symfony's `InputInterface::hasParameterOption()` inspects `argv` (parsed CLI tokens) only, not environment variables like `COLUMNS` or `TERM`. The check at `PullSeoMetricsCommand.php:152-153` is safe.

6. **No sensitive logging.**
   - `Log::warning('PullSeoMetrics: GSC date failed — continuing', […])` at `app/Actions/Seo/PullSeoMetrics.php:58-62` and the GA4 sibling at lines 77-81 log: tenant ID, date string, exception message. No service-account contents, no API tokens, no Filament-stored GSC/GA4 credentials. The tenant ID is not sensitive (it appears in URLs and Filament UI already).
   - `SeoStatusCommand` writes nothing to `storage/logs/laravel.log` (no `Log::` calls at all).
   - `reportCredentialStatus()` prints only the path string, never the file contents. Confirmed at `SeoStatusCommand.php:167-179`.

7. **Test safety.**
   - All three test files use `/fake/path.json` placeholders, fabricated property IDs (`properties/111111`, `properties/123`), and `sc-domain:tenant-a.com`-style fake site URLs. No real-looking GA4 property IDs, no JSON-key fragments, no API tokens.
   - `test_missing_service_account_file_is_flagged` uses `/nonexistent/path/service-account.json` — clearly synthetic.
   - `test_cross_tenant_isolation` already asserts that running PullSeoMetrics on tenant-a does not mutate tenant-b's `seo_metrics` rows — a positive proof of the upsert key including `tenant_id`.

8. **Exit-code logic correctness.**
   - `SeoStatusCommand` returns `self::FAILURE` only when STALE/NO_DATA/ERROR exists (`SeoStatusCommand.php:155-157`). UNCONFIGURED never triggers failure. This matches spec §5.4 and avoids alert fatigue from explicitly-disabled tenants — a security-adjacent operational hygiene win because flapping alerts get muted by humans.

---

## 5-line summary
- Critical: 0 · High: 0 · Medium: 0 · Low/Note: 3
- Tenant isolation verified: `whereIn('tenant_id', …)` + `groupBy('tenant_id', …)` + per-tenant `Collection` lookup — no leakage path.
- Tenancy lifecycle verified: `try { … } finally { tenancy()->end(); }` covers both GSC and GA4 loops including post-loop computation.
- All CLI args parameterized through Eloquent or strict regex; no raw SQL with unbound input.
- Top notes for future: gate full service-account path behind `-v`, and add `--format=json` with redaction before piping `pw2d:seo:status` into any non-admin sink.
