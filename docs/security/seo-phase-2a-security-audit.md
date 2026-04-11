# SEO Phase 2a Security Audit — 2026-04-10

## TL;DR

The PR's security posture is solid for its threat model. Tenant isolation in every new widget and action is enforced by explicit `where('tenant_id', ?)` clauses with no exceptions, the service-account path is env-configurable and gitignored, and no logging paths leak credentials. **Zero critical findings.** Two medium findings around test coverage effectiveness and CLI error messages, and a handful of informational notes. **Recommendation: APPROVE WITH FIXES** — the medium findings are non-blocking but should land before or shortly after merge.

## Critical findings

None.

## Medium findings

### M1. Tenant-isolation tests do not exercise the widget code paths

**Location:** `tests/Feature/Seo/SeoDashboardTest.php:55-69`

The only two tests that actually boot the Filament page (`test_admin_can_access_seo_dashboard`, `test_unauthenticated_user_cannot_access_seo_dashboard`) are both `markTestSkipped()` due to the pre-existing F12 `ProblemProducts` REGEXP/sqlite incompatibility. The remaining "isolation" tests at lines 71-223 simply re-implement the widget's SQL inline and assert that the rewritten query is scoped — which proves nothing about `KpiCardsWidget::getStats()` or any other widget. The spec (line 344) explicitly calls out that widget tests must cover cross-tenant leakage.

**Fix.** Instantiate the widget classes directly and invoke their protected methods via reflection, or use Filament's `Livewire::test()` against the widget component with a stubbed `filament()->getTenant()`. Example for `KpiCardsWidget`:

```php
public function test_kpi_widget_only_returns_current_tenant_rows(): void
{
    // seed both tenants...
    filament()->setTenant($this->tenantA);
    $widget  = new KpiCardsWidget();
    $stats   = (fn () => $this->getStats())->call($widget);
    // assert stat values reflect tenant-a only
}
```

Until this lands, the "no cross-tenant leakage" guarantee is only enforced by code review, not by CI.

### M2. Google SDK error messages surface service-account path to CLI output

**Location:** `app/Actions/Seo/PullGscMetrics.php:96-101` and `app/Actions/Seo/PullGa4Metrics.php:90-95`

Both actions capture `$e->getMessage()` from the Google SDKs and bubble it into `PullResult::$errors`. `app/Console/Commands/Seo/PullSeoMetricsCommand.php:75-78` then prints these messages verbatim via `$this->warn($error)`. When the service-account JSON is missing or malformed, `Google_Client::setAuthConfig()` throws a message that embeds the full absolute path passed to it (e.g. `"Could not read the credential file: /var/www/pw2d/storage/app/seo/google-service-account.json"`). The spec explicitly says "never log the path." CLI stdout is less risky than a log file, but scheduled output from `Schedule::command(...)->runInBackground()` can be redirected into a log chain and inherit wider readers.

**Fix.** In both actions, redact filesystem paths before handing the message to `PullResult`:

```php
} catch (\Throwable $e) {
    $msg = str_replace(
        (string) config('seo.google.service_account_path'),
        '[REDACTED_SA_PATH]',
        $e->getMessage()
    );
    return new PullResult(upserted: $upserted, errors: [$msg]);
}
```

## Informational / defense-in-depth

### I1. `resolveTenants()` lacks try/finally around tenancy init

**Location:** `app/Console/Commands/Seo/PullSeoMetricsCommand.php:141-148`

If `tenant_seo_enabled()` throws mid-loop, tenancy leaks into the next iteration and `tenant('seo_enabled')` reads from the wrong tenant. The helper is fully defensive today, but structural fix is cheap:

```php
return Tenant::all()->filter(function (Tenant $tenant) {
    tenancy()->initialize($tenant);
    try {
        return tenant_seo_enabled();
    } finally {
        tenancy()->end();
    }
})->values();
```

### I2. `PullSeoMetrics::execute()` initializes tenancy outside try

**Location:** `app/Actions/Seo/PullSeoMetrics.php:31-41`

`tenancy()->initialize($tenant)` called outside the `try`. OK in practice (init throw = nothing to clean up) but defensive coding would still include it inside the try. Move `initialize()` inside try for belt-and-braces.

### I3. No format validation on tenant SEO inputs

**Location:** `app/Filament/Resources/TenantResource.php:116-122`

Admins can type anything for `gsc_site_url` / `ga4_property_id`. Malformed values won't leak data but will cause the nightly pull to silently emit an error per tenant. Not security, reliability. Add `->regex('/^(sc-domain:|https:\/\/)/')` and `->regex('/^properties\/\d+$/')`.

### I4. `QueryExplorerWidget` filter values trusted

**Location:** `app/Filament/Widgets/Seo/QueryExplorerWidget.php:68-70`

`$this->filter` is used in a `LIKE` clause. Value is constrained to a hardcoded dropdown today — no injection. When F10 converts this to free-text, ensure `addcslashes($prefix, '%_\\')` escapes LIKE wildcards before interpolation.

## What's solid

1. **Explicit `tenant_id` filtering in every widget DB query** — verified across all 5 widgets. Both `source` discrimination and `metric_date` ranges layered on top. No query path missed.
2. **Action-layer inserts hardcode `tenant_id`** from `$tenant->getTenantKey()` at `PullGscMetrics.php:61` and `PullGa4Metrics.php:57`. Upsert unique key includes `tenant_id`, so a mis-tenanted insert cannot collide with another tenant's row.
3. **Service-account path is env-configurable** at `config/seo.php:7` via `SEO_GOOGLE_SA_PATH`. `.env.example:67` ships the prod path template.
4. **`storage/app/seo/` is gitignored** by `storage/app/.gitignore:1-4` (`*` with only `private/` and `public/` whitelisted). Zero risk of committing service-account JSON.
5. **Zero `Log::` / `logger()` calls** in `app/Services/Seo`, `app/Actions/Seo`, or `app/Console/Commands/Seo`. The spec's "never log the path" directive is honored by absence.
6. **No route or controller serves from `storage/app/seo/`.** Grepped routes and controllers; the path is outside `public/` and not linked.
7. **Date parsing is SQL-injection-proof** — `PullSeoMetricsCommand.php:104-115` regex-validates `YYYY-MM-DD` before `CarbonImmutable::createFromFormat()`, all downstream query usage passes bound values.
8. **`PullSeoMetrics` tenancy teardown uses try/finally** at `PullSeoMetrics.php:36-41` — child-action throw cannot leave scheduler stuck in tenant context.
9. **Child actions catch all `Throwable`** (not just `Exception`) at `PullGscMetrics.php:96` and `PullGa4Metrics.php:90`. One broken tenant cannot crash the scheduler loop.
10. **Third-party packages clean** — `google/analytics-data v0.21.1`, `google/apiclient v2.19.2`, transitive `google/auth`. No known CVEs in these exact versions as of audit date.

## Merge recommendation

**APPROVE WITH FIXES.** No critical or high findings. M1 (widget tests) and M2 (error redaction) should land before merge to enforce what is currently only guaranteed by code review. Everything else is defense-in-depth that can track as a small follow-up PR.
