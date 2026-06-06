<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Actions;

use App\Actions\Seo\PullGscMetrics;
use App\Models\Tenant;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests for PullGscMetrics action.
 *
 * Uses a fake GoogleSearchConsoleService bound into the container so no
 * live API calls are made. The fake implements fetchUrlMetricsForRange()
 * returning a date-keyed collection (F23 shape).
 *
 * After F23, execute() takes array<int, CarbonImmutable> instead of a
 * single CarbonImmutable. All call sites updated accordingly.
 */
class PullGscMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Re-fetch via find() to get the correct tenant id.
        // Tenant::create() on sqlite returns a model whose id is the sqlite rowid.
        // See SeoBrandBleedTest for the established pattern.
        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $this->tenant = Tenant::find('acme');
        $this->tenant->gsc_site_url = 'sc-domain:acme.com';
        $this->tenant->save();

        tenancy()->initialize($this->tenant);

        // Bind a fake service implementing fetchUrlMetricsForRange() (F23 shape).
        // Returns a date-keyed Collection matching the real service's contract.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    // Return rows for every requested date in the range.
                    $buckets = collect();

                    for ($d = $startDate; ! $d->greaterThan($endDate); $d = $d->addDay()) {
                        $dateKey = $d->format('Y-m-d');
                        $buckets->put($dateKey, collect([
                            ['url' => 'https://acme.com/', 'impressions' => 1200, 'clicks' => 42, 'ctr' => 0.035, 'position' => 4.2, 'top_query' => null],
                            ['url' => 'https://acme.com/compare/widgets', 'impressions' => 800, 'clicks' => 25, 'ctr' => 0.031, 'position' => 7.5, 'top_query' => null],
                            ['url' => 'https://acme.com/product/widget-pro', 'impressions' => 400, 'clicks' => 10, 'ctr' => 0.025, 'position' => 9.0, 'top_query' => null],
                        ]));
                    }

                    return $buckets;
                }
            };
        });
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    public function test_upserts_rows_with_correct_tenant_id_and_source(): void
    {
        $date   = CarbonImmutable::parse('2026-04-10');
        // F23: execute() now takes array<int, CarbonImmutable>
        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        $this->assertSame(3, $result->upserted);
        $this->assertEmpty($result->errors);

        $rows = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'gsc')
            ->where('metric_date', '2026-04-10')
            ->get();

        $this->assertCount(3, $rows);
    }

    public function test_url_hash_is_sha256_of_url(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');
        (new PullGscMetrics)->execute($this->tenant, [$date]);

        $row = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'gsc')
            ->where('url', 'https://acme.com/')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', 'https://acme.com/'), $row->url_hash);
    }

    public function test_re_running_is_idempotent(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        (new PullGscMetrics)->execute($this->tenant, [$date]);
        (new PullGscMetrics)->execute($this->tenant, [$date]);

        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'gsc')
            ->where('metric_date', '2026-04-10')
            ->count();

        // Running twice should not produce duplicates.
        $this->assertSame(3, $count);
    }

    public function test_missing_gsc_site_url_returns_error_without_throwing(): void
    {
        // Remove the config key from the tenant.
        $this->tenant->gsc_site_url = null;
        $this->tenant->save();

        $date   = CarbonImmutable::parse('2026-04-10');
        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        $this->assertSame(0, $result->upserted);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('gsc_site_url', $result->errors[0]);
    }

    public function test_rows_are_scoped_to_correct_tenant(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');
        (new PullGscMetrics)->execute($this->tenant, [$date]);

        // Rows from another tenant should not bleed in.
        $rows = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'other-tenant')
            ->count();

        $this->assertSame(0, $rows);
    }

    // ── New F23 multi-date tests ───────────────────────────────────────────────

    /**
     * Multi-date array: two dates passed → rows upserted for each date,
     * total = 6 (3 per date), and both dates appear in dailyCounts.
     */
    public function test_multi_date_upsert_aggregates_counts_across_all_dates(): void
    {
        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09');

        $result = (new PullGscMetrics)->execute($this->tenant, [$d1, $d2]);

        // 3 rows per date × 2 dates = 6 total
        $this->assertSame(6, $result->upserted);
        $this->assertEmpty($result->errors);

        // Both dates should appear in the DB.
        foreach (['2026-04-10', '2026-04-09'] as $dateStr) {
            $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
                ->where('tenant_id', 'acme')
                ->where('source', 'gsc')
                ->where('metric_date', $dateStr)
                ->count();
            $this->assertSame(3, $count, "Expected 3 rows for {$dateStr}");
        }
    }

    /**
     * PullResult::$dailyCounts must be populated with per-date upsert counts
     * when multiple dates are requested (F23 feature).
     */
    public function test_per_date_count_breakdown_populates_daily_counts(): void
    {
        // Bind a fake that returns 2 rows for d1 and 1 row for d2.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return collect([
                        '2026-04-10' => collect([
                            ['url' => 'https://acme.com/a', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null],
                            ['url' => 'https://acme.com/b', 'impressions' => 200, 'clicks' => 8, 'ctr' => 0.04, 'position' => 4.0, 'top_query' => null],
                        ]),
                        '2026-04-09' => collect([
                            ['url' => 'https://acme.com/a', 'impressions' => 90, 'clicks' => 4, 'ctr' => 0.04, 'position' => 3.5, 'top_query' => null],
                        ]),
                    ]);
                }
            };
        });

        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09');

        $result = (new PullGscMetrics)->execute($this->tenant, [$d1, $d2]);

        $this->assertSame(3, $result->upserted);
        $this->assertArrayHasKey('2026-04-10', $result->dailyCounts);
        $this->assertArrayHasKey('2026-04-09', $result->dailyCounts);
        $this->assertSame(2, $result->dailyCounts['2026-04-10']);
        $this->assertSame(1, $result->dailyCounts['2026-04-09']);
    }

    /**
     * When the GSC API throws, the entire batch fails atomically (F23 trade-off).
     * PullResult must carry the error with 0 upserted rows.
     */
    public function test_api_failure_fails_all_dates_atomically(): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    throw new \RuntimeException('Simulated GSC API error');
                }
            };
        });

        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09');
        $d3 = CarbonImmutable::parse('2026-04-08');

        $result = (new PullGscMetrics)->execute($this->tenant, [$d1, $d2, $d3]);

        // All dates fail together — no partial upserts.
        $this->assertSame(0, $result->upserted);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Simulated GSC API error', $result->errors[0]);

        // No rows in DB.
        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'gsc')
            ->count();
        $this->assertSame(0, $count);
    }

    /**
     * Empty dates array should return a zero-upsert success result without
     * calling the GSC service at all.
     */
    public function test_empty_dates_array_returns_zero_upsert_success(): void
    {
        $result = (new PullGscMetrics)->execute($this->tenant, []);

        $this->assertSame(0, $result->upserted);
        $this->assertEmpty($result->errors);
    }

    // =========================================================================
    // Spec 022 — F30: top_query merging in PullGscMetrics
    // =========================================================================

    /**
     * Helper: bind a fake GoogleSearchConsoleService that implements both
     * fetchUrlMetricsForRange() and fetchTopQueriesForRange(), returning the
     * supplied canned data without touching any real API.
     *
     * @param  Collection  $urlMetricsBuckets   Date-keyed buckets for the page-level call.
     * @param  Collection  $topQueryBuckets     Date-keyed buckets for the top-query call.
     */
    private function bindDualFakeService(Collection $urlMetricsBuckets, Collection $topQueryBuckets): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () use ($urlMetricsBuckets, $topQueryBuckets) {
            return new class($urlMetricsBuckets, $topQueryBuckets) extends GoogleSearchConsoleService {
                public function __construct(
                    private readonly Collection $urlMetricsBuckets,
                    private readonly Collection $topQueryBuckets,
                ) {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return $this->urlMetricsBuckets;
                }

                public function fetchTopQueriesForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return $this->topQueryBuckets;
                }
            };
        });
    }

    /**
     * F30 (§4.5) — top_query from fetchTopQueriesForRange is merged into each
     * upsert row using the (date, url) lookup map.
     *
     * Two URLs per date; each has a known top_query. Assert that the stored rows
     * in seo_metrics carry the correct gsc_top_query value.
     */
    public function test_top_query_is_merged_from_second_api_call_into_upsert_rows(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/a', 'impressions' => 1000, 'clicks' => 40, 'ctr' => 0.04, 'position' => 3.0, 'top_query' => null],
                ['url' => 'https://acme.com/b', 'impressions' => 500,  'clicks' => 20, 'ctr' => 0.04, 'position' => 5.0, 'top_query' => null],
            ]),
        ]);

        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/a', 'top_query' => 'best espresso machine', 'top_query_impressions' => 800],
                ['url' => 'https://acme.com/b', 'top_query' => 'affordable grinder',    'top_query_impressions' => 300],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        $this->assertSame(2, $result->upserted);
        $this->assertEmpty($result->errors);

        // Verify gsc_top_query is stored correctly for each URL.
        $rowA = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('url', 'https://acme.com/a')
            ->where('metric_date', '2026-04-10')
            ->first();

        $this->assertNotNull($rowA);
        $this->assertSame('best espresso machine', $rowA->gsc_top_query);

        $rowB = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('url', 'https://acme.com/b')
            ->where('metric_date', '2026-04-10')
            ->first();

        $this->assertNotNull($rowB);
        $this->assertSame('affordable grinder', $rowB->gsc_top_query);
    }

    /**
     * F30 (§4.5) — when fetchTopQueriesForRange throws, the main pull must still
     * complete (fetchUrlMetricsForRange data upserted), with gsc_top_query = NULL
     * for all rows, and no exception propagated from execute().
     */
    public function test_top_query_failure_does_not_block_the_main_pull(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        // Bind a fake that succeeds on the URL-metrics call but throws on the top-query call.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return collect([
                        '2026-04-10' => collect([
                            ['url' => 'https://acme.com/a', 'impressions' => 1000, 'clicks' => 30, 'ctr' => 0.03, 'position' => 4.0, 'top_query' => null],
                            ['url' => 'https://acme.com/b', 'impressions' => 600,  'clicks' => 15, 'ctr' => 0.025, 'position' => 6.0, 'top_query' => null],
                        ]),
                    ]);
                }

                public function fetchTopQueriesForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    throw new \RuntimeException('top query API error');
                }
            };
        });

        // execute() must NOT throw even though top-query call fails.
        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        // (a) Main upsert succeeded — rows exist.
        $this->assertSame(2, $result->upserted, 'Main upsert must still complete when top-query call fails');
        $this->assertEmpty($result->errors, 'No errors should propagate to PullResult when top-query fails gracefully');

        // (b) gsc_top_query is NULL for all rows.
        $rows = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('metric_date', '2026-04-10')
            ->get();

        $this->assertCount(2, $rows, 'Two rows must have been upserted despite top-query failure');

        foreach ($rows as $row) {
            $this->assertNull(
                $row->gsc_top_query,
                "gsc_top_query must be NULL when top-query call fails; got '{$row->gsc_top_query}' for {$row->url}"
            );
        }

        // (c) No exception propagated — verified by the test reaching this line.
    }

    /**
     * F30 (§4.5) — when the top-query lookup map contains no entry for a URL,
     * that URL's seo_metrics row must have gsc_top_query = NULL.
     *
     * Scenario: fetchUrlMetricsForRange returns a row for URL X, but
     * fetchTopQueriesForRange only returns data for URL Y (different).
     */
    public function test_top_query_is_null_when_no_match_found_in_lookup_map(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/x', 'impressions' => 700, 'clicks' => 25, 'ctr' => 0.036, 'position' => 4.5, 'top_query' => null],
            ]),
        ]);

        // Top-query data is keyed to a DIFFERENT URL ('y', not 'x').
        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/y', 'top_query' => 'some query for y', 'top_query_impressions' => 500],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        (new PullGscMetrics)->execute($this->tenant, [$date]);

        $row = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('url', 'https://acme.com/x')
            ->where('metric_date', '2026-04-10')
            ->first();

        $this->assertNotNull($row, 'Row for URL X must exist in seo_metrics');
        $this->assertNull(
            $row->gsc_top_query,
            'gsc_top_query must be NULL when the top-query map has no entry for that URL'
        );
    }
}
