<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Carbon\CarbonImmutable;
use Google_Client;
use Google_Service_Webmasters;
use Google_Service_Webmasters_SearchAnalyticsQueryRequest;
use Illuminate\Support\Collection;

/**
 * Reads per-URL search performance metrics from the Google Search Console API.
 *
 * The underlying Google client is created via the protected makeClient() method
 * so that tests can subclass this service and return a fake client without
 * touching the network.
 *
 * Usage:
 *   $service = new GoogleSearchConsoleService($siteUrl, $serviceAccountPath);
 *   $rows = $service->fetchUrlMetrics(CarbonImmutable::yesterday());
 */
class GoogleSearchConsoleService
{
    /**
     * @param string $siteUrl           GSC property URL, e.g. "sc-domain:pw2d.com"
     *                                  or "https://pw2d.com/" (trailing slash required).
     * @param string $serviceAccountPath Absolute path to the Google service-account JSON key.
     */
    public function __construct(
        private readonly string $siteUrl,
        private readonly string $serviceAccountPath,
    ) {}

    /**
     * Fetch per-URL search performance metrics for a single calendar day.
     *
     * Returns one row per URL found in GSC for the given date. The `top_query`
     * field is currently left null — a per-URL top-query pass would require one
     * extra API call per URL (up to 500 × the chunk size), which would risk rate
     * limiting. This is tracked as F7 follow-up.
     *
     * @param  CarbonImmutable $date The calendar day to pull (UTC).
     * @return Collection<int, array{url: string, impressions: int, clicks: int, ctr: float, position: float, top_query: string|null}>
     *
     * @throws \Google_Service_Exception  On API-level errors (quota, auth).
     * @throws \Google_Exception          On client setup failures.
     */
    public function fetchUrlMetrics(CarbonImmutable $date): Collection
    {
        $client  = $this->makeClient();
        $service = new Google_Service_Webmasters($client);

        $dateStr = $date->format('Y-m-d');

        $request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
        $request->setStartDate($dateStr);
        $request->setEndDate($dateStr);
        $request->setDimensions(['page']);
        $request->setRowLimit((int) config('seo.pull.chunk_size', 500));

        $response = $service->searchanalytics->query($this->siteUrl, $request);

        $rows = collect($response->getRows() ?? []);

        return $rows->map(function (mixed $row) {
            // The GSC response shape: keys[0] = page URL, plus aggregate metrics.
            $url = data_get($row, 'keys.0') ?? data_get($row, 'keys[0]') ?? '';

            // Google_Service_Webmasters_ApiDataRow objects expose getters.
            if (is_object($row) && method_exists($row, 'getKeys')) {
                $keys      = $row->getKeys();
                $url       = $keys[0] ?? '';
                $clicks    = (int) $row->getClicks();
                $impressions = (int) $row->getImpressions();
                $ctr       = (float) $row->getCtr();
                $position  = (float) $row->getPosition();
            } else {
                // Array fallback (used by fake clients in tests)
                $url         = data_get($row, 'keys.0', '');
                $clicks      = (int) data_get($row, 'clicks', 0);
                $impressions = (int) data_get($row, 'impressions', 0);
                $ctr         = (float) data_get($row, 'ctr', 0.0);
                $position    = (float) data_get($row, 'position', 0.0);
            }

            return [
                'url'         => $url,
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'ctr'         => $ctr,
                'position'    => $position,
                'top_query'   => null, // F7: per-URL top-query requires a second dimension pass
            ];
        })->values();
    }

    /**
     * Build and configure the authenticated Google API client.
     *
     * Protected so tests can override and return a fake/mock client without
     * making any network requests.
     *
     * @throws \Google_Exception On credential file errors.
     */
    protected function makeClient(): Google_Client
    {
        $client = new Google_Client();
        $client->setAuthConfig($this->serviceAccountPath);
        $client->setScopes([Google_Service_Webmasters::WEBMASTERS_READONLY]);

        return $client;
    }
}
