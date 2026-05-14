<?php

declare(strict_types=1);

namespace App\Console\Commands\Seo;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic command: print a per-tenant SEO health table.
 *
 * Does NOT initialize tenancy — all queries use explicit tenant_id filtering
 * and run in the central (admin/console) database context.
 *
 * No N+1: tenant metrics are pre-fetched in a single grouped query before
 * iterating tenants.
 *
 * Exit codes:
 *   0 — every configured tenant×source is HEALTHY (or UNCONFIGURED, which is never an error).
 *   1 — at least one configured tenant×source is STALE / NO_DATA / ERROR.
 *
 * Usage:
 *   php artisan pw2d:seo:status              # All tenants
 *   php artisan pw2d:seo:status acme         # Scope to one tenant
 *   php artisan pw2d:seo:status --days=28    # Extend the row-count window
 */
class SeoStatusCommand extends Command
{
    protected $signature = 'pw2d:seo:status
                            {tenant? : Optional tenant ID to scope the report to}
                            {--days=14 : Days of history to summarize per tenant}';

    protected $description = 'Display a per-tenant SEO health table (GSC + GA4 freshness + row counts)';

    // Freshness thresholds (spec §5.3).
    // GSC: 5 days (2d Google lag + 3d cron-firing slop).
    // GA4: 2 days (no lag, 1 day slop).
    private const GSC_STALE_DAYS = 5;
    private const GA4_STALE_DAYS = 2;

    // Health status strings.
    private const HEALTHY       = 'HEALTHY';
    private const STALE         = 'STALE';
    private const NO_DATA       = 'NO_DATA';
    private const UNCONFIGURED  = 'UNCONFIGURED';
    private const ERROR         = 'ERROR';

    public function handle(): int
    {
        $days      = max(1, (int) $this->option('days'));
        $tenantArg = $this->argument('tenant');

        // ── Service-account credential check (system-level, reported once) ──
        $this->reportCredentialStatus();

        // ── Load tenants ─────────────────────────────────────────────────────
        $tenants = $this->loadTenants($tenantArg);

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::FAILURE;
        }

        // ── Pre-fetch all metric aggregates in one query (no N+1) ───────────
        // Produces: tenant_id, source, max_date, row_count
        $tenantIds = $tenants->pluck('id')->all();
        $windowStart = CarbonImmutable::today('UTC')->subDays($days)->format('Y-m-d');

        $aggregates = $this->fetchAggregates($tenantIds, $windowStart);

        // ── Per-tenant output ─────────────────────────────────────────────────
        $summaryCounts = [
            self::HEALTHY      => 0,
            self::STALE        => 0,
            self::UNCONFIGURED => 0,
            self::NO_DATA      => 0,
            self::ERROR        => 0,
        ];

        $today = CarbonImmutable::today('UTC');

        foreach ($tenants as $tenant) {
            $tenantId  = (string) $tenant->getTenantKey();
            $seoEnabled = filter_var(
                $tenant->seo_enabled,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) === true;

            $enabledLabel = $seoEnabled ? 'YES' : 'NO';
            $this->line('');
            $this->line("Tenant: <info>{$tenantId}</info>  (seo_enabled={$enabledLabel})");

            if (! $seoEnabled) {
                $this->line('  → UNCONFIGURED (seo_enabled=false; skipping)');
                // Count as 2 UNCONFIGURED — one per source (GSC + GA4) — matching
                // the per-source granularity model described in spec §5.3.
                $summaryCounts[self::UNCONFIGURED] += 2;
                continue;
            }

            // Resolve per-source config from tenant data JSON (VirtualColumn).
            $gscSiteUrl    = (string) ($tenant->gsc_site_url ?? '');
            $ga4PropertyId = (string) ($tenant->ga4_property_id ?? '');

            $tenantAgg = $aggregates->get($tenantId, collect());

            // Build table rows for GSC and GA4.
            $rows = [];

            foreach (['gsc' => $gscSiteUrl, 'ga4' => $ga4PropertyId] as $source => $configValue) {
                try {
                    [$status, $latestDate, $ageDays, $rowCount] = $this->computeSourceStatus(
                        source: $source,
                        configured: $configValue !== '',
                        tenantAgg: $tenantAgg,
                        today: $today,
                    );

                    $summaryCounts[$status]++;

                    $configuredMark = $configValue !== '' ? '✓' : '✗';
                    $latestDateStr  = $latestDate ?? '—';
                    $ageStr         = $ageDays !== null ? "{$ageDays} day" . ($ageDays === 1 ? '' : 's') : '—';
                    $rowCountStr    = $rowCount !== null ? number_format($rowCount) : '—';
                    $sourceLabel    = strtoupper($source);

                    $rows[] = [$sourceLabel, $configuredMark, $latestDateStr, $ageStr, $rowCountStr, $status];
                } catch (\Throwable $e) {
                    $summaryCounts[self::ERROR]++;
                    $rows[] = [strtoupper($source), '?', '—', '—', '—', self::ERROR];
                    $this->warn("  ERROR collecting {$source} status: " . $e->getMessage());
                }
            }

            $this->table(
                ['Source', 'Configured?', 'Latest date', 'Age', "Rows ({$days}d)", 'Status'],
                $rows,
            );
        }

