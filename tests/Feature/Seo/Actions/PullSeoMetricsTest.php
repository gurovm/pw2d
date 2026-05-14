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
     * Bind a fake GSC service that returns the given rows for ANY date.
     */
    private function bindFakeGsc(array $rows = []): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () use ($rows) {
            return new class($rows) extends GoogleSearchConsoleService {
                public function __construct(private array $fakeRows)
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    return collect($this->fakeRows);
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
     * Bind a fake GSC service that throws for the given Y-m-d date strings
     * and returns $rows for all other dates.
     *
     * @param array<string> $throwOnDates Y-m-d strings on which to throw.
     */
    private function bindFakeGscThrowingOnDates(array $throwOnDates, array $rows = []): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () use ($throwOnDates, $rows) {
            return new class($throwOnDates, $rows) extends GoogleSearchConsoleService {
                public function __construct(
                    private array $throwOnDates,
                    private array $fakeRows,
                ) {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    if (in_array($date->format('Y-m-d'), $this->throwOnDates, true)) {
                        throw new \RuntimeException("Simulated GSC API error for {$date->format('Y-m-d')}");
                    }

                    return collect($this->fakeRows);
                }
            };
        });
    }

    // ── Migrated baseline tests (single-date → one-element arrays) ────────────

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

    // ── New Spec 016 §4.3 tests ───────────────────────────────────────────────

    /**
     * When a multi-date GSC window is supplied, each date is pulled independently.
     * The total upserted count aggregates all dates and errors stay empty.
     */
    public function test_pulls_each_date_in_the_gsc_window_and_aggregates_counts(): void
    {
        // \stdClass is used deliberately: the anonymous class receives the counter
        // via its constructor, which doesn't support `use (&$ref)`. An object
        // reference is the only way to share mutable state between the binding
        // closure and the anonymous class instance without PHP reference gymnastics.
        $gscCalls = new \stdClass();
        $gscCalls->count = 0;

        $ga4Calls = new \stdClass();
        $ga4Calls->count = 0;

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscCalls) {
            return new class($gscCalls) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $counter)
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    $this->counter->count++;
                    // Unique URL per date so each invocation writes its own row.
                    return collect([
                        ['url' => "https://tenant-a.com/{$date->format('Y-m-d')}", 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                    ]);
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
            [$d1, $d2, $d3],  // 3 GSC dates
            [$d1],             // 1 GA4 date
        );

        $this->assertSame(3, $gscCalls->count, 'PullGscMetrics should be called once per GSC date');
        $this->assertSame(1, $ga4Calls->count, 'PullGa4Metrics should be called once per GA4 date');

        $this->assertSame(3, $result->gscRowsUpserted, '1 row per date × 3 dates = 3 total');
        $this->assertSame(1, $result->ga4RowsUpserted);
        $this->assertEmpty($result->errors);
    }

    /**
     * gscDailyCounts must be keyed by Y-m-d and hold per-date upsert counts.
     */
    public function test_gsc_daily_counts_populates_per_date_upsert_counts(): void
    {
        // d1 → 2 rows, d2 → 1 row.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:tenant-a.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    if ($date->format('Y-m-d') === '2026-04-10') {
                        return collect([
                            ['url' => 'https://tenant-a.com/a', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null],
                            ['url' => 'https://tenant-a.com/b', 'impressions' => 200, 'clicks' => 8, 'ctr' => 0.04, 'position' => 4.0, 'top_query' => null],
                        ]);
                    }

                    return collect([
                        ['url' => 'https://tenant-a.com/a', 'impressions' => 90, 'clicks' => 4, 'ctr' => 0.04, 'position' => 3.5, 'top_query' => null],
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
     * When GSC throws for d2, the remaining dates (d1, d3) must still upsert
     * and the errors array must contain exactly one entry mentioning d2's date.
     */
    public function test_gsc_failure_for_one_date_does_not_block_other_dates(): void
    {
        $d1 = CarbonImmutable::parse('2026-04-10');
        $d2 = CarbonImmutable::parse('2026-04-09'); // will throw
        $d3 = CarbonImmutable::parse('2026-04-08');

        $this->bindFakeGscThrowingOnDates(
            throwOnDates: ['2026-04-09'],
            rows: [
                ['url' => 'https://tenant-a.com/', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null],
            ],
        );

        $this->bindFakeGa4([]);

        $result = (new PullSeoMetrics)->execute(
            $this->tenantA,
            [$d1, $d2, $d3],
            [$d1],
        );

        // d1 and d3 each write 1 row; d2 fails.
        $this->assertSame(2, $result->gscRowsUpserted, 'd1 and d3 should each produce 1 upserted row');
        $this->assertCount(1, $result->errors, 'Exactly one error entry for d2');
        $this->assertStringContainsString('2026-04-09', $result->errors[0]);

        // Confirm the DB rows for d1 and d3 exist.
        foreach (['2026-04-10', '2026-04-08'] as $dateStr) {
            $this->assertTrue(
                \Illuminate\Support\Facades\DB::table('seo_metrics')
                    ->where('tenant_id', 'tenant-a')
                    ->where('source', 'gsc')
                    ->where('metric_date', $dateStr)
                    ->exists(),
                "Expected seo_metrics row for {$dateStr}",
            );
        }
    }
}
