<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\AuditLandingPageFreshness;
use App\Actions\SelectLandingPagePicks;
use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Spec 030 §B2/B7 — AuditLandingPageFreshness: the reasons contract.
 *
 * NOTE on isolation: `pick_ineligible`'s is_ignored/detached/deleted sub-triggers
 * necessarily cascade into `selection_drift` (SelectLandingPagePicks applies the
 * SAME is_ignored/category filters) and `render_short` (LandingPageController's
 * render filter is a strict subset of the select filter) — that overlap is
 * inherent to the domain, not a test gap. Where a sub-trigger truly can be
 * isolated (the offer `condition`/`high_price` columns, which SelectLandingPagePicks
 * partially or fully ignores; price drift; a pure selection swap), the test
 * asserts the EXACT reasons array.
 */
class AuditLandingPageFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-audit-tenant', 'name' => 'LP Audit Tenant']);
        $this->tenant = Tenant::find('lp-audit-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a leaf category with $count fully-eligible products (each with a
     * distinct feature score, image, and a $100 offer), then a LandingPage whose
     * `picks` are EXACTLY what SelectLandingPagePicks currently returns for it
     * (roles + est_price_snapshot included) — i.e. a page that starts genuinely
     * fresh.
     *
     * @return array{0: Category, 1: LandingPage, 2: Collection<int, Product>}
     */
    private function makeFreshCategoryAndPage(string $slug, int $count = 5): array
    {
        $category = Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $products = collect();

        foreach (range(1, $count) as $i) {
            $product = Product::factory()->create([
                'category_id'   => $category->id,
                'slug'          => "{$slug}-{$i}",
                'ai_summary'    => "Summary {$i}",
                'is_ignored'    => false,
                'status'        => null,
                'amazon_rating' => null,
                'price_tier'    => 2,
            ]);

            ProductOffer::create([
                'product_id'    => $product->id,
                'url'           => "https://example.com/{$slug}-{$i}",
                'raw_title'     => $product->name,
                'image_url'     => "https://images.example.com/{$slug}-{$i}.jpg",
                'scraped_price' => 100,
            ]);

            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value'  => 50 + $i,
            ]);

            $products->push($product->fresh());
        }

        $selected = (new SelectLandingPagePicks())->execute($category);
        $byId     = $products->keyBy('id');

        $picks = collect($selected)->map(fn (array $pick) => [
            'product_id'         => $pick['product_id'],
            'role'               => $pick['role'],
            'headline'           => 'Headline',
            'body'               => 'Body',
            'est_price_snapshot' => $byId[$pick['product_id']]->estimated_price,
        ])->values()->all();

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => "best-{$slug}",
            'status'      => 'published',
            'picks'       => $picks,
        ]);

        return [$category, $page, $products];
    }

    /** Extra eligible product beyond the base set, for eligibility-preserving mutations. */
    private function makeExtraEligibleProduct(Category $category, Feature $feature, string $slug, float $rawValue = 50): Product
    {
        $product = Product::factory()->create([
            'category_id'   => $category->id,
            'slug'          => $slug,
            'ai_summary'    => 'Summary extra',
            'is_ignored'    => false,
            'status'        => null,
            'amazon_rating' => null,
            'price_tier'    => 2,
        ]);

        ProductOffer::create([
            'product_id'    => $product->id,
            'url'           => "https://example.com/{$slug}",
            'raw_title'     => $product->name,
            'image_url'     => "https://images.example.com/{$slug}.jpg",
            'scraped_price' => 100,
        ]);

        ProductFeatureValue::factory()->create([
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'raw_value'  => $rawValue,
        ]);

        return $product->fresh();
    }

    // =========================================================================
    // Fresh page
    // =========================================================================

    /** @test */
    public function a_genuinely_fresh_page_stores_an_empty_array_and_stamps_checked_at(): void
    {
        [, $page] = $this->makeFreshCategoryAndPage('lp-audit-fresh');

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertSame([], $reasons);

        $fresh = $page->fresh();
        $this->assertSame([], $fresh->stale_reasons);
        $this->assertNotNull($fresh->freshness_checked_at);
    }

    // =========================================================================
    // pick_ineligible
    // =========================================================================

    /** @test */
    public function pick_ineligible_fires_in_isolation_when_the_best_offer_has_a_negative_condition(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-condition');

        // SelectLandingPagePicks does NOT read the `condition` column (only text
        // markers + high_price), so this does not shift selection/render.
        ProductOffer::where('product_id', $products->first()->id)->update(['condition' => 'renewed']);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertSame(['pick_ineligible'], $reasons);
    }

    /** @test */
    public function pick_ineligible_fires_in_isolation_when_the_best_offer_is_flagged_high_price(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-highprice', 7);

        // 7 picks = MAX_PICKS already reached, so flagging one high_price simply
        // drops it from re-selection (7 -> 6 eligible, still >= MIN_PICKS) without
        // pulling in an unrelated 8th candidate — keeps this isolated to the
        // flagged product's own exclusion.
        ProductOffer::where('product_id', $products->first()->id)->update(['listing_flags' => ['high_price']]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('pick_ineligible', $reasons);
        $this->assertContains('selection_drift', $reasons, 'high_price also excludes the product from re-selection');
    }

    /** @test */
    public function pick_ineligible_fires_when_the_best_offer_is_flagged_unavailable(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-unavailable', 7);

        // Same isolation trick as the high_price test above: 7 = MAX_PICKS, so the
        // flagged product simply drops from re-selection without pulling in an 8th.
        // `unavailable` is pick-excluding exactly like high_price (Spec 029,
        // ListingHealth::PICK_EXCLUDING_FLAGS).
        ProductOffer::where('product_id', $products->first()->id)->update(['listing_flags' => ['unavailable']]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('pick_ineligible', $reasons);
        $this->assertContains('selection_drift', $reasons, 'unavailable also excludes the product from re-selection');
    }

    /** @test */
    public function pick_ineligible_cascades_with_selection_drift_and_render_short_when_a_pick_is_ignored(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-ignored');

        $products->first()->update(['is_ignored' => true]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('pick_ineligible', $reasons);
        $this->assertContains('selection_drift', $reasons);
        $this->assertContains('render_short', $reasons);
    }

    /** @test */
    public function pick_ineligible_cascades_when_a_pick_is_detached_from_its_category(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-detached');

        $products->first()->update(['category_id' => null]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('pick_ineligible', $reasons);
        $this->assertContains('render_short', $reasons);
    }

    /** @test */
    public function pick_ineligible_cascades_when_a_pick_moves_to_a_different_category(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-moved');
        $otherCategory = Category::factory()->create(['slug' => 'lp-audit-moved-elsewhere']);

        $products->first()->update(['category_id' => $otherCategory->id]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('pick_ineligible', $reasons);
    }

    /** @test */
    public function pick_ineligible_cascades_when_a_pick_is_deleted(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-deleted');

        $products->first()->delete();

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('pick_ineligible', $reasons);
        $this->assertContains('render_short', $reasons);
    }

    // =========================================================================
    // selection_drift
    // =========================================================================

    /** @test */
    public function selection_drift_fires_in_isolation_when_a_new_candidate_would_change_the_pick_set(): void
    {
        [$category, $page] = $this->makeFreshCategoryAndPage('lp-audit-drift', 5);
        $feature = Feature::where('category_id', $category->id)->firstOrFail();

        // A 6th eligible product: with only 6 total (< MAX_PICKS 7), the fill
        // loop takes ALL of them, so the fresh id SET differs from the 5 stored.
        $this->makeExtraEligibleProduct($category, $feature, 'lp-audit-drift-extra');

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertSame(['selection_drift'], $reasons);
    }

    /** @test */
    public function selection_drift_fires_when_the_category_drops_below_the_minimum_pick_count(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-toolow', 5);

        // Drop to 2 eligible products — SelectLandingPagePicks throws (< MIN_PICKS 5).
        Product::whereIn('id', $products->take(3)->pluck('id'))->update(['is_ignored' => true]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('selection_drift', $reasons);
    }

    // =========================================================================
    // price_drift
    // =========================================================================

    /** @test */
    public function price_drift_fires_in_isolation_when_current_price_deviates_more_than_fifteen_percent(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-pricedrift');

        $pick = $products->first();
        // Snapshot was $100 (rounds to $100); bump to $140 -> 40% deviation.
        ProductOffer::where('product_id', $pick->id)->update(['scraped_price' => 140]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertSame(['price_drift'], $reasons);
    }

    /** @test */
    public function price_drift_does_not_fire_at_or_under_the_fifteen_percent_threshold(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-pricestable');

        $pick = $products->first();
        // $100 -> $110 = 10% deviation, under the 15% threshold.
        ProductOffer::where('product_id', $pick->id)->update(['scraped_price' => 110]);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function price_drift_is_skipped_for_picks_without_a_stored_snapshot(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-nosnapshot');

        // Simulate a pre-Spec-030 page: strip the snapshot from every pick.
        $picks = collect($page->picks)->map(function (array $pick) {
            unset($pick['est_price_snapshot']);
            return $pick;
        })->all();
        $page->update(['picks' => $picks]);

        $pick = $products->first();
        ProductOffer::where('product_id', $pick->id)->update(['scraped_price' => 500]);

        $reasons = (new AuditLandingPageFreshness())->execute($page->fresh());

        $this->assertSame([], $reasons, 'A pick with no snapshot must be skipped for price_drift, not treated as drifted');
    }

    // =========================================================================
    // render_short
    // =========================================================================

    /** @test */
    public function render_short_fires_distinctly_from_pick_ineligible_when_a_pick_goes_pending(): void
    {
        [, $page, $products] = $this->makeFreshCategoryAndPage('lp-audit-rendershort');

        // `status` is deliberately NOT part of pick_ineligible's contract (spec §"Staleness
        // reasons" only lists deleted/detached/is_ignored/condition-or-high_price) — this
        // proves render_short catches what pick_ineligible's narrower list does not.
        $products->first()->update(['status' => 'pending_ai']);

        $reasons = (new AuditLandingPageFreshness())->execute($page);

        $this->assertContains('render_short', $reasons);
        $this->assertNotContains('pick_ineligible', $reasons, 'status is not one of pick_ineligible\'s own criteria');
    }
}
