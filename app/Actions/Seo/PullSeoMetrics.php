<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Orchestrates a full per-tenant SEO data pull (GSC + GA4).
 *
 * Initializes tenancy for the given tenant, runs both child actions, then
 * ends tenancy in a finally block so errors in one source never leave the
 * scheduler in tenant context.
 *
 * Error isolation: if GSC fails, GA4 still runs. Errors are aggregated in the
 * returned PullSeoMetricsResult so the command can report partial failures
 * without masking partial successes.
 */
final class PullSeoMetrics
{
    /**
     * Execute a full SEO pull for a single tenant and date.
     *
     * @param Tenant          $tenant The tenant to pull data for.
     * @param CarbonImmutable $date   The calendar day to pull.
     */
    public function execute(Tenant $tenant, CarbonImmutable $date): PullSeoMetricsResult
    {
        tenancy()->initialize($tenant);

        $gsc = null;
        $ga4 = null;

        try {
            $gsc = (new PullGscMetrics)->execute($tenant, $date);
            $ga4 = (new PullGa4Metrics)->execute($tenant, $date);
        } finally {
            tenancy()->end();
        }

        // Both child actions should always return a PullResult (they catch all
        // Throwable internally), but we guard against null just in case.
        $gscResult = $gsc ?? new PullResult(upserted: 0, errors: ['GSC action did not complete']);
        $ga4Result = $ga4 ?? new PullResult(upserted: 0, errors: ['GA4 action did not complete']);

        return new PullSeoMetricsResult(
            tenantId: (string) $tenant->getTenantKey(),
            date: $date,
            gscRowsUpserted: $gscResult->upserted,
            ga4RowsUpserted: $ga4Result->upserted,
            errors: [...$gscResult->errors, ...$ga4Result->errors],
        );
    }
}
