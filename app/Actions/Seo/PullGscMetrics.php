<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls Google Search Console per-URL metrics for a single tenant over a date
 * window, then upserts them into seo_metrics.
 *
 * Designed to run inside tenancy context (tenancy()->initialize() must have been
 * called before execute()). The service class can be swapped in tests by binding
 * a fake into the container:
 *
 *   app()->bind(GoogleSearchConsoleService::class, fn () => new FakeGscService());
 *
 * IMPORTANT — atomicity trade-off (F23):
 * The entire date window is fetched in a single GSC API call. This means a single
 * API failure causes ALL requested dates to fail — there is no per-date isolation.
 * The upside is a 4× reduction in API calls for the default 4-day window.
 * GA4 retains per-date isolation because it uses a separate action with its own loop.
 */
final class PullGscMetrics
{
    /**
     * Execute the GSC pull for a single tenant over a window of dates.
     *
     * Sorts $dates ascending, derives startDate/endDate for a single ranged
     * GSC API call, then upserts each date's rows individually. Per-date upsert
     * counts are available in PullResult::$dailyCounts for the orchestrator to
     * surface in verbose output.
     *
     * Returns a PullResult carrying the total upserted row count, any errors,
     * and a per-date breakdown in dailyCounts.
     * Never throws — all exceptions are caught and surfaced in PullResult::$errors.
     *
     * @param Tenant                      $tenant The tenant to pull data for.
     * @param array<int, CarbonImmutable> $dates  One or more calendar days to pull.
     */
    public function execute(Tenant $tenant, array $dates): PullResult
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

        if (empty($dates)) {
            return new PullResult(upserted: 0, errors: []);
        }

        $serviceAccountPath = config('seo.google.service_account_path');

        /** @var array<string, int> $dailyCounts */
        $dailyCounts = [];
        $upserted    = 0;

        try {
            // Derive the contiguous range from the supplied dates.
            $sorted    = collect($dates)->sort(fn (CarbonImmutable $a, CarbonImmutable $b) => $a->timestamp <=> $b->timestamp)->values();
            $startDate = $sorted->first();
            $endDate   = $sorted->last();

            // Resolve through the container so tests can swap in a fake.
            $service = app(GoogleSearchConsoleService::class, [
                'siteUrl'            => $siteUrl,
                'serviceAccountPath' => $serviceAccountPath,
            ]);

            // Call 1: single ranged API call for the full window (page-level totals).
            $buckets = $service->fetchUrlMetricsForRange($startDate, $endDate);

            // Call 2: per-(date, URL) top query — isolated so its failure cannot
            // block the main pull. If this throws, we log a warning and continue
            // with gsc_top_query = null for all rows in this window.
            $topQueriesByDateUrl = [];

            try {
                $topQueryBuckets = $service->fetchTopQueriesForRange($startDate, $endDate);

                // Build a two-level lookup: $topQueriesByDateUrl[$dateStr][$url] = $query
                $topQueryBuckets->each(function ($perUrlRows, string $dateStr) use (&$topQueriesByDateUrl): void {
                    $topQueriesByDateUrl[$dateStr] = [];
                    foreach ($perUrlRows as $entry) {
                        $topQueriesByDateUrl[$dateStr][$entry['url']] = $entry['top_query'];
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('PullGscMetrics: top queries failed — continuing without top_query', [
                    'tenant'    => $tenant->getTenantKey(),
                    'exception' => $e->getMessage(),
                ]);
                $topQueriesByDateUrl = [];
            }

            // Upsert each requested date individually so the unique constraint
            // (tenant_id, source, url_hash, metric_date) remains idempotent.
            foreach ($sorted as $date) {
                $dateKey = $date->format('Y-m-d');
                $rows    = $buckets->get($dateKey, collect());

                if ($rows->isEmpty()) {
                    // GSC had no data for this date — skip noisy zero-row upsert.
                    $dailyCounts[$dateKey] = 0;
                    continue;
                }

                $batch = $rows->map(fn (array $row) => [
                    'tenant_id'       => $tenant->getTenantKey(),
                    'source'          => 'gsc',
                    'url'             => $row['url'],
                    'url_hash'        => hash('sha256', $row['url']),
                    'metric_date'     => $dateKey,
                    'gsc_impressions' => $row['impressions'],
                    'gsc_clicks'      => $row['clicks'],
                    'gsc_ctr'         => $row['ctr'],
                    'gsc_position'    => $row['position'],
                    'gsc_top_query'   => $topQueriesByDateUrl[$dateKey][$row['url']] ?? null,
                    'updated_at'      => now(),
                    'created_at'      => now(),
                ])->all();

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

                $dateCount             = count($batch);
                $dailyCounts[$dateKey] = $dateCount;
                $upserted             += $dateCount;
            }
        } catch (\Throwable $e) {
            return PullResult::fromThrowable($e, $upserted, $dailyCounts);
        }

        return new PullResult(upserted: $upserted, errors: [], dailyCounts: $dailyCounts);
    }
}
