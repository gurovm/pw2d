<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a full per-tenant SEO data pull (GSC + GA4).
 *
 * Initializes tenancy for the given tenant, iterates the supplied date windows
 * for each source, then ends tenancy in a finally block so errors in one source
 * never leave the scheduler in tenant context.
 *
 * Error isolation:
 * - If GSC fails for one date, the remaining GSC dates still run.
 * - If GSC fails entirely, GA4 still runs.
 * - Partial failures are aggregated in the returned PullSeoMetricsResult.
 *
 * Idempotency: child actions use DB::upsert(), so re-running for the same date
 * is safe and cheap.
 */
final class PullSeoMetrics
{
    /**
     * Execute a full SEO pull for a single tenant over rolling date windows.
     *
     * @param Tenant                   $tenant    The tenant to pull data for.
     * @param array<int, CarbonImmutable> $gscDates Dates to pull for GSC — each date is upserted individually.
     * @param array<int, CarbonImmutable> $ga4Dates Dates to pull for GA4 — each date is upserted individually.
     */
    public function execute(Tenant $tenant, array $gscDates, array $ga4Dates): PullSeoMetricsResult
    {
        tenancy()->initialize($tenant);

        /** @var array<string, int> $gscDailyCounts */
        $gscDailyCounts = [];
        /** @var array<string, int> $ga4DailyCounts */
        $ga4DailyCounts = [];
        /** @var string[] $errors */
        $errors = [];

        try {
            // ── GSC window ───────────────────────────────────────────────────
            foreach ($gscDates as $date) {
                try {
                    $result = (new PullGscMetrics)->execute($tenant, $date);
                    $dateKey = $date->format('Y-m-d');
                    $gscDailyCounts[$dateKey] = $result->upserted;
                    array_push($errors, ...$result->errors);
                } catch (\Throwable $e) {
                    $dateKey = $date->format('Y-m-d');
                    $gscDailyCounts[$dateKey] = 0;
                    $errors[] = "GSC [{$dateKey}]: " . $e->getMessage();
                    Log::warning('PullSeoMetrics: GSC date failed — continuing', [
                        'tenant_id' => $tenant->getTenantKey(),
                        'date'      => $dateKey,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            // ── GA4 window ───────────────────────────────────────────────────
            foreach ($ga4Dates as $date) {
                try {
                    $result = (new PullGa4Metrics)->execute($tenant, $date);
                    $dateKey = $date->format('Y-m-d');
                    $ga4DailyCounts[$dateKey] = $result->upserted;
                    array_push($errors, ...$result->errors);
                } catch (\Throwable $e) {
                    $dateKey = $date->format('Y-m-d');
                    $ga4DailyCounts[$dateKey] = 0;
                    $errors[] = "GA4 [{$dateKey}]: " . $e->getMessage();
                    Log::warning('PullSeoMetrics: GA4 date failed — continuing', [
                        'tenant_id' => $tenant->getTenantKey(),
                        'date'      => $dateKey,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            tenancy()->end();
        }

        // Compute the latest date across both windows for backward-compat callers.
        $allDates = [...$gscDates, ...$ga4Dates];
        $latestDate = array_reduce(
            $allDates,
            fn (?CarbonImmutable $carry, CarbonImmutable $d) => $carry === null || $d->greaterThan($carry) ? $d : $carry,
            null,
        ) ?? CarbonImmutable::today('UTC');

        return new PullSeoMetricsResult(
            tenantId: (string) $tenant->getTenantKey(),
            date: $latestDate,
            gscRowsUpserted: (int) array_sum($gscDailyCounts),
            ga4RowsUpserted: (int) array_sum($ga4DailyCounts),
            errors: $errors,
            gscDailyCounts: $gscDailyCounts,
            ga4DailyCounts: $ga4DailyCounts,
        );
    }
}
