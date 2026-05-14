<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Actions;

use App\Actions\Seo\PullSeoMetrics;
use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests for PullSeoMetrics orchestrator.
 *
 * Child actions are faked via container bindings so the test stays offline.
 * Since Spec 016 the execute() signature takes date arrays, not a single date.
 *
 * F23 changes:
 * - GSC fakes must implement fetchUrlMetricsForRange() (date-keyed Collection)
 *   instead of the deprecated fetchUrlMetrics().
 * - PullGscMetrics::execute() is now called ONCE per tenant for the full window,
 *   not once per date. Assertions on call counts updated accordingly.
 * - GSC failure is now ATOMIC: a single API error fails all requested dates.
 *   The "d2 throws → d1 and d3 still succeed" semantic is gone for GSC (GA4
 *   retains per-date isolation).
 */
class PullSeoMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'tenant-a', 'name' => 'Tenant A']);
        $this->tenantA = Tenant::find('tenant-a');
        $this->tenantA->gsc_site_url    = 'sc-domain:tenant-a.com';
        $this->tenantA->ga4_property_id = 'properties/111111';
        $this->tenantA->save();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Bind a fake GSC service implementing fetchUrlMetricsForRange() (F23 shape).
     *
     * Returns $rows for every date in the requested range, keyed by 'Y-m-d'.
     */
    private function bindFakeGsc(array $rows = []): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () use ($rows) {
            return new class($rows) extends GoogleSearchConsoleService {
                public function __construct(private array $fakeRows)
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    $buckets = collect();
                    for ($d = $startDate; ! $d->greaterThan($endDate); $d = $d->addDay()) {
                        $buckets->put($d->format('Y-m-d'), collect($this->fakeRows));
                    }
                    return $buckets;
                }
            };
        });
    }

    /**
     * Bind a fake GA4 service that returns the given rows for ANY date.
     */
    private function bindFakeGa4(array $rows = []): void
    {
        app()->bind(GoogleAnalyticsService::class, function () use ($rows) {
            return new class($rows) extends GoogleAnalyticsService {
                public function __construct(private array $fakeRows)
                {
                    parent::__construct('properties/111111', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    return collect($this->fakeRows);
                }
            };
        });
    }

    /**
     * Bind a fake GSC service that throws unconditionally when fetchUrlMetricsForRange
     * is called (simulates a full window API failure, per F23 atomicity).
     */
    private function bindFakeGscThrowing(): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    throw new \RuntimeException('Simulated GSC API window failure');
                }
            };
        });
    }

    // ── Migrated baseline tests ───────────────────────────────────────────────

    public function test_tenancy_is_initialized_and_ended(): void
    {
        $this->bindFakeGsc([['url' => 'https://tenant-a.com/', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null]]);
        $this->bindFakeGa4([['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3]]);

        $this->assertFalse(tenancy()->initialized);

        $result = (new PullSeoMetrics)->execute(
            $this->tenantA,
            [CarbonImmutable::parse('2026-04-10')],
            [CarbonImmutable::parse('2026-04-10')],
        );

        // The finally block must have ended tenancy.
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame('tenant-a', $result->tenantId);
    }

    public function test_gsc_failure_does_not_block_ga4(): void
    {
        // GSC has no site URL — PullGscMetrics will return an error result (not throw).
        $this->tenantA->gsc_site_url = null;
        $this->tenantA->save();

        $this->bindFakeGa4([['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3]]);

        $result = (new PullSeoMetrics)->execute(
            $this->tenantA,
            [CarbonImmutable::parse('2026-04-10')],
            [CarbonImmutable::parse('2026-04-10')],
        );

        $this->assertSame(0, $result->gscRowsUpserted);
        $this->assertSame(1, $result->ga4RowsUpserted);
        $this->assertNotEmpty($result->errors);

        $this->assertDatabaseCount('seo_metrics', 1);
    }

    public function test_cross_tenant_isolation(): void
    {
        Tenant::create(['id' => 'tenant-b', 'name' => 'Tenant B']);

        \Illuminate\Support\Facades\DB::table('seo_metrics')->insert([
            'tenant_id'       => 'tenant-b',
            'source'          => 'gsc',
            'url'             => 'https://tenant-a.com/',  // same URL, different tenant
            'url_hash'        => hash('sha256', 'https://tenant-a.com/'),
            'metric_date'     => '2026-04-10',
            'gsc_impressions' => 999,
            'gsc_clicks'      => 99,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->bindFakeGsc([['url' => 'https://tenant-a.com/', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null]]);
        $this->bindFakeGa4([]);

        (new PullSeoMetrics)->execute(
            $this->tenantA,
            [CarbonImmutable::parse('2026-04-10')],
            [CarbonImmutable::parse('2026-04-10')],
        );

        // Tenant B's row must be untouched.
        $tenantBRow = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-b')
            ->where('url', 'https://tenant-a.com/')
            ->first();

        $this->assertNotNull($tenantBRow);
        $this->assertSame(999, (int) $tenantBRow->gsc_impressions);

        // Tenant A gets its own row with the freshly upserted value.
        $tenantARow = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-a')
            ->where('url', 'https://tenant-a.com/')
            ->where('source', 'gsc')
            ->first();

        $this->assertNotNull($tenantARow);
        $this->assertSame(100, (int) $tenantARow->gsc_impressions);
    }

    // ── F23 updated orchestration tests ──────────────────────────────────────

    /**
     * After F23, PullSeoMetrics calls PullGscMetrics ONCE for the full window
     * (not once per date). The GSC service's fetchUrlMetricsForRange() is invoked
     * a single time regardless of how many dates are in the window.
     *
     * Total upserted count still aggregates rows across all dates.
     * GA4 is still called once per date (per-date isolation retained for GA4).
     */
    public function test_gsc_service_is_called_once_for_the_full_window(): void
    {
        $gscRangeCalls = new \stdClass();
        $gscRangeCalls->count = 0;

        $ga4Calls = new \stdClass();
        $ga4Calls->count = 0;

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscRangeCalls) {
            return new class($gscRangeCalls) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $counter)
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    $this->counter->count++;
                    // Return one unique URL per date so each date produces a distinct row.
                    $buckets = collect();
                    for ($d = $startDate; ! $d->greaterThan($endDate); $d = $d->addDay()) {
                        $dateKey = $d->format('Y-m-d');
                        $buckets->put($dateKey, collect([
                            ['url' => "https://tenant-a.com/{$dateKey}", 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                        ]));
                    }
                    return $buckets;
                }
            };
        });

        app()->bind(GoogleAnalyticsService::class, function () use ($ga4Calls) {
            return new class($ga4Calls) extends GoogleAnalyticsService {
                public function __construct(private \stdClass $counter)
                {
                    parent::__construct('properties/111111', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    $this->counter->count++;
                    return collect([
                        ['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3],
                    ]);
                }
            };
        });

        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09');
        $d3 = CarbonImmutable::parse('2026-04-08');

        $result = (new PullSeoMetrics)->execute(
            $this->tenantA,
            [$d1, $d2, $d3],  // 3 GSC dates → ONE ranged call
            [$d1],             // 1 GA4 date
        );

        // F23: fetchUrlMetricsForRange is called ONCE for the full window, not 3×.
        $this->assertSame(1, $gscRangeCalls->count, 'GSC fetchUrlMetricsForRange must be called exactly once for the whole window');
        $this->assertSame(1, $ga4Calls->count, 'GA4 fetchLandingPageMetrics must still be called once per date');

        $this->assertSame(3, $result->gscRowsUpserted, '1 row per date × 3 dates = 3 total');
        $this->assertSame(1, $result->ga4RowsUpserted);
        $this->assertEmpty($result->errors);
    }

    /**
     * gscDailyCounts must be keyed by Y-m-d and hold per-date upsert counts.
     * PullGscMetrics now returns these in PullResult::$dailyCounts; the orchestrator
     * plumbs them through to PullSeoMetricsResult::$gscDailyCounts.
     */
    public function test_gsc_daily_counts_populates_per_date_upsert_counts(): void
    {
        // d1 → 2 rows, d2 → 1 row — via a date-keyed fetchUrlMetricsForRange response.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return collect([
                        '2026-04-10' => collect([
                            ['url' => 'https://tenant-a.com/a', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null],
                            ['url' => 'https://tenant-a.com/b', 'impressions' => 200, 'clicks' => 8, 'ctr' => 0.04, 'position' => 4.0, 'top_query' => null],
                        ]),
                        '2026-04-09' => collect([
                            ['url' => 'https://tenant-a.com/a', 'impressions' => 90, 'clicks' => 4, 'ctr' => 0.04, 'position' => 3.5, 'top_query' => null],
                        ]),
                    ]);
                }
            };
        });

        $this->bindFakeGa4([]);

        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09');

        $result = (new PullSeoMetrics)->execute(
            $this->tenantA,
            [$d1, $d2],
            [$d1],
        );

        $this->assertSame(3, $result->gscRowsUpserted);
        $this->assertArrayHasKey('2026-04-10', $result->gscDailyCounts);
        $this->assertArrayHasKey('2026-04-09', $result->gscDailyCounts);
        $this->assertSame(2, $result->gscDailyCounts['2026-04-10']);
        $this->assertSame(1, $result->gscDailyCounts['2026-04-09']);
    }

    /**
     * F23 atomicity: when the GSC window fails (fetchUrlMetricsForRange throws),
     * ALL requested GSC dates fail together — there is no per-date isolation.
     *
     * UPDATED from Spec 016: the old test asserted d1 and d3 still upsert when
     * d2 throws. That per-date isolation is gone for GSC. Now the whole window
     * fails. GA4 is unaffected (it runs separately with its own loop).
     */
    public function test_gsc_window_failure_is_atomic_all_dates_fail_together(): void
    {
        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09');
        $d3 = CarbonImmutable::parse('2026-04-08');

        // The entire window throws — simulates an API auth or quota failure.
        $this->bindFakeGscThrowing();

        $this->bindFakeGa4([['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3]]);

        $result = (new PullSeoMetrics)->execute(
            $this->tenantA,
            [$d1, $d2, $d3],
            [$d1],
        );

        // All GSC dates fail together — zero GSC rows upserted.
        $this->assertSame(0, $result->gscRowsUpserted, 'Atomic GSC failure: zero rows must be upserted for any date');
        $this->assertNotEmpty($result->errors, 'An error entry must be recorded for the window failure');

        // No GSC rows at all in the DB for any of the three dates.
        $gscCount = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-a')
            ->where('source', 'gsc')
            ->count();
        $this->assertSame(0, $gscCount, 'No GSC rows should be written when the window fails');

        // GA4 is unaffected — its own loop runs separately (GSC failure ≠ GA4 block).
        $this->assertSame(1, $result->ga4RowsUpserted, 'GA4 must still succeed despite GSC window failure');
        $ga4Count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-a')
            ->where('source', 'ga4')
            ->count();
        $this->assertSame(1, $ga4Count);
    }
}
