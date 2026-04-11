<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Carbon\CarbonImmutable;
use Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Illuminate\Support\Collection;

/**
 * Reads per-landing-page metrics from the Google Analytics 4 Data API.
 *
 * The underlying GA4 client is created via the protected makeClient() method
 * so that tests can subclass this service and return a fake client without
 * touching the network.
 *
 * Usage:
 *   $service = new GoogleAnalyticsService('properties/123456789', $serviceAccountPath);
 *   $rows = $service->fetchLandingPageMetrics(CarbonImmutable::yesterday());
 */
class GoogleAnalyticsService
{
    /**
     * @param string $propertyId        GA4 property ID, e.g. "properties/123456789".
     * @param string $serviceAccountPath Absolute path to the Google service-account JSON key.
     */
    public function __construct(
        private readonly string $propertyId,
        private readonly string $serviceAccountPath,
    ) {}

    /**
     * Fetch per-landing-page metrics for a single calendar day.
     *
     * Uses the GA4 Data API v1beta. The `keyEvents` metric is GA4's replacement
     * for "conversions" (goals). Zero-session landing pages are excluded by default
     * by the GA4 API.
     *
     * @param  CarbonImmutable $date The calendar day to pull (UTC).
     * @return Collection<int, array{url: string, sessions: int, users: int, engaged_sessions: int, conversions: int, bounce_rate: float}>
     *
     * @throws \Google\ApiCore\ApiException On API-level errors (quota, auth, invalid property).
     */
    public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
    {
        $client  = $this->makeClient();
        $dateStr = $date->format('Y-m-d');

        $request = new RunReportRequest([
            'property'   => $this->propertyId,
            'dimensions' => [new Dimension(['name' => 'landingPage'])],
            'metrics'    => [
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'totalUsers']),
                new Metric(['name' => 'engagedSessions']),
                new Metric(['name' => 'keyEvents']),     // GA4 replacement for conversions
                new Metric(['name' => 'bounceRate']),
            ],
            'date_ranges' => [
                new DateRange(['start_date' => $dateStr, 'end_date' => $dateStr]),
            ],
            'limit' => (int) config('seo.pull.chunk_size', 500),
        ]);

        $response = $client->runReport($request);
        $client->close();

        $rows = collect($response->getRows());

        return $rows->map(function (mixed $row) {
            // GA4 response: dimension values[0] = landingPage path
            // metric values correspond to the order declared above.
            if (is_object($row) && method_exists($row, 'getDimensionValues')) {
                $dimValues    = $row->getDimensionValues();
                $metricValues = $row->getMetricValues();

                $path         = data_get($dimValues, '0') ?? '';
                if (is_object($path) && method_exists($path, 'getValue')) {
                    $path = $path->getValue();
                }

                $getMetric = function (int $idx) use ($metricValues): float {
                    $mv = data_get($metricValues, $idx);
                    if ($mv && is_object($mv) && method_exists($mv, 'getValue')) {
                        return (float) $mv->getValue();
                    }

                    return 0.0;
                };

                $sessions       = (int) $getMetric(0);
                $users          = (int) $getMetric(1);
                $engagedSessions = (int) $getMetric(2);
                $conversions    = (int) $getMetric(3);
                $bounceRate     = $getMetric(4);
            } else {
                // Array fallback (used by fake clients in tests)
                $path            = data_get($row, 'dimensions.0', '');
                $sessions        = (int) data_get($row, 'metrics.sessions', 0);
                $users           = (int) data_get($row, 'metrics.users', 0);
                $engagedSessions = (int) data_get($row, 'metrics.engaged_sessions', 0);
                $conversions     = (int) data_get($row, 'metrics.conversions', 0);
                $bounceRate      = (float) data_get($row, 'metrics.bounce_rate', 0.0);
            }

            // GA4 landing page paths are relative ("/compare/espresso"). Prepend
            // nothing here — the action that calls this service can prefix the
            // tenant domain if needed for cross-source URL matching.
            return [
                'url'              => (string) $path,
                'sessions'         => $sessions,
                'users'            => $users,
                'engaged_sessions' => $engagedSessions,
                'conversions'      => $conversions,
                'bounce_rate'      => $bounceRate,
            ];
        })->values();
    }

    /**
     * Build and configure the authenticated GA4 Data API client.
     *
     * Protected so tests can override and return a fake/mock client without
     * making any network requests.
     *
     * @throws \Google\ApiCore\ValidationException On credential configuration errors.
     */
    protected function makeClient(): BetaAnalyticsDataClient
    {
        return new BetaAnalyticsDataClient([
            'credentials' => $this->serviceAccountPath,
        ]);
    }
}
