<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Actions;

use App\Actions\Seo\PullGa4Metrics;
use App\Actions\Seo\PullGscMetrics;
use App\Actions\Seo\PullResult;
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

    public function test_tenancy_is_initialized_and_ended(): void
    {
        $this->bindFakeGsc([['url' => 'https://tenant-a.com/', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null]]);
        $this->bindFakeGa4([['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3]]);

        // Before execute: no tenancy context.
        $this->assertFalse(tenancy()->initialized);

        $result = (new PullSeoMetrics)->execute($this->tenantA, CarbonImmutable::parse('2026-04-10'));

        // After execute: tenancy was ended (the finally block fired).
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame('tenant-a', $result->tenantId);
    }

    public function test_gsc_failure_does_not_block_ga4(): void
    {
        // GSC has no site URL — will return error without throwing.
        $this->tenantA->gsc_site_url = null;
        $this->tenantA->save();

        $this->bindFakeGa4([['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3]]);

        $result = (new PullSeoMetrics)->execute($this->tenantA, CarbonImmutable::parse('2026-04-10'));

        $this->assertSame(0, $result->gscRowsUpserted);
        $this->assertSame(1, $result->ga4RowsUpserted);
        $this->assertNotEmpty($result->errors);

        // GA4 row must exist in DB.
        $this->assertDatabaseCount('seo_metrics', 1);
    }

    public function test_cross_tenant_isolation(): void
    {
        // Seed tenant-b with overlapping URLs in seo_metrics.
        Tenant::create(['id' => 'tenant-b', 'name' => 'Tenant B']);
        $tenantB = Tenant::find('tenant-b');

        \Illuminate\Support\Facades\DB::table('seo_metrics')->insert([
            'tenant_id'   => 'tenant-b',
            'source'      => 'gsc',
            'url'         => 'https://tenant-a.com/',  // same URL, different tenant
            'url_hash'    => hash('sha256', 'https://tenant-a.com/'),
            'metric_date' => '2026-04-10',
            'gsc_impressions' => 999,
            'gsc_clicks'      => 99,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->bindFakeGsc([['url' => 'https://tenant-a.com/', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null]]);
        $this->bindFakeGa4([]);

        (new PullSeoMetrics)->execute($this->tenantA, CarbonImmutable::parse('2026-04-10'));

        // Tenant B's row must be untouched.
        $tenantBRow = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-b')
            ->where('url', 'https://tenant-a.com/')
            ->first();

        $this->assertNotNull($tenantBRow);
        $this->assertSame(999, (int) $tenantBRow->gsc_impressions);

        // Tenant A gets its own row.
        $tenantARow = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-a')
            ->where('url', 'https://tenant-a.com/')
            ->where('source', 'gsc')
            ->first();

        $this->assertNotNull($tenantARow);
        $this->assertSame(100, (int) $tenantARow->gsc_impressions);
    }
}
