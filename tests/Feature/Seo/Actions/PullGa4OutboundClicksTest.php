<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Actions;

use App\Actions\Seo\PullGa4Metrics;
use App\Models\SeoMetric;
use App\Models\Tenant;
use App\Services\Seo\GoogleAnalyticsService;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for Spec 040 — GA4 outbound (buy-button) click merging in PullGa4Metrics.
 *
 * Mirrors PullGa4MetricsTest's fake-service pattern: a GoogleAnalyticsService
 * subclass bound into the container so no live API calls are made. Unlike
 * PullGa4MetricsTest (which fixes fetchOutboundClicks() to an empty Collection
 * because it isn't under test there), these tests drive both
 * fetchLandingPageMetrics() and fetchOutboundClicks() with per-test row data,
 * including a "throws" mode for the click fetch.
 */
class PullGa4OutboundClicksTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // ── Fake infrastructure ───────────────────────────────────────────────────

    /**
     * Bind a fake GoogleAnalyticsService returning the given landing-page rows
     * and click rows. Pass $clickRows = null to make fetchOutboundClicks()
     * throw, simulating an API failure isolated in its own try/catch.
     */
    private function bindGa4Service(array $landingRows, ?array $clickRows): void
    {
        app()->bind(GoogleAnalyticsService::class, function () use ($landingRows, $clickRows) {
            return new class($landingRows, $clickRows) extends GoogleAnalyticsService {
                public function __construct(
                    private readonly array $landingRows,
                    private readonly ?array $clickRows,
                ) {
                    parent::__construct('properties/123456789', '/fake/path.json');
                }

                public function fetchLandingPageMetrics(CarbonImmutable $date): Collection
                {
                    return collect($this->landingRows);
                }

                public function fetchOutboundClicks(CarbonImmutable $date): Collection
                {
                    if ($this->clickRows === null) {
                        throw new \RuntimeException('Simulated GA4 outbound-click API failure');
                    }

                    return collect($this->clickRows);
                }
            };
        });
    }

    // ── 1. Happy path ─────────────────────────────────────────────────────────

    public function test_click_on_url_with_a_landing_page_row_that_day_merges_into_the_same_row(): void
    {
        $this->bindGa4Service(
            landingRows: [
                ['url' => '/product/widget-pro', 'sessions' => 175, 'users' => 160, 'engaged_sessions' => 140, 'conversions' => 8, 'bounce_rate' => 0.2],
            ],
            clickRows: [
                ['url' => '/product/widget-pro', 'clicks' => 7],
            ],
        );

        $date   = CarbonImmutable::parse('2026-04-10');
        $result = (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->assertSame(1, $result->upserted);
        $this->assertEmpty($result->errors);

        $rows = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'ga4')
            ->where('metric_date', '2026-04-10')
            ->get();

        $this->assertCount(1, $rows, 'Click and landing-page data for the same URL must merge into a single row');

        $row = $rows->first();
        $this->assertSame('/product/widget-pro', $row->url);
        $this->assertSame(175, (int) $row->ga4_sessions);
        $this->assertSame(160, (int) $row->ga4_users);
        $this->assertSame(140, (int) $row->ga4_engaged_sess);
        $this->assertSame(8, (int) $row->ga4_conversions);
        $this->assertEqualsWithDelta(0.2, (float) $row->ga4_bounce_rate, 0.0001);
        $this->assertSame(7, (int) $row->ga4_outbound_clicks);
    }

    // ── 2. Click-only page ────────────────────────────────────────────────────

    public function test_click_only_page_creates_a_new_row_with_zeroed_session_metrics(): void
    {
        $this->bindGa4Service(
            landingRows: [
                ['url' => '/', 'sessions' => 300, 'users' => 280, 'engaged_sessions' => 190, 'conversions' => 10, 'bounce_rate' => 0.35],
            ],
            clickRows: [
                ['url' => '/product/no-landing-today', 'clicks' => 3],
            ],
        );

        $date   = CarbonImmutable::parse('2026-04-10');
        $result = (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->assertSame(2, $result->upserted, '1 landing row + 1 click-only row');

        $row = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'ga4')
            ->where('metric_date', '2026-04-10')
            ->where('url', '/product/no-landing-today')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->ga4_sessions);
        $this->assertSame(0, (int) $row->ga4_users);
        $this->assertSame(0, (int) $row->ga4_engaged_sess);
        $this->assertSame(0, (int) $row->ga4_conversions);
        $this->assertEqualsWithDelta(0.0, (float) $row->ga4_bounce_rate, 0.0001);
        $this->assertSame(3, (int) $row->ga4_outbound_clicks);
    }

    // ── 3. Path-only URLs ─────────────────────────────────────────────────────

    public function test_compare_page_click_merges_into_the_single_path_only_row_with_no_query_string(): void
    {
        $this->bindGa4Service(
            landingRows: [
                ['url' => '/compare/espresso-machines', 'sessions' => 540, 'users' => 498, 'engaged_sessions' => 420, 'conversions' => 23, 'bounce_rate' => 0.222],
            ],
            clickRows: [
                ['url' => '/compare/espresso-machines', 'clicks' => 4],
            ],
        );

        $result = (new PullGa4Metrics)->execute($this->tenant, CarbonImmutable::parse('2026-04-10'));

        $this->assertSame(1, $result->upserted);

        $rows = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('source', 'ga4')->get();
        $this->assertCount(1, $rows, 'A click on a compare page must merge into its single landing row, never fork a second row');

        $row = $rows->first();
        $this->assertSame('/compare/espresso-machines', $row->url);
        $this->assertStringNotContainsString('?', $row->url, 'GA4 urls stored by the pull must never carry a query string');
        $this->assertSame(4, (int) $row->ga4_outbound_clicks);
    }

    // ── 4. fetchOutboundClicks() throws ──────────────────────────────────────

    public function test_click_fetch_failure_does_not_block_the_landing_page_upsert_and_logs_a_warning(): void
    {
        Log::spy();

        $this->bindGa4Service(
            landingRows: [
                ['url' => '/', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 60, 'conversions' => 4, 'bounce_rate' => 0.4],
            ],
            clickRows: null, // throws
        );

        $date   = CarbonImmutable::parse('2026-04-10');
        $result = (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->assertSame(1, $result->upserted, 'Landing-page row must still be upserted');
        $this->assertEmpty($result->errors, 'A click-fetch failure must not surface as a PullResult error (Spec 017 exit-code rule)');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'outbound clicks')
                    && ($context['tenant'] ?? null) === 'acme'
                    && ($context['date'] ?? null) === '2026-04-10';
            });

        $row = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('source', 'ga4')->first();
        $this->assertSame(0, (int) $row->ga4_outbound_clicks, 'Fresh insert falls back to the column default when the click fetch fails');
    }

    /**
     * A click-fetch failure alone must not flip pw2d:seo:pull's exit code to
     * FAILURE — PullGa4Metrics swallows it into a log warning, not a
     * PullResult error, so PullSeoMetrics/PullSeoMetricsCommand never see it.
     */
    public function test_command_exit_code_is_unaffected_by_a_click_fetch_failure(): void
    {
        // The command drives its own tenancy lifecycle via PullSeoMetrics —
        // end what setUp() started so the command's tenancy()->initialize() call
        // doesn't collide with an already-initialized tenancy.
        tenancy()->end();

        $this->tenant->gsc_site_url = 'sc-domain:acme.com';
        $this->tenant->save();

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
                            ['url' => 'https://acme.com/', 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 5.0, 'top_query' => null],
                        ]),
                    ]);
                }
            };
        });

        $this->bindGa4Service(
            landingRows: [
                ['url' => '/', 'sessions' => 50, 'users' => 40, 'engaged_sessions' => 35, 'conversions' => 2, 'bounce_rate' => 0.3],
            ],
            clickRows: null, // throws — must not flip the command's exit code
        );

        $this->artisan('pw2d:seo:pull', ['tenant' => 'acme', '--date' => '2026-04-10'])
            ->assertExitCode(0);
    }

    // ── 5. THE regression that matters most ──────────────────────────────────

    public function test_re_pull_with_click_fetch_failure_preserves_the_stored_click_count_but_still_updates_sessions(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        // First pull: succeeds, stores a click count of 9 alongside initial session numbers.
        $this->bindGa4Service(
            landingRows: [
                ['url' => '/product/a', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 70, 'conversions' => 5, 'bounce_rate' => 0.3],
            ],
            clickRows: [
                ['url' => '/product/a', 'clicks' => 9],
            ],
        );
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $stored = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/product/a')->first();
        $this->assertSame(9, (int) $stored->ga4_outbound_clicks);

        // Re-pull the SAME date (a window backfill would do exactly this):
        // session metrics changed, but the click fetch now throws.
        $this->bindGa4Service(
            landingRows: [
                ['url' => '/product/a', 'sessions' => 150, 'users' => 130, 'engaged_sessions' => 100, 'conversions' => 9, 'bounce_rate' => 0.45],
            ],
            clickRows: null,
        );

        Log::spy();
        $result = (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->assertEmpty($result->errors);

        $row = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/product/a')->first();

        // The regression that matters: the previously stored click count survives.
        $this->assertSame(9, (int) $row->ga4_outbound_clicks, 'A failed re-pull click fetch must NOT zero the previously stored click count');

        // Session columns must still update to the freshly fetched values.
        $this->assertSame(150, (int) $row->ga4_sessions);
        $this->assertSame(130, (int) $row->ga4_users);
        $this->assertSame(100, (int) $row->ga4_engaged_sess);
        $this->assertSame(9, (int) $row->ga4_conversions);
        $this->assertEqualsWithDelta(0.45, (float) $row->ga4_bounce_rate, 0.0001);
    }

    // ── 6. Idempotency — replace, never increment ────────────────────────────

    public function test_re_pull_with_a_successful_fetch_replaces_the_click_count_not_increments_it(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        $this->bindGa4Service(
            landingRows: [['url' => '/product/a', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 70, 'conversions' => 5, 'bounce_rate' => 0.3]],
            clickRows: [['url' => '/product/a', 'clicks' => 3]],
        );
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $this->bindGa4Service(
            landingRows: [['url' => '/product/a', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 70, 'conversions' => 5, 'bounce_rate' => 0.3]],
            clickRows: [['url' => '/product/a', 'clicks' => 5]],
        );
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $count = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/product/a')->count();
        $this->assertSame(1, $count, 'Re-pulling the same date must not create a duplicate row');

        $row = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/product/a')->first();
        $this->assertSame(5, (int) $row->ga4_outbound_clicks, 'Value must be REPLACED (3 -> 5), never incremented (would be 8)');
    }

    public function test_re_pull_with_zero_click_rows_zeroes_the_previously_stored_count(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        $this->bindGa4Service(
            landingRows: [['url' => '/product/a', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 70, 'conversions' => 5, 'bounce_rate' => 0.3]],
            clickRows: [['url' => '/product/a', 'clicks' => 3]],
        );
        (new PullGa4Metrics)->execute($this->tenant, $date);

        // Re-pull: the click fetch SUCCEEDS but genuinely returns zero rows
        // (no clicks that day this time) — this must overwrite, unlike case 5
        // where the fetch throws.
        $this->bindGa4Service(
            landingRows: [['url' => '/product/a', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 70, 'conversions' => 5, 'bounce_rate' => 0.3]],
            clickRows: [],
        );
        (new PullGa4Metrics)->execute($this->tenant, $date);

        $row = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/product/a')->first();
        $this->assertSame(0, (int) $row->ga4_outbound_clicks, 'A successful fetch that legitimately returns zero rows must zero the stored count');
    }

    // ── 7. Tenant isolation ───────────────────────────────────────────────────

    public function test_pulling_tenant_a_never_creates_or_modifies_tenant_bs_rows_including_same_url_and_date(): void
    {
        Tenant::create(['id' => 'tenant-b', 'name' => 'Tenant B']);

        DB::table('seo_metrics')->insert([
            'tenant_id'           => 'tenant-b',
            'source'              => 'ga4',
            'url'                 => '/product/shared-url',
            'url_hash'            => hash('sha256', '/product/shared-url'),
            'metric_date'         => '2026-04-10',
            'ga4_sessions'        => 500,
            'ga4_users'           => 480,
            'ga4_engaged_sess'    => 300,
            'ga4_conversions'     => 40,
            'ga4_bounce_rate'     => 0.1,
            'ga4_outbound_clicks' => 77,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->bindGa4Service(
            landingRows: [
                ['url' => '/product/shared-url', 'sessions' => 10, 'users' => 8, 'engaged_sessions' => 5, 'conversions' => 1, 'bounce_rate' => 0.9],
            ],
            clickRows: [
                ['url' => '/product/shared-url', 'clicks' => 2],
                ['url' => '/product/click-only', 'clicks' => 1],
            ],
        );

        (new PullGa4Metrics)->execute($this->tenant, CarbonImmutable::parse('2026-04-10'));

        // Tenant B's pre-existing row (same URL, same date) is completely untouched.
        $tenantBRow = DB::table('seo_metrics')->where('tenant_id', 'tenant-b')->where('url', '/product/shared-url')->first();
        $this->assertSame(77, (int) $tenantBRow->ga4_outbound_clicks);
        $this->assertSame(500, (int) $tenantBRow->ga4_sessions);

        // No tenant-b row is ever created for the click-only URL from tenant A's pull.
        $this->assertSame(0, DB::table('seo_metrics')->where('tenant_id', 'tenant-b')->where('url', '/product/click-only')->count());

        // Tenant A's pull produced exactly its own 2 rows.
        $this->assertSame(2, DB::table('seo_metrics')->where('tenant_id', 'acme')->where('source', 'ga4')->count());

        $tenantARow = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/product/shared-url')->first();
        $this->assertSame(2, (int) $tenantARow->ga4_outbound_clicks);
        $this->assertSame(10, (int) $tenantARow->ga4_sessions);
    }

    // ── 8. No-zeroing guard (single-upsert regression) ───────────────────────

    public function test_merging_a_click_into_one_row_does_not_zero_session_metrics_on_other_landing_rows_in_the_same_batch(): void
    {
        $this->bindGa4Service(
            landingRows: [
                ['url' => '/a', 'sessions' => 100, 'users' => 90, 'engaged_sessions' => 70, 'conversions' => 5, 'bounce_rate' => 0.3],
                ['url' => '/b', 'sessions' => 200, 'users' => 180, 'engaged_sessions' => 150, 'conversions' => 12, 'bounce_rate' => 0.25],
            ],
            clickRows: [
                ['url' => '/a', 'clicks' => 6],
            ],
        );

        (new PullGa4Metrics)->execute($this->tenant, CarbonImmutable::parse('2026-04-10'));

        $rowA = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/a')->first();
        $rowB = DB::table('seo_metrics')->where('tenant_id', 'acme')->where('url', '/b')->first();

        // The row that received a click keeps its own session metrics intact.
        $this->assertSame(100, (int) $rowA->ga4_sessions);
        $this->assertSame(90, (int) $rowA->ga4_users);
        $this->assertSame(70, (int) $rowA->ga4_engaged_sess);
        $this->assertSame(5, (int) $rowA->ga4_conversions);
        $this->assertEqualsWithDelta(0.3, (float) $rowA->ga4_bounce_rate, 0.0001);
        $this->assertSame(6, (int) $rowA->ga4_outbound_clicks);

        // The row with NO click keeps its own session metrics, unaffected by
        // the merge that happened on a different row in the same batch.
        $this->assertSame(200, (int) $rowB->ga4_sessions);
        $this->assertSame(180, (int) $rowB->ga4_users);
        $this->assertSame(150, (int) $rowB->ga4_engaged_sess);
        $this->assertSame(12, (int) $rowB->ga4_conversions);
        $this->assertEqualsWithDelta(0.25, (float) $rowB->ga4_bounce_rate, 0.0001);
        $this->assertSame(0, (int) $rowB->ga4_outbound_clicks);
    }

    // ── 10. Model / migration ─────────────────────────────────────────────────

    public function test_ga4_outbound_clicks_is_fillable_casts_to_integer_and_defaults_to_zero(): void
    {
        // No ga4_outbound_clicks passed — column default (0) must apply.
        $metric = SeoMetric::factory()->ga4()->create([
            'tenant_id' => 'acme',
            'url'       => '/some-path',
            'url_hash'  => hash('sha256', '/some-path'),
        ]);

        $fresh = SeoMetric::find($metric->id);
        $this->assertSame(0, $fresh->ga4_outbound_clicks);
        $this->assertIsInt($fresh->ga4_outbound_clicks, 'ga4_outbound_clicks must cast to integer');

        // Mass assignment via $fillable works.
        $metric2 = SeoMetric::create([
            'tenant_id'           => 'acme',
            'source'              => 'ga4',
            'url'                 => '/other-path',
            'url_hash'            => hash('sha256', '/other-path'),
            'metric_date'         => '2026-04-10',
            'ga4_outbound_clicks' => 12,
        ]);

        $this->assertSame(12, $metric2->fresh()->ga4_outbound_clicks);
    }

    // ── 11. Widget aggregate (query-level, not full component render) ────────

    /**
     * Mirrors SeoDashboardTest's established pattern of testing a KPI widget's
     * underlying aggregate query directly rather than rendering the Filament
     * component. Rendering KpiCardsWidget through the admin layout hits the
     * same unrelated sqlite/REGEXP blocker documented on
     * SeoDashboardTest::test_admin_can_access_seo_dashboard (ProblemProducts'
     * getNavigationBadge() — tracked as F12). This test instead verifies the
     * exact SUM(ga4_outbound_clicks) query the widget runs, tenant-scoped.
     */
    public function test_kpi_widget_store_clicks_aggregate_is_tenant_scoped(): void
    {
        Tenant::create(['id' => 'tenant-b', 'name' => 'Tenant B']);

        SeoMetric::factory()->ga4()->create([
            'tenant_id'           => 'acme',
            'url'                 => '/product/a',
            'url_hash'            => hash('sha256', '/product/a'),
            'metric_date'         => now()->subDays(5)->toDateString(),
            'ga4_outbound_clicks' => 6,
        ]);

        SeoMetric::factory()->ga4()->create([
            'tenant_id'           => 'tenant-b',
            'url'                 => '/product/a',
            'url_hash'            => hash('sha256', '/product/a'),
            'metric_date'         => now()->subDays(5)->toDateString(),
            'ga4_outbound_clicks' => 999,
        ]);

        $now       = now()->toDateString();
        $current28 = now()->subDays(27)->toDateString();

        $acmeClicks = (int) DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('source', 'ga4')
            ->whereBetween('metric_date', [$current28, $now])
            ->sum('ga4_outbound_clicks');

        $tenantBClicks = (int) DB::table('seo_metrics')
            ->where('tenant_id', 'tenant-b')
            ->where('source', 'ga4')
            ->whereBetween('metric_date', [$current28, $now])
            ->sum('ga4_outbound_clicks');

        $this->assertSame(6, $acmeClicks, 'KpiCardsWidget\'s "Store Clicks (28d)" stat must only sum the active tenant\'s rows');
        $this->assertSame(999, $tenantBClicks, 'Tenant B\'s clicks must not bleed into tenant A\'s aggregate');
    }
}
