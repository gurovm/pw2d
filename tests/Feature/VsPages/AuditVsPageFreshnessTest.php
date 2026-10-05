<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Actions\AuditVsPageFreshness;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\VsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditVsPageFreshnessTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private Category $category;
    private Product $a;
    private Product $b;
    private VsPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTenant('audit-vs-tenant');
        $this->category = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
        $this->a = $this->makeLiveProduct($this->category, 'Alpha 1', 1000);
        $this->b = $this->makeLiveProduct($this->category, 'Beta 2', 1100);
        $this->page = VsPage::factory()->published()->create([
            'category_id' => $this->category->id, 'product_a_id' => $this->a->id, 'product_b_id' => $this->b->id,
            'price_snapshot_a' => 1000, 'price_snapshot_b' => 1100,
        ]);
    }

    protected function tearDown(): void
    {
        $this->endTenancy();
        parent::tearDown();
    }

    /** @test */
    public function a_fresh_page_has_no_reasons_and_is_stamped(): void
    {
        $this->assertSame([], (new AuditVsPageFreshness())->handle($this->page));

        $this->page->refresh();
        $this->assertSame([], $this->page->stale_reasons);
        $this->assertNotNull($this->page->freshness_checked_at);
    }

    /** @test */
    public function price_drift_beyond_the_landing_page_threshold_is_flagged(): void
    {
        ProductOffer::where('product_id', $this->a->id)->update(['scraped_price' => 1200]); // +20% > 15%

        $this->assertSame(['price_drift'], (new AuditVsPageFreshness())->handle($this->page));
        $this->assertSame(['price_drift'], $this->page->fresh()->stale_reasons);
    }

    /** @test */
    public function a_price_move_within_the_threshold_is_not_drift(): void
    {
        ProductOffer::where('product_id', $this->b->id)->update(['scraped_price' => 1200]); // +9%

        $this->assertSame([], (new AuditVsPageFreshness())->handle($this->page));
    }

    /** @test */
    public function a_hidden_product_is_pick_ineligible(): void
    {
        $this->b->update(['is_ignored' => true]);

        $this->assertSame(['pick_ineligible'], (new AuditVsPageFreshness())->handle($this->page));
    }

    /** @test */
    public function a_product_without_an_eligible_offer_is_pick_ineligible(): void
    {
        ProductOffer::where('product_id', $this->a->id)->update(['listing_flags' => ['unavailable']]);

        $reasons = (new AuditVsPageFreshness())->handle($this->page);

        $this->assertContains('pick_ineligible', $reasons);
    }

    /** @test */
    public function a_deleted_product_cascades_the_page_away(): void
    {
        // vs_pages.product_*_id cascade on delete; with SQLite FK enforcement off the row
        // may linger, in which case the audit must flag it rather than crash.
        $this->b->offers()->delete();
        $this->b->delete();

        $page = VsPage::find($this->page->id);

        if ($page !== null) {
            $this->assertContains('pick_ineligible', (new AuditVsPageFreshness())->handle($page));
        } else {
            $this->assertNull($page);
        }
    }

    /** @test */
    public function the_nightly_command_audits_vs_pages_and_fails_on_a_stale_published_one(): void
    {
        ProductOffer::where('product_id', $this->a->id)->update(['scraped_price' => 1500]);
        $this->endTenancy();

        $code = \Illuminate\Support\Facades\Artisan::call('pw2d:landing-pages:audit', ['tenant' => 'audit-vs-tenant']);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('vs/' . $this->page->slug, $output);
        $this->assertStringContainsString('price_drift', $output);
    }

    /** @test */
    public function the_nightly_command_passes_when_vs_pages_are_fresh(): void
    {
        $this->endTenancy();

        $this->artisan('pw2d:landing-pages:audit', ['tenant' => 'audit-vs-tenant'])
            ->expectsOutputToContain('FRESH')
            ->assertExitCode(0);
    }
}
