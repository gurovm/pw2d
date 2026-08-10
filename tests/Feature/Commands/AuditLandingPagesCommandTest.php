<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Spec 030 §B4/B7 — pw2d:landing-pages:audit: exit codes + --backfill-snapshots.
 *
 * Tests run on central domain; tenancy is initialized manually for seeding
 * then ended before Artisan::call so the command initializes tenancy itself
 * per tenant — matches GenerateLandingPageCommandTest's established pattern.
 */
class AuditLandingPagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTenant(string $id): Tenant
    {
        Tenant::create(['id' => $id, 'name' => $id]);
        return Tenant::find($id);
    }

    private function makeStalePage(Tenant $tenant, string $status): LandingPage
    {
        tenancy()->initialize($tenant);

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => true]); // ineligible -> stale

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'status'      => $status,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 100],
            ],
        ]);

        tenancy()->end();

        return $page;
    }

    /** @test */
    public function it_exits_failure_when_a_published_page_is_stale(): void
    {
        $tenant = $this->makeTenant('audit-cmd-fail-tenant');
        $this->makeStalePage($tenant, 'published');

        $exitCode = Artisan::call('pw2d:landing-pages:audit', ['tenant' => 'audit-cmd-fail-tenant']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    /** @test */
    public function it_exits_success_when_only_a_draft_page_is_stale(): void
    {
        $tenant = $this->makeTenant('audit-cmd-draft-tenant');
        $this->makeStalePage($tenant, 'draft');

        $exitCode = Artisan::call('pw2d:landing-pages:audit', ['tenant' => 'audit-cmd-draft-tenant']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /** @test */
    public function it_stamps_stale_reasons_and_freshness_checked_at_on_every_page(): void
    {
        $tenant = $this->makeTenant('audit-cmd-stamp-tenant');
        $stale  = $this->makeStalePage($tenant, 'published');

        Artisan::call('pw2d:landing-pages:audit', ['tenant' => 'audit-cmd-stamp-tenant']);

        tenancy()->initialize($tenant);
        $fresh = $stale->fresh();
        $this->assertNotEmpty($fresh->stale_reasons);
        $this->assertNotNull($fresh->freshness_checked_at);
        tenancy()->end();
    }

    /** @test */
    public function omitting_the_tenant_argument_audits_every_tenant(): void
    {
        $tenantA = $this->makeTenant('audit-cmd-all-a');
        $tenantB = $this->makeTenant('audit-cmd-all-b');
        $pageA   = $this->makeStalePage($tenantA, 'published');
        $pageB   = $this->makeStalePage($tenantB, 'published');

        $exitCode = Artisan::call('pw2d:landing-pages:audit');

        $this->assertSame(Command::FAILURE, $exitCode);

        tenancy()->initialize($tenantA);
        $this->assertNotEmpty($pageA->fresh()->stale_reasons);
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $this->assertNotEmpty($pageB->fresh()->stale_reasons);
        tenancy()->end();
    }

    /** @test */
    public function unknown_tenant_argument_fails(): void
    {
        $exitCode = Artisan::call('pw2d:landing-pages:audit', ['tenant' => 'does-not-exist']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    // =========================================================================
    // --backfill-snapshots
    // =========================================================================

    /** @test */
    public function backfill_snapshots_stamps_missing_snapshots_from_current_prices(): void
    {
        $tenant = $this->makeTenant('audit-cmd-backfill-tenant');

        tenancy()->initialize($tenant);
        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'url'           => 'https://example.com/backfill',
            'raw_title'     => $product->name,
            'scraped_price' => 100,
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B'],
            ],
        ]);
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:landing-pages:audit', [
            'tenant'                 => 'audit-cmd-backfill-tenant',
            '--backfill-snapshots'   => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Backfilled est_price_snapshot on 1 pick(s)', Artisan::output());

        tenancy()->initialize($tenant);
        $fresh = $page->fresh();
        $this->assertSame(100, $fresh->picks[0]['est_price_snapshot']);
        tenancy()->end();
    }

    /** @test */
    public function backfill_snapshots_is_idempotent(): void
    {
        $tenant = $this->makeTenant('audit-cmd-backfill-idempotent-tenant');

        tenancy()->initialize($tenant);
        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'url'           => 'https://example.com/backfill-idempotent',
            'raw_title'     => $product->name,
            'scraped_price' => 100,
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 50],
            ],
        ]);
        tenancy()->end();

        Artisan::call('pw2d:landing-pages:audit', [
            'tenant'               => 'audit-cmd-backfill-idempotent-tenant',
            '--backfill-snapshots' => true,
        ]);

        $this->assertStringContainsString('Backfilled est_price_snapshot on 0 pick(s)', Artisan::output());

        tenancy()->initialize($tenant);
        $this->assertSame(50, $page->fresh()->picks[0]['est_price_snapshot'], 'An existing snapshot must never be overwritten');
        tenancy()->end();
    }
}
