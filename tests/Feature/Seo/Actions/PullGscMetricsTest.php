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
 * live API calls are made. The fake returns rows from the sample fixture.
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

        // Bind a fake service that returns fixture rows — no network access.
        app()->bind(GoogleSearchConsoleService::class, function () {
            return new class extends GoogleSearchConsoleService {
                public function __construct()
                {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetrics(CarbonImmutable $date): Collection
                {
                    return collect([
                        ['url' => 'https://acme.com/', 'impressions' => 1200, 'clicks' => 42, 'ctr' => 0.035, 'position' => 4.2, 'top_query' => null],
                        ['url' => 'https://acme.com/compare/widgets', 'impressions' => 800, 'clicks' => 25, 'ctr' => 0.031, 'position' => 7.5, 'top_query' => null],
                        ['url' => 'https://acme.com/product/widget-pro', 'impressions' => 400, 'clicks' => 10, 'ctr' => 0.025, 'position' => 9.0, 'top_query' => null],
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
        $result = (new PullGscMetrics)->execute($this->tenant, $date);

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
        (new PullGscMetrics)->execute($this->tenant, $date);

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

        (new PullGscMetrics)->execute($this->tenant, $date);
        (new PullGscMetrics)->execute($this->tenant, $date);

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
        $result = (new PullGscMetrics)->execute($this->tenant, $date);

        $this->assertSame(0, $result->upserted);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('gsc_site_url', $result->errors[0]);
    }

    public function test_rows_are_scoped_to_correct_tenant(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');
        (new PullGscMetrics)->execute($this->tenant, $date);

        // Rows from another tenant should not bleed in.
        $rows = \Illuminate\Support\Facades\DB::table('seo_metrics')
            ->where('tenant_id', 'other-tenant')
            ->count();

        $this->assertSame(0, $rows);
    }
}
