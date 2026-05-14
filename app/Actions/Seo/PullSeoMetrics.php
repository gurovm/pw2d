<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a full per-tenant SEO data pull (GSC + GA4).
 *
 * Initializes tenancy for the given tenant, issues the API calls for each
 * source over the supplied date windows, then ends tenancy in a finally block
 * so errors in one source never leave the scheduler in tenant context.
 *
 * Error isolation model (post-F23):
 * - GSC: the entire window is fetched in ONE API call (F23). A failure means
 *   ALL requested GSC dates fail — there is no per-date isolation for GSC.
 *   This is an intentional trade-off: the win is 4× fewer API calls per tenant.
 * - GA4: retains per-date isolation — each date is fetched/upserted individually,
 *   so a failure on one date does not block the others.
 * - If GSC fails entirely, GA4 still runs (outer try/catch boundaries are separate).
 *
 * Idempotency: child actions use DB::upsert(), so re-running for the same date
 * is safe and cheap.
 */
final class PullSeoMetrics
{
    /**
     * Execute a full SEO pull for a single tenant over rolling date windows.
     *
     * @param Tenant                      $tenant    The tenant to pull data for.
     * @param array<int, CarbonImmutable> $gscDates  Dates to pull for GSC — fetched in a single ranged API call.
     * @param array<int, CarbonImmutable> $ga4Dates  Dates to pull for GA4 — each date is upserted individually.
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
            // ── GSC window — single ranged API call for the full window (F23) ──
            try {
                $gscResult      = (new PullGscMetrics)->execute($tenant, $gscDates);
                $gscDailyCounts = $gscResult->dailyCounts;
                array_push($errors, ...$gscResult->errors);
            } catch (\Throwable $e) {
                // Populate dailyCounts with zeros for all requested dates so
                // downstream consumers never see a missing key.
                foreach ($gscDates as $date) {
                    $gscDailyCounts[$date->format('Y-m-d')] = 0;
                }
                $errors[] = 'GSC: ' . $e->getMessage();
                Log::warning('PullSeoMetrics: GSC window failed', [
                    'tenant_id' => $tenant->getTenantKey(),
                    'error'     => $e->getMessage(),
                ]);
            }

            // ── GA4 window — per-date isolation retained ─────────────────────
            foreach ($ga4Dates as $date) {
                try {
                    $result  = (new PullGa4Metrics)->execute($tenant, $date);
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
        $allDates   = [...$gscDates, ...$ga4Dates];
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
