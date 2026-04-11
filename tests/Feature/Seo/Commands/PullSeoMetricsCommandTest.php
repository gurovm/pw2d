<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Commands;

use App\Actions\Seo\PullSeoMetrics;
use App\Actions\Seo\PullSeoMetricsResult;
use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Feature tests for PullSeoMetricsCommand (pw2d:seo:pull).
 */
class PullSeoMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind fakes so no live API calls happen.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:test.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    return collect([
                        ['url' => 'https://test.com/', 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null],
                    ]);
                }
            };
        });

        app()->bind(GoogleAnalyticsService::class, function () {
            return new class extends GoogleAnalyticsService {
                public function __construct()
                {
                    parent::__construct('properties/123', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    return collect([
                        ['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3],
                    ]);
                }
            };
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function createEnabledTenant(string $id): Tenant
    {
        Tenant::create(['id' => $id, 'name' => ucfirst($id)]);
        $tenant = Tenant::find($id);
        $tenant->gsc_site_url    = "sc-domain:{$id}.com";
        $tenant->ga4_property_id = "properties/123";
        $tenant->seo_enabled     = true;
        $tenant->save();

        return $tenant;
    }

    public function test_date_yesterday_picks_yesterday(): void
    {
        $tenant = $this->createEnabledTenant('acme');

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => 'yesterday'])
            ->assertSuccessful();

        $yesterday = CarbonImmutable::yesterday('UTC')->format('Y-m-d');

        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('metric_date', $yesterday)
            ->count();

        $this->assertGreaterThan(0, $count);
    }

    public function test_date_specific_yyyy_mm_dd_works(): void
    {
        $this->createEnabledTenant('acme');

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => '2026-04-01'])
            ->assertSuccessful();

        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('metric_date', '2026-04-01')
            ->count();

        $this->assertGreaterThan(0, $count);
    }

    public function test_invalid_date_format_errors_out(): void
    {
        $this->createEnabledTenant('acme');

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => 'garbage'])
            ->assertFailed();
    }

    public function test_seo_enabled_false_tenants_are_skipped_when_no_tenant_arg(): void
    {
        // This tenant has seo_enabled=false — should be skipped.
        Tenant::create(['id' => 'disabled', 'name' => 'Disabled']);
        $disabled = Tenant::find('disabled');
        $disabled->seo_enabled = false;
        $disabled->save();

        // This tenant IS enabled.
        $this->createEnabledTenant('enabled');

        $this->artisan('pw2d:seo:pull')
            ->assertSuccessful();

        // Disabled tenant must have zero seo_metrics rows.
        $disabledCount = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'disabled')
            ->count();

        $this->assertSame(0, $disabledCount);

        // Enabled tenant has rows.
        $enabledCount = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'enabled')
            ->count();

        $this->assertGreaterThan(0, $enabledCount);
    }

    public function test_single_tenant_arg_bypasses_seo_enabled_check(): void
    {
        // Disabled tenant — but we're passing it explicitly.
        Tenant::create(['id' => 'disabled', 'name' => 'Disabled']);
        $disabled = Tenant::find('disabled');
        $disabled->seo_enabled      = false;
        $disabled->gsc_site_url     = 'sc-domain:disabled.com';
        $disabled->ga4_property_id  = 'properties/999';
        $disabled->save();

        $this->artisan('pw2d:seo:pull', ['tenant' => 'disabled', '--date' => '2026-04-01'])
            ->assertSuccessful();

        // Rows should exist despite seo_enabled=false.
        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'disabled')
            ->count();

        $this->assertGreaterThan(0, $count);
    }
}
