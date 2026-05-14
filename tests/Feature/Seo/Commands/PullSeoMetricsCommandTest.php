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
 * Child services are faked via container bindings. Spec 016 tests verify
 * the date-window logic (4-day GSC default, 1-day GA4 default, explicit-date
 * backward-compat, and --gsc-window-days override).
 *
 * F23 changes:
 * - GSC fakes now implement fetchUrlMetricsForRange() instead of fetchUrlMetrics().
 * - Date-window tests now verify the startDate/endDate range passed to
 *   fetchUrlMetricsForRange(), rather than counting per-date calls.
 *
 * F25 changes:
 * - Zero upserts with no errors → SUCCESS (was FAILURE under old semantic).
 * - Errors during processing → FAILURE.
 * - No tenants matched → FAILURE (preserved).
 */
class PullSeoMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Default fake GSC: implements fetchUrlMetricsForRange (F23 shape).
        // Returns 1 row for every date in the requested range.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:test.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    $buckets = collect();
                    for ($d = $startDate; ! $d->greaterThan($endDate); $d = $d->addDay()) {
                        $dateKey = $d->format('Y-m-d');
                        $buckets->put($dateKey, collect([
                            ['url' => "https://test.com/{$dateKey}", 'impressions' => 100, 'clicks' => 5, 'ctr' => 0.05, 'position' => 3.0, 'top_query' => null],
                        ]));
                    }
                    return $buckets;
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

    // ── F23 date-window tests (updated) ──────────────────────────────────────

    /**
     * --date=yesterday (keyword) triggers a 4-day GSC window.
     *
     * After F23, GSC is fetched in a SINGLE ranged call. We verify the window
     * size by inspecting the startDate/endDate passed to fetchUrlMetricsForRange:
     * endDate must be yesterday, startDate must be yesterday-3d (4-day window).
     * GA4 is still called once per date via its own loop — unchanged.
     */
    public function test_date_yesterday_triggers_4_day_gsc_window_and_1_day_ga4_window(): void
    {
        $this->createEnabledTenant('acme');

        // Capture the range passed to fetchUrlMetricsForRange.
        $gscRange = new \stdClass();
        $gscRange->startDate = null;
        $gscRange->endDate   = null;
        $gscRange->calls     = 0;

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscRange) {
            return new class($gscRange) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    $this->tracker->calls++;
                    $this->tracker->startDate = $startDate->format('Y-m-d');
                    $this->tracker->endDate   = $endDate->format('Y-m-d');

                    // Return one unique URL per date so rows land in DB.
                    $buckets = collect();
                    for ($d = $startDate; ! $d->greaterThan($endDate); $d = $d->addDay()) {
                        $dateKey = $d->format('Y-m-d');
                        $buckets->put($dateKey, collect([
                            ['url' => "https://acme.com/{$dateKey}", 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                        ]));
                    }
                    return $buckets;
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

        // F23: fetchUrlMetricsForRange called exactly once for the full window.
        $this->assertSame(1, $gscRange->calls, 'fetchUrlMetricsForRange must be called exactly once');

        // The range covers 4 days: yesterday-3d through yesterday.
        $yesterday  = CarbonImmutable::yesterday('UTC')->format('Y-m-d');
        $windowStart = CarbonImmutable::yesterday('UTC')->subDays(3)->format('Y-m-d');
        $this->assertSame($yesterday, $gscRange->endDate, 'GSC endDate must be yesterday');
        $this->assertSame($windowStart, $gscRange->startDate, 'GSC startDate must be 3 days before yesterday (4-day window)');

        // GA4: still called once (1-day default window), and the date is yesterday.
        $this->assertCount(1, $ga4DatesReceived->dates, '--date=yesterday should trigger 1 GA4 date pull');
        $this->assertSame($yesterday, $ga4DatesReceived->dates[0]);
    }

    /**
     * An explicit YYYY-MM-DD anchor with no window flags forces both windows to 1.
     *
     * After F23, the single-date GSC call still issues one fetchUrlMetricsForRange
     * call, but with startDate == endDate == the explicit date.
     */
    public function test_explicit_date_defaults_both_windows_to_1(): void
    {
        $this->createEnabledTenant('acme');

        $gscRange = new \stdClass();
        $gscRange->startDate = null;
        $gscRange->endDate   = null;

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscRange) {
            return new class($gscRange) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    $this->tracker->startDate = $startDate->format('Y-m-d');
                    $this->tracker->endDate   = $endDate->format('Y-m-d');
                    return collect([
                        '2026-04-01' => collect([
                            ['url' => 'https://acme.com/', 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                        ]),
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

        // Single-date: GSC window = 1, so startDate == endDate == the explicit date.
        $this->assertSame('2026-04-01', $gscRange->startDate);
        $this->assertSame('2026-04-01', $gscRange->endDate);

        // GA4 also gets exactly one date.
        $this->assertCount(1, $ga4DatesReceived->dates, 'Explicit YYYY-MM-DD → GA4 window should be 1');
        $this->assertSame('2026-04-01', $ga4DatesReceived->dates[0]);
    }

    /**
     * --gsc-window-days=2 overrides the 4-day default.
     * The GSC range should span exactly 2 days (startDate = yesterday-1d, endDate = yesterday).
     */
    public function test_gsc_window_days_overrides_default(): void
    {
        $this->createEnabledTenant('acme');

        $gscRange = new \stdClass();
        $gscRange->startDate = null;
        $gscRange->endDate   = null;

        app()->bind(GoogleSearchConsoleService::class, function () use ($gscRange) {
            return new class($gscRange) extends GoogleSearchConsoleService {
                public function __construct(private \stdClass $tracker)
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    $this->tracker->startDate = $startDate->format('Y-m-d');
                    $this->tracker->endDate   = $endDate->format('Y-m-d');

                    $buckets = collect();
                    for ($d = $startDate; ! $d->greaterThan($endDate); $d = $d->addDay()) {
                        $dateKey = $d->format('Y-m-d');
                        $buckets->put($dateKey, collect([
                            ['url' => "https://acme.com/{$dateKey}", 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                        ]));
                    }
                    return $buckets;
                }
            };
        });

        $this->artisan('pw2d:seo:pull', [
            'tenant'            => 'acme',
            '--date'            => 'yesterday',
            '--gsc-window-days' => '2',
        ])->assertSuccessful();

        $yesterday   = CarbonImmutable::yesterday('UTC')->format('Y-m-d');
        $dayBefore   = CarbonImmutable::yesterday('UTC')->subDay()->format('Y-m-d');

        $this->assertSame($yesterday, $gscRange->endDate, 'GSC endDate must be yesterday');
        $this->assertSame($dayBefore, $gscRange->startDate, '--gsc-window-days=2 → startDate must be yesterday-1d');
    }

    // ── F25 exit code tests ───────────────────────────────────────────────────

    /**
     * F25: Zero upserts with no errors must exit SUCCESS.
     *
     * Prior to F25, the command returned FAILURE when no rows were upserted.
     * A fresh install where GSC has 3-day lag would trigger this every night.
     * New contract: SUCCESS unless an actual error occurs.
     */
    public function test_zero_rows_no_errors_exits_success(): void
    {
        $this->createEnabledTenant('acme');

        // Bind a GSC fake that returns empty collections (0 rows, no errors).
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    // Empty buckets — GSC has no data for this range yet.
                    return collect();
                }
            };
        });

        // GA4 also returns 0 rows.
        app()->bind(GoogleAnalyticsService::class, function () {
            return new class extends GoogleAnalyticsService {
                public function __construct()
                {
                    parent::__construct('properties/123', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    return collect(); // no rows
                }
            };
        });

        // F25: 0 upserts + 0 errors = SUCCESS (not FAILURE as before).
        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => '2026-04-01'])
            ->assertExitCode(0);
    }

    /**
     * F25: Errors during processing must exit FAILURE.
     *
     * If the GSC service throws, PullGscMetrics catches it and returns a
     * PullResult with errors. The command must surface that as FAILURE.
     */
    public function test_errors_during_processing_exit_failure(): void
    {
        $this->createEnabledTenant('acme');

        // GSC throws — simulates an auth or quota error.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    throw new \RuntimeException('GSC API quota exceeded');
                }
            };
        });

        // GA4 succeeds — errors in GSC alone must be enough to flip the exit code.
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

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => '2026-04-01'])
            ->assertExitCode(1);
    }

    /**
     * F25 (preserved): No tenants matched → FAILURE regardless of error state.
     */
    public function test_no_tenants_matched_exits_failure(): void
    {
        // No seo_enabled tenants in DB → command warns and fails.
        $this->artisan('pw2d:seo:pull')
            ->assertExitCode(1);
    }
}
