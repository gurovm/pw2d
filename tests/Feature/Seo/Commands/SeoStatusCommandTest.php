<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Commands;

use App\Models\SeoMetric;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tests for SeoStatusCommand (pw2d:seo:status).
 *
 * All tests seed SeoMetric rows via the factory and check exit codes + output
 * strings. No live API calls are made — the command is read-only and queries
 * only the seo_metrics table plus tenant attributes.
 *
 * Exit-code rules (spec §5.4):
 *   0 — all configured sources HEALTHY (or only UNCONFIGURED tenants present).
 *   1 — at least one configured source is STALE / NO_DATA / ERROR.
 */
class SeoStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a fully-configured, seo_enabled tenant.
     */
    private function createEnabledTenant(string $id): Tenant
    {
        Tenant::create(['id' => $id, 'name' => ucfirst($id)]);
        $tenant = Tenant::find($id);
        $tenant->seo_enabled     = true;
        $tenant->gsc_site_url    = "sc-domain:{$id}.com";
        $tenant->ga4_property_id = "properties/100{$id}";
        $tenant->save();

        return $tenant;
    }

    /**
     * Insert one or more SeoMetric rows via the factory.
     *
     * Uses the appropriate factory state (gsc/ga4) so no raw DB inserts are
     * needed. The url is made unique by incorporating tenantId, source, daysAgo,
     * and an index so that repeated calls never collide on the unique index.
     *
     * @param  int  $rows  Number of rows to insert (each gets a distinct URL).
     */
    private function insertMetric(string $tenantId, string $source, int $daysAgo, int $rows = 1): void
    {
        $date = now('UTC')->subDays($daysAgo)->format('Y-m-d');

        for ($i = 0; $i < $rows; $i++) {
            $url = "https://{$tenantId}.com/page-{$source}-{$daysAgo}-{$i}";

            $factory = SeoMetric::factory()->{$source}()->create([
                'tenant_id'   => $tenantId,
                'url'         => $url,
                'url_hash'    => hash('sha256', $url),
                'metric_date' => $date,
            ]);
        }
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Test 1: A tenant with both sources fresh (GSC 2d ago, GA4 1d ago) → HEALTHY + exit 0.
     */
    public function test_healthy_tenant_returns_exit_0(): void
    {
        $this->createEnabledTenant('acme');

        $this->insertMetric('acme', 'gsc', daysAgo: 2);
        $this->insertMetric('acme', 'ga4', daysAgo: 1);

        $this->artisan('pw2d:seo:status', ['tenant' => 'acme'])
            ->assertExitCode(0)
            ->expectsOutputToContain('HEALTHY');
    }

    /**
     * Test 2: GSC latest row is 10 days old → STALE → exit 1.
     */
    public function test_stale_gsc_returns_exit_1(): void
    {
        $this->createEnabledTenant('acme');

        $this->insertMetric('acme', 'gsc', daysAgo: 10);
        $this->insertMetric('acme', 'ga4', daysAgo: 1);

        $this->artisan('pw2d:seo:status', ['tenant' => 'acme'])
            ->assertExitCode(1)
            ->expectsOutputToContain('STALE');
    }

    /**
     * Test 3: seo_enabled=false tenant → output contains "UNCONFIGURED" but exit 0
     * (UNCONFIGURED never triggers non-zero exit — spec §5.4).
     */
    public function test_unconfigured_tenant_does_not_break_exit_code(): void
    {
        Tenant::create(['id' => 'disabled', 'name' => 'Disabled']);
        $tenant = Tenant::find('disabled');
        $tenant->seo_enabled = false;
        $tenant->save();

        $this->artisan('pw2d:seo:status', ['tenant' => 'disabled'])
            ->assertExitCode(0)
            ->expectsOutputToContain('UNCONFIGURED');
    }

    /**
     * Test 4: Tenant has gsc_site_url configured but zero rows in seo_metrics → NO_DATA + exit 1.
     */
    public function test_no_data_when_configured_but_zero_rows(): void
    {
        $this->createEnabledTenant('acme');
        // Intentionally insert no rows.

        $this->artisan('pw2d:seo:status', ['tenant' => 'acme'])
            ->assertExitCode(1)
            ->expectsOutputToContain('NO_DATA');
    }

    /**
     * Test 5: --days=N adjusts the windowed row count shown in the table.
     *
     * Insert 5 rows within 7 days and 5 more rows at 30 days ago.
     * --days=7 must report 5 rows in the Rows column; --days=30 must report 10.
     *
     * Assertion uses a regex anchored to the GSC table row so that unrelated
     * digits (ages, positions, percentages in other columns) cannot cause a
     * false positive. The pattern matches the Rows column value between two
     * pipe characters: "| <count> |".
     */
    public function test_days_option_adjusts_row_count_summary(): void
    {
        $this->createEnabledTenant('acme');

        // 5 rows within last 7 days (spread across days 1–5 so they're distinct URLs).
        for ($d = 1; $d <= 5; $d++) {
            $this->insertMetric('acme', 'gsc', daysAgo: $d);
        }

        // 5 more rows at exactly 30 days ago (all same date, different URLs via $rows param).
        $this->insertMetric('acme', 'gsc', daysAgo: 30, rows: 5);

        // GA4 also needs a fresh row to avoid STALE on that source skewing exit code.
        $this->insertMetric('acme', 'ga4', daysAgo: 1);

        // With --days=7 the GSC row should show "5" in the Rows column.
        // Pattern: "| GSC " followed by any columns until a "| 5 |" Rows cell.
        Artisan::call('pw2d:seo:status', ['tenant' => 'acme', '--days' => '7']);
        $output7 = Artisan::output();

        $this->assertMatchesRegularExpression(
            '/GSC\s*\|\s*✓[^|]*\|[^|]*\|[^|]*\|\s*5\s*\|/',
            $output7,
            'With --days=7, the GSC Rows column must show exactly 5',
        );

        // With --days=30 the windowed count includes all 10 GSC rows.
        Artisan::call('pw2d:seo:status', ['tenant' => 'acme', '--days' => '30']);
        $output30 = Artisan::output();

        $this->assertMatchesRegularExpression(
            '/GSC\s*\|\s*✓[^|]*\|[^|]*\|[^|]*\|\s*10\s*\|/',
            $output30,
            'With --days=30, the GSC Rows column must show exactly 10',
        );
    }

    /**
     * Test 6: Passing a tenant argument scopes the report to that tenant only.
     */
    public function test_single_tenant_filter_works(): void
    {
        $this->createEnabledTenant('acme');
        $this->createEnabledTenant('other');

        $this->insertMetric('acme', 'gsc', daysAgo: 1);
        $this->insertMetric('acme', 'ga4', daysAgo: 1);
        // "other" has stale data — if it were included exit code would be 1.
        $this->insertMetric('other', 'gsc', daysAgo: 20);
        $this->insertMetric('other', 'ga4', daysAgo: 20);

        // Scoping to "acme" must return exit 0 (acme is healthy).
        $this->artisan('pw2d:seo:status', ['tenant' => 'acme'])
            ->assertExitCode(0)
            ->expectsOutputToContain('acme')
            ->doesntExpectOutputToContain('other');
    }

    /**
     * Test 7: When the service-account file path does not exist, the output
     * must contain a warning line (spec §5.2 credential check).
     *
     * Config is restored via try/finally so subsequent tests are not affected
     * by the mutated global config state.
     */
    public function test_missing_service_account_file_is_flagged(): void
    {
        $this->createEnabledTenant('acme');
        $this->insertMetric('acme', 'gsc', daysAgo: 1);
        $this->insertMetric('acme', 'ga4', daysAgo: 1);

        $originalPath = config('seo.google.service_account_path');

        try {
            // Point the config to a path that definitely doesn't exist.
            config(['seo.google.service_account_path' => '/nonexistent/path/service-account.json']);

            $this->artisan('pw2d:seo:status', ['tenant' => 'acme'])
                ->expectsOutputToContain('NOT FOUND');
        } finally {
            config(['seo.google.service_account_path' => $originalPath]);
        }
    }

    /**
     * Test 8: Per-source independence — healthy GSC + missing ga4_property_id
     * must produce GSC=HEALTHY and GA4=UNCONFIGURED on the same tenant.
     * Exit code is 0 because UNCONFIGURED is not an error (spec §5.4).
     */
    public function test_per_source_independence_healthy_gsc_unconfigured_ga4(): void
    {
        Tenant::create(['id' => 'partial', 'name' => 'Partial']);
        $tenant = Tenant::find('partial');
        $tenant->seo_enabled     = true;
        $tenant->gsc_site_url    = 'sc-domain:partial.com';
        $tenant->ga4_property_id = null; // GA4 not configured
        $tenant->save();

        // Fresh GSC data.
        $this->insertMetric('partial', 'gsc', daysAgo: 1);
        // No GA4 rows (and no property ID).

        $this->artisan('pw2d:seo:status', ['tenant' => 'partial'])
            ->assertExitCode(0)
            ->expectsOutputToContain('HEALTHY')
            ->expectsOutputToContain('UNCONFIGURED');
    }

    /**
     * Test 9 (Task C): Summary banner counts must reflect actual status distribution.
     *
     * This is the test that would have caught the UNCONFIGURED counter bug:
     * when seo_enabled=false, the command must increment $summaryCounts[UNCONFIGURED]
     * by 2 (one per source). A disabled tenant that is the only tenant present must
     * produce "Summary: 0 HEALTHY · 0 STALE · 2 UNCONFIGURED · 0 NO_DATA · 0 ERROR".
     */
    public function test_summary_banner_reflects_unconfigured_count(): void
    {
        Tenant::create(['id' => 'disabled', 'name' => 'Disabled']);
        $tenant = Tenant::find('disabled');
        $tenant->seo_enabled = false;
        $tenant->save();

        Artisan::call('pw2d:seo:status', ['tenant' => 'disabled']);
        $output = Artisan::output();

        $this->assertStringContainsString(
            'Summary: 0 HEALTHY · 0 STALE · 2 UNCONFIGURED · 0 NO_DATA · 0 ERROR',
            $output,
            'A single disabled tenant must contribute exactly 2 UNCONFIGURED entries (one per source)',
        );
    }

    /**
     * Test 10 (Task C, extended): A mixed scenario (1 HEALTHY GSC + 1 STALE GA4)
     * must produce the correct summary counts and exit 1.
     *
     * Seeds one tenant where GSC is fresh (2d ago) but GA4 is stale (10d ago).
     * Expected summary: 1 HEALTHY · 1 STALE · 0 UNCONFIGURED · 0 NO_DATA · 0 ERROR.
     */
    public function test_summary_banner_reflects_healthy_and_stale_counts(): void
    {
        $this->createEnabledTenant('acme');

        $this->insertMetric('acme', 'gsc', daysAgo: 2);  // within GSC 5d threshold → HEALTHY
        $this->insertMetric('acme', 'ga4', daysAgo: 10); // exceeds GA4 2d threshold → STALE

        Artisan::call('pw2d:seo:status', ['tenant' => 'acme']);
        $output = Artisan::output();

        $this->assertStringContainsString(
            'Summary: 1 HEALTHY · 1 STALE · 0 UNCONFIGURED · 0 NO_DATA · 0 ERROR',
            $output,
            'GSC (fresh) must be HEALTHY, GA4 (10d old) must be STALE',
        );

        // Exit code must be 1 because at least one configured source is STALE.
        $this->assertSame(1, Artisan::call('pw2d:seo:status', ['tenant' => 'acme']));
    }

    /**
     * Test 11 (Task E): Inverse per-source independence —
     * healthy GA4 + unconfigured GSC (gsc_site_url is empty) must produce
     * GA4=HEALTHY and GSC=UNCONFIGURED on the same tenant.
     *
     * Exit code is 0 because UNCONFIGURED is not an error (spec §5.4).
     */
    public function test_per_source_independence_healthy_ga4_unconfigured_gsc(): void
    {
        Tenant::create(['id' => 'ga4only', 'name' => 'GA4 Only']);
        $tenant = Tenant::find('ga4only');
        $tenant->seo_enabled     = true;
        $tenant->gsc_site_url    = null;               // GSC not configured
        $tenant->ga4_property_id = 'properties/99999'; // GA4 configured
        $tenant->save();

        // Fresh GA4 data (1d ago — within the 2d threshold).
        $this->insertMetric('ga4only', 'ga4', daysAgo: 1);
        // No GSC rows and no gsc_site_url.

        $this->artisan('pw2d:seo:status', ['tenant' => 'ga4only'])
            ->assertExitCode(0)
            ->expectsOutputToContain('HEALTHY')
            ->expectsOutputToContain('UNCONFIGURED');

        // Verify the output places HEALTHY on the GA4 row and UNCONFIGURED on
        // the GSC row. The table lists GSC first, then GA4, so we check that
        // UNCONFIGURED appears before HEALTHY in the output.
        Artisan::call('pw2d:seo:status', ['tenant' => 'ga4only']);
        $output = Artisan::output();

        $posUnconfigured = strpos($output, 'UNCONFIGURED');
        $posHealthy      = strpos($output, 'HEALTHY');

        $this->assertNotFalse($posUnconfigured, 'Output must contain UNCONFIGURED');
        $this->assertNotFalse($posHealthy, 'Output must contain HEALTHY');
        $this->assertLessThan(
            $posHealthy,
            $posUnconfigured,
            'GSC (row 1) must be UNCONFIGURED and appear before GA4 (row 2) HEALTHY',
        );
    }
}
