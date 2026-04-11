<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Actions;

use App\Actions\Seo\PullGa4Metrics;
use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests for PullGa4Metrics action.
 *
 * Uses a fake GoogleAnalyticsService bound into the container so no
 * live API calls are made.
 */
class PullGa4MetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $this->tenant = Tenant::find('acme');
        $this->tenant->ga4_property_id = 'properties/123456789';
        $this->tenant->save();

        tenancy()->initialize($this->tenant);

        app()->bind(GoogleAnalyticsService::class, function () {
            return new class extends GoogleAnalyticsService {
                public function __construct()
                {
                    parent::__construct('properties/123456789', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    return collect([
                        ['url' => '/', 'sessions' => 310, 'users' => 280, 'engaged_sessions' => 195, 'conversions' => 12, 'bounce_rate' => 0.371],
                        ['url' => '/compare/widgets', 'sessions' => 540, 'users' => 498, 'engaged_sessions' => 420, 'conversions' => 23, 'bounce_rate' => 0.222],
                        ['url' => '/product/widget-pro', 'sessions' => 175, 'users' => 160, 'engaged_sessions' => 140, 'conversions' => 8, 'bounce_rate' => 0.2],
                    ]);
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
        $result = (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->assertSame(3, $result->upserted);
        $this->assertEmpty($result->errors);

        $rows = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'ga4')
            ->where('metric_date', '2026-04-10')
            ->get();

        $this->assertCount(3, $rows);
    }

    public function test_url_hash_is_sha256_of_url(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $row = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'ga4')
            ->where('url', '/')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', '/'), $row->url_hash);
    }

    public function test_re_running_is_idempotent(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        (new PullGa4Metrics)->execute($this->tenant, $date);
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'ga4')
            ->where('metric_date', '2026-04-10')
            ->count();

        $this->assertSame(3, $count);
    }

    public function test_missing_ga4_property_id_returns_error_without_throwing(): void
    {
        $this->tenant->ga4_property_id = null;
        $this->tenant->save();

        $date   = CarbonImmutable::parse('2026-04-10');
        $result = (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->assertSame(0, $result->upserted);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('ga4_property_id', $result->errors[0]);
    }

    public function test_rows_are_scoped_to_correct_tenant(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $rows = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'other-tenant')
            ->count();

        $this->assertSame(0, $rows);
    }
}
