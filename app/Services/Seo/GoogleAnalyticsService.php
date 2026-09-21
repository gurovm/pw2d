<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Carbon\CarbonImmutable;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter\MatchType;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Illuminate\Support\Collection;

/**
 * Reads per-landing-page metrics and outbound (buy-button) click counts from
 * the Google Analytics 4 Data API.
 *
 * The underlying GA4 client is created via the protected makeClient() method
 * so that tests can subclass this service and return a fake client without
 * touching the network.
 *
 * Usage:
 *   $service = new GoogleAnalyticsService('properties/123456789', $serviceAccountPath);
 *   $rows   = $service->fetchLandingPageMetrics(CarbonImmutable::yesterday());
 *   $clicks = $service->fetchOutboundClicks(CarbonImmutable::yesterday());
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
            $path            = $this->extractDimensionValue($row);
            $sessions        = (int) $this->extractMetricValue($row, 0, 'sessions');
            $users           = (int) $this->extractMetricValue($row, 1, 'users');
            $engagedSessions = (int) $this->extractMetricValue($row, 2, 'engaged_sessions');
            $conversions     = (int) $this->extractMetricValue($row, 3, 'conversions');
            $bounceRate      = $this->extractMetricValue($row, 4, 'bounce_rate');

            // GA4 landing page paths are relative ("/compare/espresso"). Prepend
            // nothing here — the action that calls this service can prefix the
            // tenant domain if needed for cross-source URL matching.
            return [
                'url'              => $path,
                'sessions'         => $sessions,
                'users'            => $users,
                'engaged_sessions' => $engagedSessions,
                'conversions'      => $conversions,
                'bounce_rate'      => $bounceRate,
            ];
        })->values();
    }

    /**
     * Fetch outbound (buy-button) click counts for a single calendar day.
     *
     * GA4 Enhanced Measurement automatically fires a `click` event for every
     * outbound link — this is the "site → store" step the whole project exists
     * to move (Spec 040). Filtered to `eventName = click` (EXACT match).
     *
     * Uses `pagePath`, matching the `landingPage` dimension used above — both
     * are path-only, so `url`/`url_hash` are byte-identical between the two
     * methods for the same page and PullGa4Metrics can merge their rows by
     * URL. A query-string-carrying dimension here would fork a click on
     * `/compare/x?preset=streamer` away from the `/compare/x` landing row, and
     * let tracking params (`?srsltid=`, `?fbclid=`) fragment the count.
     *
     * @param  CarbonImmutable $date The calendar day to pull (UTC).
     * @return Collection<int, array{url: string, clicks: int}>
     *
     * @throws \Google\ApiCore\ApiException On API-level errors (quota, auth, invalid property).
     */
    public function fetchOutboundClicks(CarbonImmutable $date): Collection
    {
        $client  = $this->makeClient();
        $dateStr = $date->format('Y-m-d');

        $request = new RunReportRequest([
            'property'   => $this->propertyId,
            'dimensions' => [new Dimension(['name' => 'pagePath'])],
            'metrics'    => [new Metric(['name' => 'eventCount'])],
            'dimension_filter' => new FilterExpression([
                'filter' => new Filter([
                    'field_name'    => 'eventName',
                    'string_filter' => new StringFilter([
                        'match_type' => MatchType::EXACT,
                        'value'      => 'click',
                    ]),
                ]),
            ]),
            'date_ranges' => [
                new DateRange(['start_date' => $dateStr, 'end_date' => $dateStr]),
            ],
            'limit' => (int) config('seo.pull.chunk_size', 500),
        ]);

        $response = $client->runReport($request);
        $client->close();

        $rows = collect($response->getRows());

        return $rows->map(function (mixed $row) {
            $path   = $this->extractDimensionValue($row);
            $clicks = (int) $this->extractMetricValue($row, 0, 'clicks');

            return [
                'url'    => $path,
                'clicks' => $clicks,
            ];
        })->values();
    }

    /**
     * Extract the first dimension value from a GA4 report row.
     *
     * Shared by fetchLandingPageMetrics() and fetchOutboundClicks() so the two
     * methods read the page path identically — their `url` output must be
     * byte-for-byte the same for a given page so PullGa4Metrics can merge rows
     * from both calls by URL.
     *
     * Handles both the real SDK row object and the flexible array shape used
     * by fake clients in tests.
     */
    private function extractDimensionValue(mixed $row): string
    {
        if (is_object($row) && method_exists($row, 'getDimensionValues')) {
            $dimValues = $row->getDimensionValues();

            $value = data_get($dimValues, '0') ?? '';
            if (is_object($value) && method_exists($value, 'getValue')) {
                $value = $value->getValue();
            }

            return (string) $value;
        }

        // Array fallback (used by fake clients in tests)
        return (string) data_get($row, 'dimensions.0', '');
    }

    /**
     * Extract a single metric value from a GA4 report row.
     *
     * $index selects the metric by its declared position in the request (real
     * SDK rows carry metrics positionally). $fallbackKey selects the same
     * metric by name in the flexible array shape used by fake clients in tests.
     */
    private function extractMetricValue(mixed $row, int $index, string $fallbackKey): float
    {
        if (is_object($row) && method_exists($row, 'getMetricValues')) {
            $metricValues = $row->getMetricValues();
            $mv           = data_get($metricValues, $index);

            if ($mv && is_object($mv) && method_exists($mv, 'getValue')) {
                return (float) $mv->getValue();
            }

            return 0.0;
        }

        // Array fallback (used by fake clients in tests)
        return (float) data_get($row, "metrics.{$fallbackKey}", 0.0);
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
