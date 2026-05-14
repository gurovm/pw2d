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
 *   php artisan pw2d:seo:pull                               # All enabled tenants, 4-day GSC window + yesterday GA4
 *   php artisan pw2d:seo:pull acme                          # Single tenant (bypasses seo_enabled check)
 *   php artisan pw2d:seo:pull --date=2026-04-01             # Single explicit date — both GSC and GA4 pull only that day
 *   php artisan pw2d:seo:pull acme --date=today             # Today for a single tenant (4-day GSC window)
 *   php artisan pw2d:seo:pull --gsc-window-days=7           # Override GSC window size
 *   php artisan pw2d:seo:pull --ga4-window-days=3           # Override GA4 window size (rare)
 */
class PullSeoMetricsCommand extends Command
{
    protected $signature = 'pw2d:seo:pull
                            {tenant? : Tenant ID — if omitted, runs for all tenants with seo_enabled=true}
                            {--date=yesterday : Anchor date: yesterday|today|YYYY-MM-DD}
                            {--gsc-window-days=4 : Number of days to pull for GSC ending at the anchor date (inclusive)}
                            {--ga4-window-days=1 : Number of days to pull for GA4 ending at the anchor date (inclusive)}';

    protected $description = 'Pull nightly GSC + GA4 SEO metrics for one or all enabled tenants';

    public function handle(): int
    {
        $dateOption = (string) $this->option('date');

        [$anchor, $isExplicitDate] = $this->resolveAnchorDate($dateOption);

        if ($anchor === null) {
            $this->error('Invalid --date value. Use: yesterday, today, or YYYY-MM-DD.');
            return self::FAILURE;
        }

        [$gscDates, $ga4Dates] = $this->buildDateWindows($anchor, $isExplicitDate);

        $tenantId = $this->argument('tenant');
        $tenants  = $this->resolveTenants($tenantId);

        if ($tenants->isEmpty()) {
            $this->warn('No tenants to process.');
            return self::FAILURE;
        }

        $action       = new PullSeoMetrics;
        $anySucceeded = false;

        foreach ($tenants as $tenant) {
            $this->line("Processing tenant: <info>{$tenant->getTenantKey()}</info>");

            $result = $action->execute($tenant, $gscDates, $ga4Dates);

            if ($result->totalUpserted() > 0 && ! $result->hasErrors()) {
                $anySucceeded = true;
            }

            // Per-source summary table.
            $this->table(
                ['Source', 'Upserted', 'Errors'],
                [
                    ['GSC', $result->gscRowsUpserted, $this->formatSourceErrors($result->errors, 'gsc')],
                    ['GA4', $result->ga4RowsUpserted, $this->formatSourceErrors($result->errors, 'ga4')],
                ],
            );

            // Per-date breakdown in verbose mode.
            if ($this->getOutput()->isVerbose()) {
                if (! empty($result->gscDailyCounts)) {
                    $this->line('  GSC per-date:');
                    foreach ($result->gscDailyCounts as $dateStr => $count) {
                        $this->line("    {$dateStr}: {$count} rows");
                    }
                }
                if (! empty($result->ga4DailyCounts)) {
                    $this->line('  GA4 per-date:');
                    foreach ($result->ga4DailyCounts as $dateStr => $count) {
                        $this->line("    {$dateStr}: {$count} rows");
                    }
                }
            }

            if ($result->hasErrors()) {
                foreach ($result->errors as $error) {
                    $this->warn("  {$error}");
                }
            }
        }

        return $anySucceeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Parse the --date option into a CarbonImmutable anchor date.
     *
     * Returns [CarbonImmutable|null, bool] — the anchor and whether it was an
     * explicit YYYY-MM-DD (true) vs a keyword like 'yesterday'/'today' (false).
     *
     * @return array{CarbonImmutable|null, bool}
     */
    private function resolveAnchorDate(string $value): array
    {
        return match ($value) {
            'yesterday' => [CarbonImmutable::yesterday('UTC'), false],
            'today'     => [CarbonImmutable::today('UTC'), false],
            default     => [$this->parseDateString($value), true],
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
     * Build the GSC and GA4 date arrays from the anchor date and window options.
     *
     * Spec §4.1 rules:
     * - Explicit YYYY-MM-DD with no window flags passed → force both windows to 1
     *   (preserves manual single-date backfill behavior from before Spec 016).
     * - Keyword anchor (yesterday/today), or any anchor when window flags are
     *   explicitly passed → use the option values (defaults: GSC=4, GA4=1).
     *
     * The returned arrays are ordered newest-first (anchor, anchor-1d, …).
     *
     * @return array{array<int, CarbonImmutable>, array<int, CarbonImmutable>}
     */
    private function buildDateWindows(CarbonImmutable $anchor, bool $isExplicitDate): array
    {
        // Detect whether window flags were explicitly passed by the caller
        // (as opposed to using their defaults).
        $gscWindowPassed = $this->input->hasParameterOption('--gsc-window-days');
        $ga4WindowPassed = $this->input->hasParameterOption('--ga4-window-days');

        if ($isExplicitDate && ! $gscWindowPassed && ! $ga4WindowPassed) {
            // Backward-compat: single explicit date → pull exactly that one day.
            $gscWindowDays = 1;
            $ga4WindowDays = 1;
        } else {
            $gscWindowDays = max(1, (int) $this->option('gsc-window-days'));
            $ga4WindowDays = max(1, (int) $this->option('ga4-window-days'));
        }

        $gscDates = [];
        for ($i = 0; $i < $gscWindowDays; $i++) {
            $gscDates[] = $anchor->subDays($i);
        }

        $ga4Dates = [];
        for ($i = 0; $i < $ga4WindowDays; $i++) {
            $ga4Dates[] = $anchor->subDays($i);
        }

        return [$gscDates, $ga4Dates];
    }

    /**
     * Filter errors to those belonging to a specific source (gsc or ga4).
     *
     * Falls back to a dash when there are no matching errors.
     */
    private function formatSourceErrors(array $errors, string $source): string
    {
        $keywords = match ($source) {
            'gsc'   => ['gsc', 'gsc_site_url', 'google_service'],
            'ga4'   => ['ga4', 'ga4_property_id'],
            default => [],
        };

        $filtered = array_filter(
            $errors,
            fn (string $e) => array_reduce(
                $keywords,
                fn (bool $carry, string $kw) => $carry || str_contains(strtolower($e), $kw),
                false,
            ),
        );

        return implode('; ', $filtered) ?: '—';
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
