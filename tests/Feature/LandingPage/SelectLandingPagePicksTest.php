<?php

declare(strict_types=1);

namespace Tests\Feature\LandingPage;

use App\Actions\SelectLandingPagePicks;
use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 027 §9.2 — SelectLandingPagePicks: deterministic pick selection.
 *
 * Products use a single Feature with `unit = null` (raw range fixed at 0-100 by
 * ProductScoringService, see app/Services/ProductScoringService.php:36-39), so
 * `raw_value` maps 1:1 to the normalized feature score — makes match_score fully
 * predictable by hand. `amazon_rating` is forced to null and `price_tier` is
 * controlled explicitly so only the feature score + price-tier score drive ranking.
 */
class SelectLandingPagePicksTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-picks-tenant', 'name' => 'LP Picks Tenant']);
        $this->tenant = Tenant::find('lp-picks-tenant');
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

    private function makeEligibleProduct(Category $category, string $slug, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'category_id'   => $category->id,
            'slug'          => $slug,
            'ai_summary'    => 'Summary for ' . $slug,
            'is_ignored'    => false,
            'status'        => null,
            'amazon_rating' => null,
            'price_tier'    => 2,
        ], $overrides));

        ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => null,
            'url'        => "https://example.com/{$slug}",
            'raw_title'  => $product->name,
            'image_url'  => "https://images.example.com/{$slug}.jpg",
        ]);

        return $product;
    }

    private function setScore(Product $product, Feature $feature, float $rawValue): void
    {
        ProductFeatureValue::factory()->create([
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'raw_value'  => $rawValue,
        ]);
    }

    // =========================================================================
    // Role assignment
    // =========================================================================

    /** @test */
    public function it_assigns_overall_budget_premium_and_preset_roles(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-roles']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // match_score = (featureScore + priceScore) / 2, priceScore: tier1=100, tier2=50, tier3=0.
        $overallWinner = $this->makeEligibleProduct($category, 'lp-picks-overall', ['price_tier' => 2]);
        $this->setScore($overallWinner, $feature, 95); // (95+50)/2 = 72.5

        $budgetWinner = $this->makeEligibleProduct($category, 'lp-picks-budget', ['price_tier' => 1]);
        $this->setScore($budgetWinner, $feature, 40); // (40+100)/2 = 70 — only tier-1 product, wins budget regardless

        $premiumWinner = $this->makeEligibleProduct($category, 'lp-picks-premium', ['price_tier' => 3]);
        $this->setScore($premiumWinner, $feature, 60); // (60+0)/2 = 30 — only tier-3 product, wins premium regardless

        $filler1 = $this->makeEligibleProduct($category, 'lp-picks-filler-1', ['price_tier' => 2]);
        $this->setScore($filler1, $feature, 30); // (30+50)/2 = 40

        $filler2 = $this->makeEligibleProduct($category, 'lp-picks-filler-2', ['price_tier' => 2]);
        $this->setScore($filler2, $feature, 20); // (20+50)/2 = 35

        $preset = Preset::factory()->create(['category_id' => $category->id, 'name' => 'Preset A', 'sort_order' => 0]);
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature->id, 'weight' => 80]);

        $picks = (new SelectLandingPagePicks())->execute($category);
        $byId  = collect($picks)->keyBy('product_id');

        $this->assertCount(5, $picks, 'Exactly 5 eligible products were created — all must be picked');
        $this->assertSame('overall', $byId[$overallWinner->id]['role']);
        $this->assertSame('budget', $byId[$budgetWinner->id]['role']);
        $this->assertSame('premium', $byId[$premiumWinner->id]['role']);
        // Preset picks skip already-picked products; among the two fillers, filler1 (raw 30)
        // out-scores filler2 (raw 20) under the preset's weighting -> filler1 wins the preset slot.
        $this->assertSame('preset:preset-a', $byId[$filler1->id]['role']);
        // The last remaining product fills the 5th slot, reusing the "overall" role (§4 fill-in rule).
        $this->assertSame('overall', $byId[$filler2->id]['role']);
    }

    // =========================================================================
    // Eligibility exclusions
    // =========================================================================

    /** @test */
    public function it_excludes_ignored_pending_detached_and_missing_data_products(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-exclusions']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // 5 valid eligible products to satisfy MIN_PICKS on their own.
        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-eligible-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $ignored = $this->makeEligibleProduct($category, 'lp-picks-ignored', ['is_ignored' => true]);
        $this->setScore($ignored, $feature, 99);

        $pending = $this->makeEligibleProduct($category, 'lp-picks-pending', ['status' => 'pending_ai']);
        $this->setScore($pending, $feature, 99);

        $detached = $this->makeEligibleProduct($category, 'lp-picks-detached', ['category_id' => null]);
        $this->setScore($detached, $feature, 99);

        // No image: no ProductOffer created at all, so image_url resolves to null.
        $noImage = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-picks-no-image',
            'ai_summary'  => 'Has a summary but no image.',
            'is_ignored'  => false,
            'status'      => null,
        ]);
        $this->setScore($noImage, $feature, 99);

        $noSummary = $this->makeEligibleProduct($category, 'lp-picks-no-summary', ['ai_summary' => null]);
        $this->setScore($noSummary, $feature, 99);

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertCount(5, $pickedIds, 'Only the 5 fully-eligible products may be picked');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);

        $this->assertNotContains($ignored->id, $pickedIds, 'is_ignored products must be excluded');
        $this->assertNotContains($pending->id, $pickedIds, 'status=pending_ai products must be excluded');
        $this->assertNotContains($detached->id, $pickedIds, 'detached (category_id null) products must be excluded');
        $this->assertNotContains($noImage->id, $pickedIds, 'products without an image must be excluded');
        $this->assertNotContains($noSummary->id, $pickedIds, 'products without ai_summary must be excluded');
    }

    // =========================================================================
    // Fill-to-N and minimum threshold
    // =========================================================================

    /** @test */
    public function it_fills_up_to_seven_picks_when_more_are_eligible(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-fill-to-seven']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        foreach (range(1, 10) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-fill-{$i}", ['price_tier' => 2]);
            $this->setScore($p, $feature, $i * 5);
        }

        $picks = (new SelectLandingPagePicks())->execute($category);

        $this->assertCount(7, $picks, 'Must cap at MAX_PICKS (7) even with 10 eligible products');
    }

    /** @test */
    public function it_throws_when_fewer_than_five_eligible_products_exist(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-too-few']);
        $feature  = Feature::factory()->create(['category_id' => $category->id]);

        foreach (range(1, 4) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-too-few-{$i}");
            $this->setScore($p, $feature, 50);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not ready for a landing page/i');

        (new SelectLandingPagePicks())->execute($category);
    }

    /** @test */
    public function it_throws_when_zero_eligible_products_exist(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-zero']);
        Feature::factory()->create(['category_id' => $category->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no eligible products/i');

        (new SelectLandingPagePicks())->execute($category);
    }

    // =========================================================================
    // Condition-marker exclusion (Addendum A §2a)
    // =========================================================================

    /** @test */
    public function it_excludes_products_with_a_condition_marker_in_an_offer_raw_title(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-condition-title']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-condition-title-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $renewed = $this->makeEligibleProduct($category, 'lp-picks-condition-title-renewed');
        $this->setScore($renewed, $feature, 99);
        // Overwrite the offer's raw_title with a condition marker; the product's own
        // name/ai_summary stay clean — the guard must still catch it via the offer.
        ProductOffer::where('product_id', $renewed->id)->update(['raw_title' => 'Logitech G915 TKL (Amazon Renewed)']);

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertCount(5, $pickedIds);
        $this->assertNotContains($renewed->id, $pickedIds, 'A product with a "Renewed" offer raw_title must be excluded');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);
    }

    /** @test */
    public function it_excludes_products_with_a_condition_marker_in_ai_summary(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-condition-summary']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-condition-summary-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
        }

        $refurbished = $this->makeEligibleProduct($category, 'lp-picks-condition-summary-refurb', [
            'ai_summary' => 'A great value pick, even if refurbished units are common on the market.',
        ]);
        $this->setScore($refurbished, $feature, 99);

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertCount(5, $pickedIds);
        $this->assertNotContains($refurbished->id, $pickedIds, 'A product with "refurbished" in ai_summary must be excluded');
    }

    /** @test */
    public function it_does_not_over_match_the_plain_word_used_inside_ai_summary_prose(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-condition-used-prose']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        foreach (range(1, 4) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-condition-used-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
        }

        // Plain-verb "used" in prose — must NOT be treated as a condition marker
        // (only ai_summary's narrower marker set is checked; "used" is excluded from it).
        $usedInProse = $this->makeEligibleProduct($category, 'lp-picks-condition-used-prose', [
            'ai_summary' => 'Designed to be used with both Mac and Windows machines.',
        ]);
        $this->setScore($usedInProse, $feature, 60);

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertCount(5, $pickedIds);
        $this->assertContains($usedInProse->id, $pickedIds, 'Plain-verb "used" in ai_summary prose must NOT trigger the condition guard');
    }

    // =========================================================================
    // Duplicate-name guard (Addendum A §2b)
    // =========================================================================

    /** @test */
    public function it_rejects_a_near_duplicate_name_of_an_already_picked_product(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-duplicate-name']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $winner = $this->makeEligibleProduct($category, 'lp-picks-dup-winner', [
            'name' => 'Keychron Q6 Max Black', 'price_tier' => 2,
        ]);
        $this->setScore($winner, $feature, 99);

        // Same physical keyboard under a separate, un-merged Product row (F29 root cause) —
        // must never be picked once its near-duplicate is already selected.
        $duplicate = $this->makeEligibleProduct($category, 'lp-picks-dup-variant', [
            'name' => 'Keychron Q6 Max - Black', 'price_tier' => 2,
        ]);
        $this->setScore($duplicate, $feature, 95);

        $fillerIds = [];
        foreach (range(1, 5) as $i) {
            $filler = $this->makeEligibleProduct($category, "lp-picks-dup-filler-{$i}", ['price_tier' => 2]);
            $this->setScore($filler, $feature, 50 + $i);
            $fillerIds[] = $filler->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($winner->id, $pickedIds, 'Best Overall winner must be picked');
        $this->assertNotContains($duplicate->id, $pickedIds, 'A near-duplicate name of an already-picked product must never be picked');
        foreach ($fillerIds as $fillerId) {
            $this->assertContains($fillerId, $pickedIds, 'Distinct fillers must still fill the slot the duplicate was skipped for');
        }
        $this->assertCount(6, $pickedIds, 'winner + 5 distinct fillers; the duplicate is skipped entirely');
    }

    // =========================================================================
    // High-price exclusion (Spec 029 amendment, 2026-08-10)
    // =========================================================================

    /** @test */
    public function it_excludes_a_product_whose_best_offer_is_flagged_high_price_and_falls_back_to_next_best(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-high-price']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Would otherwise win "Best Overall" by a wide margin, but its only (best) offer
        // carries the high_price flag — must be excluded from pick eligibility entirely.
        $flagged = $this->makeEligibleProduct($category, 'lp-picks-high-price-flagged');
        $this->setScore($flagged, $feature, 99);
        ProductOffer::where('product_id', $flagged->id)->update([
            'scraped_price' => 50,
            'listing_flags' => ['high_price'],
        ]);

        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-high-price-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertNotContains($flagged->id, $pickedIds, 'A product whose best offer is high_price-flagged must be excluded');
        $this->assertCount(5, $pickedIds, 'The 5 clean products fill the picks instead');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);
    }

    /** @test */
    public function a_high_price_flag_on_a_non_best_offer_does_not_exclude_the_product(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-high-price-non-best']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $product = $this->makeEligibleProduct($category, 'lp-picks-high-price-non-best-winner');
        $this->setScore($product, $feature, 99);
        // Best offer (lowest price, $30) is clean; a pricier second offer is flagged.
        ProductOffer::where('product_id', $product->id)->update(['scraped_price' => 30]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => null,
            'url'           => 'https://example.com/lp-picks-high-price-non-best-winner-alt',
            'raw_title'     => $product->name,
            'scraped_price' => 60,
            'listing_flags' => ['high_price'],
        ]);

        foreach (range(1, 4) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-high-price-non-best-{$i}");
            $this->setScore($p, $feature, 10 + $i);
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($product->id, $pickedIds, 'Only the BEST offer\'s flags matter — a flagged non-best offer must not exclude the product');
    }

    /** @test */
    public function it_excludes_a_product_whose_best_offer_is_flagged_unavailable(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-unavailable']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Would otherwise win "Best Overall", but its only (best) offer carries the
        // `unavailable` flag — pick-excluding exactly like high_price (Spec 029,
        // ListingHealth::PICK_EXCLUDING_FLAGS).
        $flagged = $this->makeEligibleProduct($category, 'lp-picks-unavailable-flagged');
        $this->setScore($flagged, $feature, 99);
        ProductOffer::where('product_id', $flagged->id)->update([
            'scraped_price' => 50,
            'listing_flags' => ['unavailable'],
        ]);

        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-unavailable-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertNotContains($flagged->id, $pickedIds, 'A product whose best offer is unavailable-flagged must be excluded');
        $this->assertCount(5, $pickedIds, 'The 5 clean products fill the picks instead');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);
    }
}
