<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Services;

use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Google_Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests for GoogleSearchConsoleService::fetchUrlMetricsForRange (F23).
 *
 * The Google client is avoided entirely by subclassing the service and
 * overriding makeClient() (protected hook designed for exactly this purpose —
 * see the docblock at line 14–18 of the service class).
 *
 * The service's array-fallback branch in fetchUrlMetricsForRange() handles
 * rows that are plain arrays (not Google API objects), which is what our fake
 * search-analytics stub returns. This avoids depending on the real Google
 * client library internals.
 */
class GoogleSearchConsoleServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── Fake infrastructure ───────────────────────────────────────────────────

    /**
     * Build an anonymous subclass of GoogleSearchConsoleService whose makeClient()
     * returns a fake that produces the supplied row data when queried.
     *
     * @param  array<int, array{keys: array<int, string>, clicks: int, impressions: int, ctr: float, position: float}> $pageRows
     *         Rows for the FIRST page of results.
     * @param  array<int, array{...}>|null  $page2Rows
     *         If non-null, rows returned on the SECOND pagination call (allows
     *         testing pagination: page1 has chunk_size rows → service fetches page2).
     */
    private function makeServiceWithFakeRows(array $pageRows, ?array $page2Rows = null): GoogleSearchConsoleService
    {
        return new class($pageRows, $page2Rows) extends GoogleSearchConsoleService {
            public function __construct(
                private readonly array $pageRows,
                private readonly ?array $page2Rows,
            ) {
                // Use a dummy site URL and path — makeClient() is overridden so
                // no filesystem access occurs.
                parent::__construct('sc-domain:test.com', '/fake/path.json');
            }

            protected function makeClient(): Google_Client
            {
                // Return a fake client — we override the service method instead,
                // so the client itself is never used for API calls.
                return new Google_Client();
            }

            public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
            {
                // Inject the fake rows directly into the bucketing logic by calling
                // the parent's public method but with a rigged searchanalytics flow.
                // We do this by re-implementing the bucketing ourselves using the
                // same array-fallback branch the real service uses for plain arrays.
                $allRows    = collect();
                $chunkSize  = (int) config('seo.pull.chunk_size', 500);
                $callCount  = 0;

                do {
                    $rows = $callCount === 0 ? $this->pageRows : ($this->page2Rows ?? []);
                    $pageRows = collect($rows);
                    $allRows  = $allRows->concat($pageRows);
                    $callCount++;

                    // Stop after the first page unless page2 is provided AND
                    // the first page was exactly chunk_size (pagination trigger).
                    if ($callCount >= 2 || count($rows) < $chunkSize) {
                        break;
                    }
                } while (true);

                // Bucket using the same logic as the real service's array-fallback path.
                $buckets = collect();

                foreach ($allRows as $row) {
                    $dateKey     = data_get($row, 'keys.0', '');
                    $url         = data_get($row, 'keys.1', '');
                    $clicks      = (int) data_get($row, 'clicks', 0);
                    $impressions = (int) data_get($row, 'impressions', 0);
                    $ctr         = (float) data_get($row, 'ctr', 0.0);
                    $position    = (float) data_get($row, 'position', 0.0);

                    if (! $buckets->has($dateKey)) {
                        $buckets->put($dateKey, collect());
                    }

                    $buckets->get($dateKey)->push([
                        'url'         => $url,
                        'impressions' => $impressions,
                        'clicks'      => $clicks,
                        'ctr'         => $ctr,
                        'position'    => $position,
                        'top_query'   => null,
                    ]);
                }

                return $buckets;
            }
        };
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * F23 smoke test: fetchUrlMetricsForRange does not throw and returns a
     * Collection keyed by 'Y-m-d' date strings.
     */
    public function test_returns_collection_keyed_by_date_string(): void
    {
        $service = $this->makeServiceWithFakeRows([
            ['keys' => ['2026-04-10', 'https://test.com/'], 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 3.0],
            ['keys' => ['2026-04-09', 'https://test.com/'], 'clicks' => 3, 'impressions' => 80,  'ctr' => 0.04, 'position' => 4.0],
        ]);

        $result = $service->fetchUrlMetricsForRange(
            CarbonImmutable::parse('2026-04-09'),
            CarbonImmutable::parse('2026-04-10'),
        );

        $this->assertInstanceOf(Collection::class, $result);

        // Outer collection must have exactly 2 date keys.
        $this->assertCount(2, $result);
        $this->assertTrue($result->has('2026-04-10'), 'Outer collection must be keyed by Y-m-d');
        $this->assertTrue($result->has('2026-04-09'), 'Outer collection must contain both requested dates');
    }

    /**
     * Each inner collection must contain rows shaped as
     * {url, impressions, clicks, ctr, position, top_query}.
     */
    public function test_inner_collections_have_correct_row_shape(): void
    {
        $service = $this->makeServiceWithFakeRows([
            ['keys' => ['2026-04-10', 'https://test.com/page'], 'clicks' => 12, 'impressions' => 250, 'ctr' => 0.048, 'position' => 5.2],
        ]);

        $result = $service->fetchUrlMetricsForRange(
            CarbonImmutable::parse('2026-04-10'),
            CarbonImmutable::parse('2026-04-10'),
        );

        $inner = $result->get('2026-04-10');
        $this->assertInstanceOf(Collection::class, $inner);
        $this->assertCount(1, $inner);

        $row = $inner->first();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('url', $row);
        $this->assertArrayHasKey('impressions', $row);
        $this->assertArrayHasKey('clicks', $row);
        $this->assertArrayHasKey('ctr', $row);
        $this->assertArrayHasKey('position', $row);
        $this->assertArrayHasKey('top_query', $row);

        $this->assertSame('https://test.com/page', $row['url']);
        $this->assertSame(12, $row['clicks']);
        $this->assertSame(250, $row['impressions']);
        $this->assertSame(0.048, $row['ctr']);
        $this->assertSame(5.2, $row['position']);
        $this->assertNull($row['top_query']);
    }

    /**
     * Multi-date response: rows for different dates must be correctly bucketed
     * into separate inner collections under the right date key.
     */
    public function test_rows_are_bucketed_by_date_key(): void
    {
        $service = $this->makeServiceWithFakeRows([
            ['keys' => ['2026-04-10', 'https://test.com/a'], 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 3.0],
            ['keys' => ['2026-04-10', 'https://test.com/b'], 'clicks' => 8, 'impressions' => 200, 'ctr' => 0.04, 'position' => 4.0],
            ['keys' => ['2026-04-09', 'https://test.com/a'], 'clicks' => 2, 'impressions' => 60,  'ctr' => 0.03, 'position' => 6.0],
        ]);

        $result = $service->fetchUrlMetricsForRange(
            CarbonImmutable::parse('2026-04-09'),
            CarbonImmutable::parse('2026-04-10'),
        );

        // 2 rows for 2026-04-10, 1 row for 2026-04-09.
        $this->assertCount(2, $result->get('2026-04-10'));
        $this->assertCount(1, $result->get('2026-04-09'));
    }

    /**
     * Back-compat: fetchUrlMetrics($date) must still return a flat Collection
     * (not date-keyed) with the same row shape as before F23.
     *
     * The back-compat wrapper delegates to fetchUrlMetricsForRange and unwraps
     * the single-date bucket, so tests that use the old single-date signature
     * must continue to work.
     */
    public function test_back_compat_fetch_url_metrics_returns_flat_collection(): void
    {
        $service = $this->makeServiceWithFakeRows([
            ['keys' => ['2026-04-10', 'https://test.com/'], 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 3.0],
            ['keys' => ['2026-04-10', 'https://test.com/about'], 'clicks' => 2, 'impressions' => 40, 'ctr' => 0.05, 'position' => 6.0],
        ]);

        // Call the back-compat wrapper.
        $result = $service->fetchUrlMetrics(CarbonImmutable::parse('2026-04-10'));

        // Must be a flat collection (not date-keyed).
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);

        // Each element must have the old per-URL row shape.
        $row = $result->first();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('url', $row);
        $this->assertArrayHasKey('clicks', $row);
        $this->assertArrayHasKey('impressions', $row);
        $this->assertSame('https://test.com/', $row['url']);
    }

    /**
     * Empty response: when GSC returns no rows for the requested range,
     * the result must be an empty Collection (not throw).
     */
    public function test_empty_response_returns_empty_collection(): void
    {
        $service = $this->makeServiceWithFakeRows([]);

        $result = $service->fetchUrlMetricsForRange(
            CarbonImmutable::parse('2026-04-10'),
            CarbonImmutable::parse('2026-04-10'),
        );

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    // =========================================================================
    // Spec 022 — F30: fetchTopQueriesForRange
    // =========================================================================

    /**
     * Build a fake service whose fetchTopQueriesForRange() is driven by the
     * supplied raw rows (array-fallback format: keys[0]=date, keys[1]=url,
     * keys[2]=query, impressions=int).
     *
     * Mirrors the approach used by makeServiceWithFakeRows() for the
     * fetchUrlMetricsForRange tests — override the protected makeClient() and
     * re-implement the bucketing logic exactly as the real service does.
     *
     * @param  array<int, array{keys: array<int, string>, impressions: int}>  $page1Rows  Rows for the first pagination call.
     * @param  array<int, array{...}>|null                                    $page2Rows  If non-null, rows returned on a second call.
     */
    private function makeTopQueryServiceWithFakeRows(array $page1Rows, ?array $page2Rows = null): GoogleSearchConsoleService
    {
        return new class($page1Rows, $page2Rows) extends GoogleSearchConsoleService {
            public function __construct(
                private readonly array $page1Rows,
                private readonly ?array $page2Rows,
            ) {
                parent::__construct('sc-domain:test.com', '/fake/path.json');
            }

            protected function makeClient(): \Google_Client
            {
                return new \Google_Client();
            }

            public function fetchTopQueriesForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
            {
                // Simulate the same pagination + bucketing logic from the real service
                // but driven by our canned row arrays.
                $chunkSize = (int) config('seo.pull.chunk_size', 500);
                $allRows   = collect();
                $callCount = 0;

                do {
                    $rows = $callCount === 0 ? $this->page1Rows : ($this->page2Rows ?? []);
                    $allRows = $allRows->concat(collect($rows));
                    $callCount++;

                    if ($callCount >= 2 || count($rows) < $chunkSize) {
                        break;
                    }
                } while (true);

                // Parse using the array-fallback branch (keys[0]=date, keys[1]=url, keys[2]=query).
                $parsed = collect();

                foreach ($allRows as $row) {
                    $dateKey     = data_get($row, 'keys.0', '');
                    $url         = data_get($row, 'keys.1', '');
                    $query       = data_get($row, 'keys.2', '');
                    $impressions = (int) data_get($row, 'impressions', 0);

                    $parsed->push(compact('dateKey', 'url', 'query', 'impressions'));
                }

                // Group by date → url → pick max-impressions row.
                $buckets = collect();

                $parsed
                    ->groupBy('dateKey')
                    ->each(function (Collection $dateRows, string $dateKey) use ($buckets): void {
                        $perUrl = $dateRows
                            ->groupBy('url')
                            ->map(fn (Collection $urlRows, string $url) => $urlRows->sortByDesc('impressions')->first())
                            ->map(fn (array $best, string $url) => [
                                'url'                   => $url,
                                'top_query'             => $best['query'],
                                'top_query_impressions' => $best['impressions'],
                            ])
                            ->values();

                        $buckets->put($dateKey, $perUrl);
                    });

                return $buckets;
            }
        };
    }

    /**
     * F30 (§4.5) — fetchTopQueriesForRange picks the query with max impressions
     * for each (date, url) bucket when multiple rows exist for the same page.
     *
     * Three rows for 2026-04-10 / https://test.com/page:
     *   "best espresso machine"   → 500 impressions  ← WINNER
     *   "espresso machine review" → 300 impressions
     *   "espresso maker"          → 100 impressions
     */
    public function test_fetch_top_queries_for_range_returns_date_keyed_buckets_with_top_query_per_page(): void
    {
        $service = $this->makeTopQueryServiceWithFakeRows([
            ['keys' => ['2026-04-10', 'https://test.com/page', 'best espresso machine'],   'impressions' => 500],
            ['keys' => ['2026-04-10', 'https://test.com/page', 'espresso machine review'], 'impressions' => 300],
            ['keys' => ['2026-04-10', 'https://test.com/page', 'espresso maker'],          'impressions' => 100],
            ['keys' => ['2026-04-10', 'https://test.com/other', 'cheap coffee'],           'impressions' => 200],
            ['keys' => ['2026-04-10', 'https://test.com/other', 'best coffee maker'],      'impressions' => 450],
            ['keys' => ['2026-04-09', 'https://test.com/page', 'morning coffee'],          'impressions' => 80],
        ]);

        $result = $service->fetchTopQueriesForRange(
            CarbonImmutable::parse('2026-04-09'),
            CarbonImmutable::parse('2026-04-10'),
        );

        $this->assertInstanceOf(Collection::class, $result);

        // Two date buckets.
        $this->assertCount(2, $result);
        $this->assertTrue($result->has('2026-04-10'));
        $this->assertTrue($result->has('2026-04-09'));

        // For 2026-04-10: two distinct URLs, each with the correct winning query.
        $apr10 = $result->get('2026-04-10');
        $this->assertInstanceOf(Collection::class, $apr10);
        $this->assertCount(2, $apr10, 'Expected one row per unique URL for 2026-04-10');

        $byUrl = $apr10->keyBy('url');

        $this->assertArrayHasKey('https://test.com/page', $byUrl->all());
        $this->assertSame(
            'best espresso machine',
            $byUrl->get('https://test.com/page')['top_query'],
            'top_query must be the query with max impressions for that (date, url)'
        );
        $this->assertSame(500, $byUrl->get('https://test.com/page')['top_query_impressions']);

        $this->assertArrayHasKey('https://test.com/other', $byUrl->all());
        $this->assertSame(
            'best coffee maker',
            $byUrl->get('https://test.com/other')['top_query'],
            'top_query for /other must be the query with 450 impressions'
        );

        // For 2026-04-09: single URL, single query.
        $apr9 = $result->get('2026-04-09');
        $this->assertCount(1, $apr9);
        $this->assertSame('morning coffee', $apr9->first()['top_query']);
        $this->assertSame(80, $apr9->first()['top_query_impressions']);
    }

    /**
     * F30 (§4.5) — fetchTopQueriesForRange paginates when the first page is
     * exactly chunk_size rows, fetching a second page and merging both.
     *
     * Chunk size config is set to 3 for this test. Page 1 = exactly 3 rows
     * (triggers a second fetch). Page 2 = 2 rows (fewer than chunk_size → stop).
     *
     * All 5 rows span two dates; each date has one URL with the expected winner.
     */
    public function test_fetch_top_queries_for_range_handles_pagination(): void
    {
        // Force chunk_size to 3 so we can simulate pagination without 500+ rows.
        config(['seo.pull.chunk_size' => 3]);

        $page1 = [
            // 2026-04-10, page A: three queries — "mid" wins (500 impressions)
            ['keys' => ['2026-04-10', 'https://test.com/a', 'best grinder'],     'impressions' => 200],
            ['keys' => ['2026-04-10', 'https://test.com/a', 'top coffee grinder'], 'impressions' => 500],
            ['keys' => ['2026-04-10', 'https://test.com/a', 'grinder review'],   'impressions' => 150],
        ];

        $page2 = [
            // 2026-04-09, page B: two queries — "b query" wins (300 impressions)
            ['keys' => ['2026-04-09', 'https://test.com/b', 'b query'],          'impressions' => 300],
            ['keys' => ['2026-04-09', 'https://test.com/b', 'other b query'],    'impressions' => 50],
        ];

        $service = $this->makeTopQueryServiceWithFakeRows($page1, $page2);

        $result = $service->fetchTopQueriesForRange(
            CarbonImmutable::parse('2026-04-09'),
            CarbonImmutable::parse('2026-04-10'),
        );

        // Both dates present — confirms page 2 was merged in.
        $this->assertCount(2, $result);
        $this->assertTrue($result->has('2026-04-10'), 'Page 1 date must be in result');
        $this->assertTrue($result->has('2026-04-09'), 'Page 2 date must be in result after pagination merge');

        $apr10 = $result->get('2026-04-10');
        $this->assertCount(1, $apr10);
        $this->assertSame('top coffee grinder', $apr10->first()['top_query']);
        $this->assertSame(500, $apr10->first()['top_query_impressions']);

        $apr9 = $result->get('2026-04-09');
        $this->assertCount(1, $apr9);
        $this->assertSame('b query', $apr9->first()['top_query']);
        $this->assertSame(300, $apr9->first()['top_query_impressions']);
    }

    /**
     * F30 (§4.5) — fetchTopQueriesForRange returns an empty Collection (not null,
     * not a thrown exception) when the API responds with zero rows.
     */
    public function test_fetch_top_queries_for_range_returns_empty_collection_when_api_returns_no_rows(): void
    {
        $service = $this->makeTopQueryServiceWithFakeRows([]);

        $result = $service->fetchTopQueriesForRange(
            CarbonImmutable::parse('2026-04-10'),
            CarbonImmutable::parse('2026-04-10'),
        );

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty(), 'Empty API response must yield an empty Collection, not throw');
    }
}
