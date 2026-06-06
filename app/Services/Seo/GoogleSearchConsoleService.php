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
     * @deprecated Use fetchUrlMetricsForRange() for new callers. This method is
     *             preserved for back-compat and delegates to the range variant.
     *
     * @param  CarbonImmutable $date The calendar day to pull (UTC).
     * @return Collection<int, array{url: string, impressions: int, clicks: int, ctr: float, position: float, top_query: string|null}>
     *
     * @throws \Google_Service_Exception  On API-level errors (quota, auth).
     * @throws \Google_Exception          On client setup failures.
     */
    public function fetchUrlMetrics(CarbonImmutable $date): Collection
    {
        // Thin wrapper — delegate to the range variant and unwrap the single-date bucket.
        $dateStr = $date->format('Y-m-d');

        return $this->fetchUrlMetricsForRange($date, $date)->get($dateStr) ?? collect();
    }

    /**
     * Fetch per-(date, URL) GSC metrics for a date range in a single API call.
     *
     * Adds the 'date' dimension to the query so the response contains per-day
     * rows. Buckets the result into a date-keyed collection. Paginates
     * automatically using the `seo.pull.chunk_size` config value (default 500)
     * as the page size — required for wider windows (e.g. the 35-day backfill)
     * where the total row count exceeds one page.
     *
     * Response row shape when dimensions = ['date', 'page']:
     *   keys[0] = date string ('Y-m-d'), keys[1] = page URL
     *
     * @param  CarbonImmutable $startDate First day of the range (inclusive, UTC).
     * @param  CarbonImmutable $endDate   Last day of the range (inclusive, UTC).
     * @return Collection<string, Collection<int, array{url: string, impressions: int, clicks: int, ctr: float, position: float, top_query: string|null}>>
     *         Outer collection keyed by 'Y-m-d'; inner collection is the flat per-URL list for that date.
     *
     * @throws \Google_Service_Exception  On API-level errors (quota, auth).
     * @throws \Google_Exception          On client setup failures.
     */
    public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
    {
        $client      = $this->makeClient();
        $service     = new Google_Service_Webmasters($client);
        $chunkSize   = (int) config('seo.pull.chunk_size', 500);
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr   = $endDate->format('Y-m-d');

        $allRows = collect();
        $offset  = 0;

        // Paginate: keep fetching until a page returns fewer rows than chunkSize.
        do {
            $request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
            $request->setStartDate($startDateStr);
            $request->setEndDate($endDateStr);
            $request->setDimensions(['date', 'page']);
            $request->setRowLimit($chunkSize);
            $request->setStartRow($offset);

            $response  = $service->searchanalytics->query($this->siteUrl, $request);
            $pageRows  = collect($response->getRows() ?? []);
            $pageCount = $pageRows->count();

            $allRows = $allRows->concat($pageRows);
            $offset += $pageCount;
        } while ($pageCount >= $chunkSize);

        // Parse each row and bucket by date string.
        /** @var Collection<string, Collection<int, array{url: string, impressions: int, clicks: int, ctr: float, position: float, top_query: string|null}>> $buckets */
        $buckets = collect();

        foreach ($allRows as $row) {
            if (is_object($row) && method_exists($row, 'getKeys')) {
                $keys        = $row->getKeys();
                $dateKey     = $keys[0] ?? '';
                $url         = $keys[1] ?? '';
                $clicks      = (int) $row->getClicks();
                $impressions = (int) $row->getImpressions();
                $ctr         = (float) $row->getCtr();
                $position    = (float) $row->getPosition();
            } else {
                // Array fallback (used by fake clients in tests)
                $dateKey     = data_get($row, 'keys.0', '');
                $url         = data_get($row, 'keys.1', '');
                $clicks      = (int) data_get($row, 'clicks', 0);
                $impressions = (int) data_get($row, 'impressions', 0);
                $ctr         = (float) data_get($row, 'ctr', 0.0);
                $position    = (float) data_get($row, 'position', 0.0);
            }

            if (! $buckets->has($dateKey)) {
                $buckets->put($dateKey, collect());
            }

            $buckets->get($dateKey)->push([
                'url'         => $url,
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'ctr'         => $ctr,
                'position'    => $position,
                'top_query'   => null, // F7: per-URL top-query requires a second dimension pass
            ]);
        }

        return $buckets;
    }

    /**
     * Fetch the top query per (date, URL) across the given date range.
     *
     * Uses dimensions = ['date', 'page', 'query'] so each GSC response row
     * represents a (date, page, query) triple. After paginating all rows, the
     * method groups by date → page and picks the query with the highest
     * impression count for that (date, page) bucket.
     *
     * Two API calls per nightly run (this + fetchUrlMetricsForRange) is a
     * deliberate trade-off — see spec 022 §4.1 / §9 for rationale.
     *
     * @param  CarbonImmutable $startDate First day of the range (inclusive, UTC).
     * @param  CarbonImmutable $endDate   Last day of the range (inclusive, UTC).
     * @return Collection<string, Collection<int, array{url: string, top_query: string, top_query_impressions: int}>>
     *         Outer collection keyed by 'Y-m-d'; inner collection has one entry per URL
     *         (the query with max impressions for that date+URL).
     *
     * @throws \Google_Service_Exception On API-level errors (quota, auth).
     * @throws \Google_Exception         On client setup failures.
     */
    public function fetchTopQueriesForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
    {
        $client       = $this->makeClient();
        $service      = new Google_Service_Webmasters($client);
        $chunkSize    = (int) config('seo.pull.chunk_size', 500);
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr   = $endDate->format('Y-m-d');

        $allRows = collect();
        $offset  = 0;

        // Paginate: keep fetching until a page returns fewer rows than chunkSize.
        // Per-query rows multiply the result set ~5-10× vs. the page-only call.
        do {
            $request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
            $request->setStartDate($startDateStr);
            $request->setEndDate($endDateStr);
            $request->setDimensions(['date', 'page', 'query']);
            $request->setRowLimit($chunkSize);
            $request->setStartRow($offset);

            $response  = $service->searchanalytics->query($this->siteUrl, $request);
            $pageRows  = collect($response->getRows() ?? []);
            $pageCount = $pageRows->count();

            $allRows = $allRows->concat($pageRows);
            $offset += $pageCount;
        } while ($pageCount >= $chunkSize);

        // Parse all rows into a flat collection of [date, url, query, impressions] tuples.
        $parsed = collect();

        foreach ($allRows as $row) {
            if (is_object($row) && method_exists($row, 'getKeys')) {
                $keys        = $row->getKeys();
                $dateKey     = $keys[0] ?? '';
                $url         = $keys[1] ?? '';
                $query       = $keys[2] ?? '';
                $impressions = (int) $row->getImpressions();
            } else {
                // Array fallback (used by fake clients in tests).
                $dateKey     = data_get($row, 'keys.0', '');
                $url         = data_get($row, 'keys.1', '');
                $query       = data_get($row, 'keys.2', '');
                $impressions = (int) data_get($row, 'impressions', 0);
            }

            $parsed->push(compact('dateKey', 'url', 'query', 'impressions'));
        }

        // Group by date → by url → pick the row with max impressions per (date, url).
        /** @var Collection<string, Collection<int, array{url: string, top_query: string, top_query_impressions: int}>> $buckets */
        $buckets = collect();

        $parsed
            ->groupBy('dateKey')
            ->each(function (Collection $dateRows, string $dateKey) use ($buckets): void {
                $perUrl = $dateRows
                    ->groupBy('url')
                    ->map(fn (Collection $urlRows, string $url) => $urlRows->sortByDesc('impressions')->first())
                    ->map(fn (array $best, string $url) => [
                        'url'                  => $url,
                        'top_query'            => $best['query'],
                        'top_query_impressions' => $best['impressions'],
                    ])
                    ->values();

                $buckets->put($dateKey, $perUrl);
            });

        return $buckets;
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
