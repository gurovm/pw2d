<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls Google Analytics 4 per-landing-page metrics AND outbound (buy-button)
 * click counts for a single tenant and date, merges the two by URL, then
 * upserts the combined batch into seo_metrics.
 *
 * Designed to run inside tenancy context (tenancy()->initialize() must have been
 * called before execute()). The service class can be swapped in tests by binding
 * a fake into the container:
 *
 *   app()->bind(GoogleAnalyticsService::class, fn () => new FakeGa4Service());
 */
final class PullGa4Metrics
{
    /**
     * Execute the GA4 pull for a single tenant + date.
     *
     * Returns a PullResult carrying the count of upserted rows and any errors.
     * Never throws — all exceptions are caught and surfaced in PullResult::$errors.
     *
     * @param Tenant          $tenant The tenant to pull data for.
     * @param CarbonImmutable $date   The calendar day to pull.
     */
    public function execute(Tenant $tenant, CarbonImmutable $date): PullResult
    {
        $propertyId = tenant('ga4_property_id');

        if (empty($propertyId)) {
            return new PullResult(
                upserted: 0,
                errors: ['missing config key: ga4_property_id'],
            );
        }

        $serviceAccountPath = config('seo.google.service_account_path');
        $upserted = 0;

        try {
            // Resolve through the container so tests can swap in a fake.
            $service = app(GoogleAnalyticsService::class, [
                'propertyId'         => $propertyId,
                'serviceAccountPath' => $serviceAccountPath,
            ]);

            $rows = $service->fetchLandingPageMetrics($date);

            // Keyed by URL so outbound clicks can be merged in below without a
            // second upsert — a second upsert could zero out the session columns
            // just written for a URL that isn't also in this batch.
            $batch = $rows->keyBy('url')->map(fn (array $row) => $this->buildRow($tenant, $date, $row['url'], [
                'ga4_sessions'     => $row['sessions'],
                'ga4_users'        => $row['users'],
                'ga4_engaged_sess' => $row['engaged_sessions'],
                'ga4_conversions'  => $row['conversions'],
                'ga4_bounce_rate'  => $row['bounce_rate'],
            ]))->all();

            // Outbound (buy-button) clicks — isolated in its own try/catch so a
            // failure here cannot block the landing-page pull (same isolation
            // pattern as PullGscMetrics' top-query lookup: log and carry on).
            // $clicksByUrl stays null on failure. Only the FETCH happens inside
            // this try — the merge into $batch happens after it, once the
            // try/catch has fully finished, so a throw mid-fetch can never leave
            // $batch half-merged.
            $clicksByUrl = null;

            try {
                $clicksByUrl = $service->fetchOutboundClicks($date)->pluck('clicks', 'url')->all();
            } catch (\Throwable $e) {
                Log::warning('PullGa4Metrics: outbound clicks failed — continuing without ga4_outbound_clicks', [
                    'tenant'    => $tenant->getTenantKey(),
                    'date'      => $date->format('Y-m-d'),
                    'exception' => $e->getMessage(),
                ]);
            }

            if ($clicksByUrl !== null) {
                foreach ($clicksByUrl as $url => $clicks) {
                    if (isset($batch[$url])) {
                        $batch[$url]['ga4_outbound_clicks'] = $clicks;
                        continue;
                    }

                    // Had a click but wasn't a landing page that day — zeroed session metrics.
                    $batch[$url] = $this->buildRow($tenant, $date, $url, [
                        'ga4_outbound_clicks' => $clicks,
                    ]);
                }
            }

            $batch = array_values($batch);

            if (empty($batch)) {
                return new PullResult(upserted: 0, errors: []);
            }

            $updateColumns = [
                'url',
                'ga4_sessions',
                'ga4_users',
                'ga4_engaged_sess',
                'ga4_conversions',
                'ga4_bounce_rate',
                'updated_at',
            ];

            // Only overwrite the stored click count when this pull actually fetched
            // a fresh one (including a successful fetch that legitimately found zero
            // clicks — $clicksByUrl is an empty array, not null, then) — otherwise a
            // re-pull (e.g. a window backfill) would clobber an existing good value
            // with the batch's seeded 0. Fresh inserts get that same seeded 0 either
            // way, from buildRow()'s default — not from the column's DB default,
            // which is never involved in an INSERT that names every column.
            if ($clicksByUrl !== null) {
                $updateColumns[] = 'ga4_outbound_clicks';
            }

            DB::table('seo_metrics')->upsert(
                $batch,
                ['tenant_id', 'source', 'url_hash', 'metric_date'],
                $updateColumns,
            );

            $upserted = count($batch);
        } catch (\Throwable $e) {
            return PullResult::fromThrowable($e, $upserted);
        }

        return new PullResult(upserted: $upserted, errors: []);
    }

    /**
     * Build one seo_metrics row for a GA4 URL: a zeroed baseline with $overrides
     * merged on top.
     *
     * Used for BOTH landing-page rows (all five session metrics overridden) and
     * click-only rows (just `ga4_outbound_clicks` overridden) so the row's
     * 13-key shape exists in exactly one place. `DB::table()->upsert()` derives
     * its INSERT column list from the first row in the batch — a column added
     * to only one of two separate literals would silently misalign or fail the
     * statement the day someone adds a 14th key.
     *
     * @param array<string, int|float> $overrides Column => value, merged over the zeroed baseline.
     */
    private function buildRow(Tenant $tenant, CarbonImmutable $date, string $url, array $overrides = []): array
    {
        return array_merge([
            'tenant_id'           => $tenant->getTenantKey(),
            'source'              => 'ga4',
            'url'                 => $url,
            'url_hash'            => hash('sha256', $url),
            'metric_date'         => $date->format('Y-m-d'),
            'ga4_sessions'        => 0,
            'ga4_users'           => 0,
            'ga4_engaged_sess'    => 0,
            'ga4_conversions'     => 0,
            'ga4_bounce_rate'     => 0.0,
            'ga4_outbound_clicks' => 0,
            'updated_at'          => now(),
            'created_at'          => now(),
        ], $overrides);
    }
}
