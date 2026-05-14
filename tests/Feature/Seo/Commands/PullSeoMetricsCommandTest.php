<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Commands;

use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Feature tests for PullSeoMetricsCommand (pw2d:seo:pull).
 *
 * Child services are faked via container bindings. New Spec 016 tests verify
 * the date-window logic (4-day GSC default, 1-day GA4 default, explicit-date
 * backward-compat, and --gsc-window-days override).
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
        $tenant->ga4_property_id = 'properties/123';
        $tenant->seo_enabled     = true;
        $tenant->save();

        return $tenant;
    }

    // ── Migrated baseline tests ───────────────────────────────────────────────

    public function test_date_yesterday_picks_yesterday(): void
    {
        $this->createEnabledTenant('acme');

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

        $disabledCount = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'disabled')
            ->count();

        $this->assertSame(0, $disabledCount);

        $enabledCount = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'enabled')
            ->count();

        $this->assertGreaterThan(0, $enabledCount);
    }

    public function test_single_tenant_arg_bypasses_seo_enabled_check(): void
    {
        Tenant::create(['id' => 'disabled', 'name' => 'Disabled']);
        $disabled = Tenant::find('disabled');
        $disabled->seo_enabled      = false;
        $disabled->gsc_site_url     = 'sc-domain:disabled.com';
        $disabled->ga4_property_id  = 'properties/999';
        $disabled->save();

        $this->artisan('pw2d:seo:pull', ['tenant' => 'disabled', '--date' => '2026-04-01'])
            ->assertSuccessful();

        $count = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'disabled')
            ->count();

        $this->assertGreaterThan(0, $count);
    }

    // ── New Spec 016 §4.3 window tests ───────────────────────────────────────

    /**
     * --date=yesterday (keyword) must trigger the 4-day GSC default window
     * and the 1-day GA4 default window.
     *
     * We verify this by inspecting the distinct metric_dates written to
     * seo_metrics: GSC should have 4, GA4 should have 1.
     */
    public function test_date_yesterday_triggers_4_day_gsc_window_and_1_day_ga4_window(): void
    {
        $this->createEnabledTenant('acme');

        // Capture which dates the GSC fake is called with.
        $gscDatesReceived = new \stdClass();
        $gscDatesReceived->dates = [];

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscDatesReceived) {
            return new class($gscDatesReceived) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    $this->tracker->dates[] = $date->format('Y-m-d');
                    // Return a URL unique per date so each call produces a distinct row.
                    return collect([
                        ['url' => "https://acme.com/{$date->format('Y-m-d')}", 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                    ]);
                }
            };
        });

        $ga4DatesReceived = new \stdClass();
        $ga4DatesReceived->dates = [];

        app()->bind(GoogleAnalyticsService::class, function () use ($ga4DatesReceived) {
            return new class($ga4DatesReceived) extends GoogleAnalyticsService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('properties/123', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    $this->tracker->dates[] = $date->format('Y-m-d');
                    return collect([
                        ['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3],
                    ]);
                }
            };
        });

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => 'yesterday'])
            ->assertSuccessful();

        $this->assertCount(4, $gscDatesReceived->dates, '--date=yesterday should trigger 4 GSC date pulls');
        $this->assertCount(1, $ga4DatesReceived->dates, '--date=yesterday should trigger 1 GA4 date pull');

        // The first GSC date must be yesterday.
        $yesterday = CarbonImmutable::yesterday('UTC')->format('Y-m-d');
        $this->assertSame($yesterday, $gscDatesReceived->dates[0]);

        // The GA4 date must also be yesterday.
        $this->assertSame($yesterday, $ga4DatesReceived->dates[0]);
    }

    /**
     * An explicit YYYY-MM-DD anchor with no window flags must force both
     * windows to 1 (backward-compat single-date backfill).
     */
    public function test_explicit_date_defaults_both_windows_to_1(): void
    {
        $this->createEnabledTenant('acme');

        $gscDatesReceived = new \stdClass();
        $gscDatesReceived->dates = [];

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscDatesReceived) {
            return new class($gscDatesReceived) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    $this->tracker->dates[] = $date->format('Y-m-d');
                    return collect([
                        ['url' => 'https://acme.com/', 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                    ]);
                }
            };
        });

        $ga4DatesReceived = new \stdClass();
        $ga4DatesReceived->dates = [];

        app()->bind(GoogleAnalyticsService::class, function () use ($ga4DatesReceived) {
            return new class($ga4DatesReceived) extends GoogleAnalyticsService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('properties/123', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    $this->tracker->dates[] = $date->format('Y-m-d');
                    return collect([
                        ['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3],
                    ]);
                }
            };
        });

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => '2026-04-01'])
            ->assertSuccessful();

        $this->assertCount(1, $gscDatesReceived->dates, 'Explicit YYYY-MM-DD → GSC window should be 1');
        $this->assertCount(1, $ga4DatesReceived->dates, 'Explicit YYYY-MM-DD → GA4 window should be 1');
        $this->assertSame('2026-04-01', $gscDatesReceived->dates[0]);
        $this->assertSame('2026-04-01', $ga4DatesReceived->dates[0]);
    }

    /**
     * --gsc-window-days=2 must override the 4-day default, even when combined
     * with --date=yesterday.
     */
    public function test_gsc_window_days_overrides_default(): void
    {
        $this->createEnabledTenant('acme');

        $gscDatesReceived = new \stdClass();
        $gscDatesReceived->dates = [];

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscDatesReceived) {
            return new class($gscDatesReceived) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    $this->tracker->dates[] = $date->format('Y-m-d');
                    return collect([
                        ['url' => "https://acme.com/{$date->format('Y-m-d')}", 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                    ]);
                }
            };
        });

        $this->artisan('pw2d:seo:pull', [
            'tenant'              => 'acme',
            '--date'              => 'yesterday',
            '--gsc-window-days'   => '2',
        ])->assertSuccessful();

        $this->assertCount(2, $gscDatesReceived->dates, '--gsc-window-days=2 should produce exactly 2 GSC date pulls');
    }
}
