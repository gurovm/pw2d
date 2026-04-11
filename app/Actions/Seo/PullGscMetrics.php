<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pulls Google Search Console per-URL metrics for a single tenant and date,
 * then upserts them into seo_metrics.
 *
 * Designed to run inside tenancy context (tenancy()->initialize() must have been
 * called before execute()). The service class can be swapped in tests by binding
 * a fake into the container:
 *
 *   app()->bind(GoogleSearchConsoleService::class, fn () => new FakeGscService());
 */
final class PullGscMetrics
{
    /**
     * Execute the GSC pull for a single tenant + date.
     *
     * Returns a PullResult carrying the count of upserted rows and any errors.
     * Never throws — all exceptions are caught and surfaced in PullResult::$errors.
     *
     * @param Tenant          $tenant The tenant to pull data for.
     * @param CarbonImmutable $date   The calendar day to pull.
     */
    public function execute(Tenant $tenant, CarbonImmutable $date): PullResult
    {
        // Read config from the tenant's JSON data bag.
        // tenancy() must already be initialized for tenant('key') to work.
        $siteUrl = tenant('gsc_site_url');

        if (empty($siteUrl)) {
            return new PullResult(
                upserted: 0,
                errors: ['missing config key: gsc_site_url'],
            );
        }

        $serviceAccountPath = config('seo.google.service_account_path');
        $upserted = 0;

        try {
            // Resolve through the container so tests can swap in a fake.
            $service = app(GoogleSearchConsoleService::class, [
                'siteUrl'            => $siteUrl,
                'serviceAccountPath' => $serviceAccountPath,
            ]);

            $rows = $service->fetchUrlMetrics($date);

            // Build the batch for a single upsert call — far cheaper than
            // N individual updateOrCreate() calls for large URL sets.
            $batch = $rows->map(fn (array $row) => [
                'tenant_id'       => $tenant->getTenantKey(),
                'source'          => 'gsc',
                'url'             => $row['url'],
                'url_hash'        => hash('sha256', $row['url']),
                'metric_date'     => $date->format('Y-m-d'),
                'gsc_impressions' => $row['impressions'],
                'gsc_clicks'      => $row['clicks'],
                'gsc_ctr'         => $row['ctr'],
                'gsc_position'    => $row['position'],
                'gsc_top_query'   => $row['top_query'],
                'updated_at'      => now(),
                'created_at'      => now(),
            ])->all();

            if (empty($batch)) {
                return new PullResult(upserted: 0, errors: []);
            }

            // The unique constraint (tenant_id, source, url_hash, metric_date)
            // ensures re-running the same day is idempotent.
            DB::table('seo_metrics')->upsert(
                $batch,
                ['tenant_id', 'source', 'url_hash', 'metric_date'],  // unique keys
                [   // columns to update on conflict
                    'url',
                    'gsc_impressions',
                    'gsc_clicks',
                    'gsc_ctr',
                    'gsc_position',
                    'gsc_top_query',
                    'updated_at',
                ],
            );

            $upserted = count($batch);
        } catch (\Throwable $e) {
            return PullResult::fromThrowable($e, $upserted);
        }

        return new PullResult(upserted: $upserted, errors: []);
    }
}