        // ── Summary banner ───────────────────────────────────────────────────
        $this->line('');
        $h  = $summaryCounts[self::HEALTHY];
        $st = $summaryCounts[self::STALE];
        $un = $summaryCounts[self::UNCONFIGURED];
        $nd = $summaryCounts[self::NO_DATA];
        $er = $summaryCounts[self::ERROR];
        $this->line("Summary: {$h} HEALTHY · {$st} STALE · {$un} UNCONFIGURED · {$nd} NO_DATA · {$er} ERROR");

        // ── Exit code ────────────────────────────────────────────────────────
        // Non-zero only when a *configured* source is unhealthy (spec §5.4).
        $hasProblems = ($summaryCounts[self::STALE] + $summaryCounts[self::NO_DATA] + $summaryCounts[self::ERROR]) > 0;

        return $hasProblems ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print the service-account file status once at the top.
     *
     * This is a system-level check, not per-tenant.
     */
    private function reportCredentialStatus(): void
    {
        $path = (string) config('seo.google.service_account_path');

        if ($path === '') {
            $this->warn('  Service account JSON: path not configured (SEO_GOOGLE_SA_PATH is empty)');
            return;
        }

        if (file_exists($path) && is_readable($path)) {
            $this->line("  Service account JSON: <info>✓ {$path} (readable)</info>");
        } else {
            $this->warn("  Service account JSON: ✗ {$path} (NOT FOUND or not readable)");
        }
    }

    /**
     * Load all tenants, or a single tenant if $tenantId is provided.
     *
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    private function loadTenants(?string $tenantId): \Illuminate\Support\Collection
    {
        if ($tenantId !== null) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->error("Tenant not found: {$tenantId}");
                return collect();
            }

            return collect([$tenant]);
        }

        return Tenant::all()->values();
    }

    /**
     * Fetch metric aggregates for all tenant IDs in a single grouped query.
     *
     * Returns a Collection keyed by tenant_id, where each value is a Collection
     * of stdClass objects with fields: source, max_date, row_count.
     *
     * The `row_count` here is for the full history — we also fetch the windowed
     * count in a second query grouped the same way to avoid per-tenant SELECTs.
     *
     * @param  array<int, string>         $tenantIds
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection>
     */
    private function fetchAggregates(array $tenantIds, string $windowStart): \Illuminate\Support\Collection
    {
        if (empty($tenantIds)) {
            return collect();
        }

        // Single query: per (tenant_id, source) → latest date + windowed row count.
        // Using a subquery for max_date + a CASE-based conditional count avoids
        // a second round-trip and keeps the N+1 guarantee.
        $rows = DB::table('seo_metrics')
            ->selectRaw('
                tenant_id,
                source,
                MAX(metric_date) AS max_date,
                SUM(CASE WHEN metric_date >= ? THEN 1 ELSE 0 END) AS windowed_count
            ', [$windowStart])
            ->whereIn('tenant_id', $tenantIds)
            ->groupBy('tenant_id', 'source')
            ->get();

        // Group by tenant_id for O(1) lookup in the loop.
        return $rows->groupBy('tenant_id');
    }

    /**
     * Compute the health status for one source on one tenant.
     *
     * @param  string                                                      $source     'gsc' or 'ga4'
     * @param  bool                                                        $configured Whether the source's config key is set.
     * @param  \Illuminate\Support\Collection<int, \stdClass>              $tenantAgg  Pre-fetched aggregate rows for this tenant.
     * @param  CarbonImmutable                                             $today      Reference "now".
     * @return array{string, string|null, int|null, int|null}              [status, latestDateStr, ageDays, windowedCount]
     */
    private function computeSourceStatus(
        string $source,
        bool $configured,
        \Illuminate\Support\Collection $tenantAgg,
        CarbonImmutable $today,
    ): array {
        if (! $configured) {
            return [self::UNCONFIGURED, null, null, null];
        }

        // Find the aggregate row for this source.
        $agg = $tenantAgg->firstWhere('source', $source);

        if ($agg === null || $agg->max_date === null) {
            return [self::NO_DATA, null, null, 0];
        }

        $latestDate = $agg->max_date;                       // 'Y-m-d' string
        $rowCount   = (int) $agg->windowed_count;

        $latestCarbon = CarbonImmutable::createFromFormat('Y-m-d', $latestDate, 'UTC');
        $ageDays      = (int) $latestCarbon->startOfDay()->diffInDays($today->startOfDay());

        $staleThreshold = match ($source) {
            'gsc'   => self::GSC_STALE_DAYS,
            'ga4'   => self::GA4_STALE_DAYS,
            default => 2,
        };

        $status = $ageDays > $staleThreshold ? self::STALE : self::HEALTHY;

        return [$status, $latestDate, $ageDays, $rowCount];
    }
}
