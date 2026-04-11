<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pulls Google Analytics 4 per-landing-page metrics for a single tenant and date,
 * then upserts them into seo_metrics.
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

            $batch = $rows->map(fn (array $row) => [
                'tenant_id'        => $tenant->getTenantKey(),
                'source'           => 'ga4',
                'url'              => $row['url'],
                'url_hash'         => hash('sha256', $row['url']),
                'metric_date'      => $date->format('Y-m-d'),
                'ga4_sessions'     => $row['sessions'],
                'ga4_users'        => $row['users'],
                'ga4_engaged_sess' => $row['engaged_sessions'],
                'ga4_conversions'  => $row['conversions'],
                'ga4_bounce_rate'  => $row['bounce_rate'],
                'updated_at'       => now(),
                'created_at'       => now(),
            ])->all();

            if (empty($batch)) {
                return new PullResult(upserted: 0, errors: []);
            }

            DB::table('seo_metrics')->upsert(
                $batch,
                ['tenant_id', 'source', 'url_hash', 'metric_date'],
                [
                    'url',
                    'ga4_sessions',
                    'ga4_users',
                    'ga4_engaged_sess',
                    'ga4_conversions',
                    'ga4_bounce_rate',
                    'updated_at',
                ],
            );

            $upserted = count($batch);
        } catch (\Throwable $e) {
            return new PullResult(
                upserted: $upserted,
                errors: [$e->getMessage()],
            );
        }

        return new PullResult(upserted: $upserted, errors: []);
    }
}
