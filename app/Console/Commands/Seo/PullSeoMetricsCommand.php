<?php

declare(strict_types=1);

namespace App\Console\Commands\Seo;

use App\Actions\Seo\PullSeoMetrics;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Nightly command to pull SEO metrics from Google Search Console and GA4
 * into the seo_metrics table.
 *
 * Usage:
 *   php artisan pw2d:seo:pull                        # All enabled tenants, yesterday
 *   php artisan pw2d:seo:pull acme                   # Single tenant (bypasses seo_enabled check)
 *   php artisan pw2d:seo:pull --date=2026-04-01      # Specific date for all enabled tenants
 *   php artisan pw2d:seo:pull acme --date=today      # Today for a single tenant
 */
class PullSeoMetricsCommand extends Command
{
    protected $signature = 'pw2d:seo:pull
                            {tenant? : Tenant ID — if omitted, runs for all tenants with seo_enabled=true}
                            {--date=yesterday : Date to pull: yesterday|today|YYYY-MM-DD}';

    protected $description = 'Pull nightly GSC + GA4 SEO metrics for one or all enabled tenants';

    public function handle(): int
    {
        $date = $this->resolveDate($this->option('date'));

        if ($date === null) {
            $this->error('Invalid --date value. Use: yesterday, today, or YYYY-MM-DD.');
            return self::FAILURE;
        }

        $tenantId = $this->argument('tenant');

        $tenants = $this->resolveTenants($tenantId);

        if ($tenants->isEmpty()) {
            $this->warn('No tenants to process.');
            return self::FAILURE;
        }

        $action = new PullSeoMetrics;
        $anySucceeded = false;

        foreach ($tenants as $tenant) {
            $this->line("Processing tenant: <info>{$tenant->getTenantKey()}</info>");

            $result = $action->execute($tenant, $date);

            // A tenant counts as "succeeded" only if it actually upserted
            // at least one row with no errors. Zero-upsert-zero-error is
            // treated as a no-op (e.g., API returned no data for the day).
            if ($result->totalUpserted() > 0 && ! $result->hasErrors()) {
                $anySucceeded = true;
            }

            // Display per-source summary as a table row.
            $this->table(
                ['Source', 'Upserted', 'Errors'],
                [
                    ['GSC', $result->gscRowsUpserted, implode('; ', array_filter(
                        $result->errors,
                        fn (string $e) => str_contains(strtolower($e), 'gsc') || str_contains(strtolower($e), 'gsc_site_url') || str_contains(strtolower($e), 'google_service')
                    )) ?: '—'],
                    ['GA4', $result->ga4RowsUpserted, implode('; ', array_filter(
                        $result->errors,
                        fn (string $e) => str_contains(strtolower($e), 'ga4') || str_contains(strtolower($e), 'ga4_property_id')
                    )) ?: '—'],
                ],
            );

            if ($result->hasErrors()) {
                foreach ($result->errors as $error) {
                    $this->warn("  {$error}");
                }
            }
        }

        return $anySucceeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Parse the --date option into a CarbonImmutable.
     *
     * Returns null for invalid formats (signals the caller to error out).
     */
    private function resolveDate(string $value): ?CarbonImmutable
    {
        return match ($value) {
            'yesterday' => CarbonImmutable::yesterday('UTC'),
            'today'     => CarbonImmutable::today('UTC'),
            default     => $this->parseDateString($value),
        };
    }

    /**
     * Parse a YYYY-MM-DD string, returning null if it does not match the format.
     */
    private function parseDateString(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve which tenants to process.
     *
     * - With a tenant argument: look up that single tenant (does NOT check seo_enabled).
     * - Without: return all tenants where seo_enabled is truthy.
     *
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    private function resolveTenants(?string $tenantId): \Illuminate\Support\Collection
    {
        if ($tenantId !== null) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->error("Tenant not found: {$tenantId}");
                return collect();
            }

            return collect([$tenant]);
        }

        // Load all tenants and filter by the seo_enabled data-JSON key.
        // stancl/tenancy's VirtualColumn trait exposes JSON data keys as
        // direct attributes on retrieval, so no tenancy init is needed —
        // reading $tenant->seo_enabled works on any Tenant instance.
        // Normalization (bool|string|int Filament Toggle variance) handled
        // by filter_var; tenant_seo_enabled() is for global-context callers.
        return Tenant::all()->filter(
            fn (Tenant $tenant) => filter_var(
                $tenant->seo_enabled,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) === true,
        )->values();
    }
}
