<?php

declare(strict_types=1);

namespace Tests\Feature\LandingPage;

use App\Actions\SelectLandingPagePicks;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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
            'product_id'    => $product->id,
            'store_id'      => null,
            'url'           => "https://example.com/{$slug}",
            'raw_title'     => $product->name,
            'image_url'     => "https://images.example.com/{$slug}.jpg",
            // A pick-eligible fixture needs a real, buyable offer (2026-08-12 fix:
            // eligibility now requires >=1 priced, unflagged offer) — a null price
            // is exactly the prod-incident condition, not a baseline default.
            'scraped_price' => 100,
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

        // Both rows are pinned to the SAME brand deliberately: in reality these
        // are two un-merged rows of the identical physical keyboard, so they
        // share one brand. (ProductFactory's default hands every product a
        // fresh, unrelated Brand unless overridden — leaving that default here
        // would make modelKey() key them to two different brands and this
        // fixture would no longer represent the real-world scenario it's
        // named for.)
        $keychron = Brand::factory()->create(['name' => 'Keychron']);

        $winner = $this->makeEligibleProduct($category, 'lp-picks-dup-winner', [
            'name' => 'Keychron Q6 Max Black', 'brand_id' => $keychron->id, 'price_tier' => 2,
        ]);
        $this->setScore($winner, $feature, 99);

        // Same physical keyboard under a separate, un-merged Product row (F29 root cause) —
        // must never be picked once its near-duplicate is already selected.
        $duplicate = $this->makeEligibleProduct($category, 'lp-picks-dup-variant', [
            'name' => 'Keychron Q6 Max - Black', 'brand_id' => $keychron->id, 'price_tier' => 2,
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

    // =========================================================================
    // B2 / M3 (2026-08-16 audit): condition-column exclusion — the offer's
    // DOM-verified `condition` is checked even when the title/ai_summary are
    // clean (extension v1.4 non-title condition sources: schema.org
    // itemCondition, meta tag, badge, Amazon's "#bylineInfo" Renewed byline).
    // =========================================================================

    /** @test */
    public function it_excludes_a_product_whose_only_priced_offer_has_a_negative_condition_even_with_a_clean_title(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-negative-condition']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Would otherwise win "Best Overall" by a wide margin. Its raw_title AND
        // ai_summary are perfectly clean (hasConditionMarker() would miss it) —
        // only the DOM-verified `condition` column carries the signal.
        $renewed = $this->makeEligibleProduct($category, 'lp-picks-negative-condition-renewed');
        $this->setScore($renewed, $feature, 99);
        ProductOffer::where('product_id', $renewed->id)->update(['condition' => 'renewed']);

        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-negative-condition-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertNotContains($renewed->id, $pickedIds, 'A product whose only priced offer has a negative condition must be excluded, even with a clean title/summary');
        $this->assertCount(5, $pickedIds, 'The 5 clean products fill the picks instead');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);
    }

    /**
     * B2's core regression: every returned pick must resolve to a non-null
     * best_offer — a pick_ineligible/no-CTA card must never be selectable in
     * the first place, regardless of WHICH exclusion rule is responsible.
     */
    /** @test */
    public function every_returned_pick_has_a_non_null_best_offer(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-non-null-best-offer']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // A multi-store product where the CHEAPEST/best-scoring offer is clean but
        // a sibling offer is renewed — irrelevant to eligibility (only needs ONE
        // eligible offer), included to prove the assertion is meaningful.
        $multiStore = $this->makeEligibleProduct($category, 'lp-picks-non-null-best-offer-multi');
        $this->setScore($multiStore, $feature, 80);
        ProductOffer::create([
            'product_id'    => $multiStore->id,
            'store_id'      => null,
            'url'           => 'https://example.com/lp-picks-non-null-best-offer-multi-alt',
            'raw_title'     => $multiStore->name,
            'scraped_price' => 40,
            'condition'     => 'refurbished',
        ]);

        foreach (range(1, 4) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-non-null-best-offer-{$i}");
            $this->setScore($p, $feature, 50 + $i);
        }

        $products = \App\Models\Product::whereIn('id', collect((new SelectLandingPagePicks())->execute($category))->pluck('product_id'))
            ->with(['offers.store'])
            ->get();

        foreach ($products as $product) {
            $this->assertNotNull(
                $product->best_offer,
                "pick product #{$product->id} ({$product->name}) must have a non-null best_offer"
            );
        }
    }

    // =========================================================================
    // Null-price + flagged blind spot (prod incident, 2026-08-12 fix)
    // =========================================================================

    /** @test */
    public function it_excludes_a_product_whose_only_offer_is_unavailable_flagged_with_a_null_price(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-null-price-unavailable']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Regression for the live prod incident: a category rescan delisted this
        // product's only offer (unavailable flag + scraped_price cleared to NULL).
        // Product::bestOffer excludes null-price offers, so the OLD flag check
        // (best_offer only) had nothing to inspect and let this through — it was
        // picked and ranked #1 "Best Overall" on a live page with nothing to buy.
        $delisted = $this->makeEligibleProduct($category, 'lp-picks-null-price-unavailable-delisted');
        $this->setScore($delisted, $feature, 99);
        ProductOffer::where('product_id', $delisted->id)->update([
            'scraped_price' => null,
            'listing_flags' => ['unavailable'],
        ]);

        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-null-price-unavailable-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertNotContains($delisted->id, $pickedIds, 'A product whose only offer is null-price + unavailable-flagged must be excluded');
        $this->assertCount(5, $pickedIds, 'The 5 clean products fill the picks instead');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);
    }

    /** @test */
    public function a_product_is_selectable_when_only_one_of_its_two_offers_is_eligible(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-multi-offer-partial']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Two offers: the cheaper one is flagged + priceless (delisted at that
        // store), but a second store still carries a clean, priced offer. Multi-
        // offer products must not be over-excluded just because ONE of their
        // offers went bad — only a product with ZERO eligible offers is excluded.
        $product = $this->makeEligibleProduct($category, 'lp-picks-multi-offer-partial-winner');
        $this->setScore($product, $feature, 99);
        ProductOffer::where('product_id', $product->id)->update([
            'scraped_price' => null,
            'listing_flags' => ['unavailable'],
        ]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => null,
            'url'           => 'https://example.com/lp-picks-multi-offer-partial-winner-alt',
            'raw_title'     => $product->name,
            'scraped_price' => 45,
            'listing_flags' => [],
        ]);

        foreach (range(1, 4) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-multi-offer-partial-{$i}");
            $this->setScore($p, $feature, 10 + $i);
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($product->id, $pickedIds, 'A product with at least one clean, priced offer must remain selectable');
    }

    /** @test */
    public function it_excludes_a_product_whose_offers_are_all_null_priced(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-all-null-price']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Neither offer carries a pick-excluding flag, but neither is priced either —
        // no phantom "Price N/A" picks. A product needs at least one PRICED, clean
        // offer to be pick-eligible, not merely an unflagged one.
        $noPrice = $this->makeEligibleProduct($category, 'lp-picks-all-null-price-none');
        $this->setScore($noPrice, $feature, 99);
        ProductOffer::where('product_id', $noPrice->id)->update(['scraped_price' => null]);
        ProductOffer::create([
            'product_id'    => $noPrice->id,
            'store_id'      => null,
            'url'           => 'https://example.com/lp-picks-all-null-price-none-alt',
            'raw_title'     => $noPrice->name,
            'scraped_price' => null,
            'listing_flags' => [],
        ]);

        $eligibleIds = [];
        foreach (range(1, 5) as $i) {
            $p = $this->makeEligibleProduct($category, "lp-picks-all-null-price-clean-{$i}");
            $this->setScore($p, $feature, 50 + $i);
            $eligibleIds[] = $p->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertNotContains($noPrice->id, $pickedIds, 'A product with zero priced offers must be excluded, even with no flags');
        $this->assertCount(5, $pickedIds, 'The 5 clean products fill the picks instead');
        sort($eligibleIds);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($eligibleIds, $sortedPicked);
    }

    // =========================================================================
    // Spec 034 §1 — model-identity variants (real names from the spec's
    // 2026-08-20 super-auto Tier-3 regression, verified by hand).
    // =========================================================================

    /** @test */
    public function it_rejects_the_z10_aluminum_white_as_a_variant_of_the_already_picked_z10_gen_1(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-model-key-z10']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $jura = Brand::factory()->create(['name' => 'Jura']);

        // The regression this spec exists for: one machine held BOTH "overall"
        // and "premium" on the live page because 43.3% similar_text was far
        // below the old 85% duplicate threshold (Spec 034 "Why").
        $winner = $this->makeEligibleProduct($category, 'lp-picks-z10-gen-1', [
            'name'       => 'JURA Z10 Super-Automatic Espresso Machine - Gen 1',
            'brand_id'   => $jura->id,
            'price_tier' => 2,
        ]);
        $this->setScore($winner, $feature, 99);

        $variant = $this->makeEligibleProduct($category, 'lp-picks-z10-aluminum-white', [
            'name'       => 'Jura Z10 Aluminum White',
            'brand_id'   => $jura->id,
            'price_tier' => 2,
        ]);
        $this->setScore($variant, $feature, 95);

        $fillerIds = [];
        foreach (range(1, 5) as $i) {
            $filler = $this->makeEligibleProduct($category, "lp-picks-z10-filler-{$i}", ['price_tier' => 2]);
            $this->setScore($filler, $feature, 50 + $i);
            $fillerIds[] = $filler->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($winner->id, $pickedIds, 'JURA Z10 Super-Automatic Espresso Machine - Gen 1 must win Best Overall');
        $this->assertNotContains($variant->id, $pickedIds, 'Jura Z10 Aluminum White is the same machine (model key "z10") and must be rejected as a variant, not merely scored as a near-duplicate name');
        foreach ($fillerIds as $fillerId) {
            $this->assertContains($fillerId, $pickedIds, 'Distinct fillers must still fill the slot the variant was skipped for');
        }
        $this->assertCount(6, $pickedIds, 'winner + 5 distinct fillers; the variant is skipped entirely');
    }

    /** @test */
    public function it_keeps_x10_dark_inox_and_j10_twin_as_distinct_picks_despite_82_percent_name_similarity(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-model-key-x10-j10']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $jura = Brand::factory()->create(['name' => 'Jura']);

        // 82.1% similar_text (Spec 034 "Why") — the guard the metric-inverted
        // 85% threshold nearly false-positived on. Two genuinely different
        // machines; the model key ("x10" vs "j10") must keep both selectable.
        $x10 = $this->makeEligibleProduct($category, 'lp-picks-x10-dark-inox', [
            'name'       => 'JURA X10 Dark Inox',
            'brand_id'   => $jura->id,
            'price_tier' => 2,
        ]);
        $this->setScore($x10, $feature, 99);

        $j10 = $this->makeEligibleProduct($category, 'lp-picks-j10-twin', [
            'name'       => 'JURA J10 Twin',
            'brand_id'   => $jura->id,
            'price_tier' => 2,
        ]);
        $this->setScore($j10, $feature, 95);

        foreach (range(1, 5) as $i) {
            $filler = $this->makeEligibleProduct($category, "lp-picks-x10-j10-filler-{$i}", ['price_tier' => 2]);
            $this->setScore($filler, $feature, 50 + $i);
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($x10->id, $pickedIds, 'X10 Dark Inox must be picked');
        $this->assertContains($j10->id, $pickedIds, 'J10 Twin is a different machine and must remain selectable despite scoring 82.1% similar to X10 Dark Inox');
    }

    /**
     * Twin of the X10/J10 test above, guarding the OPPOSITE failure direction:
     * X10/J10 proves the guard doesn't over-merge BELOW the 85% similar_text
     * threshold; this proves it doesn't over-merge ABOVE it. "Philips 4400
     * Series..." vs "Philips 1200 Series..." measures 95.7% similar_text —
     * comfortably over the old threshold — yet these are two different
     * machines with different model keys ("4400" vs "1200"). The model key is
     * authoritative once both sides have one: a confirmed key difference must
     * veto the similarity check, never be overruled by it. Both products are
     * live in the real super-auto pool today (Philips 1200 = product 4161,
     * Philips 4400 = product 4160).
     */
    /** @test */
    public function it_keeps_philips_4400_and_philips_1200_as_distinct_picks_despite_95_7_percent_name_similarity(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-model-key-philips-4400-1200']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $philips = Brand::factory()->create(['name' => 'Philips']);

        $p4400 = $this->makeEligibleProduct($category, 'lp-picks-philips-4400', [
            'name'       => 'Philips 4400 Series Fully Automatic Espresso Machine',
            'brand_id'   => $philips->id,
            'price_tier' => 2,
        ]);
        $this->setScore($p4400, $feature, 99);

        $p1200 = $this->makeEligibleProduct($category, 'lp-picks-philips-1200', [
            'name'       => 'Philips 1200 Series Fully Automatic Espresso Machine',
            'brand_id'   => $philips->id,
            'price_tier' => 2,
        ]);
        $this->setScore($p1200, $feature, 95);

        foreach (range(1, 5) as $i) {
            $filler = $this->makeEligibleProduct($category, "lp-picks-philips-4400-1200-filler-{$i}", ['price_tier' => 2]);
            $this->setScore($filler, $feature, 50 + $i);
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($p4400->id, $pickedIds, 'Philips 4400 must be picked');
        $this->assertContains($p1200->id, $pickedIds, 'Philips 1200 is a different machine (model key "1200" vs "4400") and must remain selectable despite scoring 95.7% similar to Philips 4400');
    }

    /** @test */
    public function it_keeps_giga_10_and_giga_x8_as_distinct_picks(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-model-key-giga']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $jura = Brand::factory()->create(['name' => 'Jura']);

        $giga10 = $this->makeEligibleProduct($category, 'lp-picks-giga-10', [
            'name'       => 'JURA GIGA 10 Espresso Machine',
            'brand_id'   => $jura->id,
            'price_tier' => 2,
        ]);
        $this->setScore($giga10, $feature, 99);

        $gigaX8 = $this->makeEligibleProduct($category, 'lp-picks-giga-x8', [
            'name'       => 'Jura GIGA X8 Professional',
            'brand_id'   => $jura->id,
            'price_tier' => 2,
        ]);
        $this->setScore($gigaX8, $feature, 95);

        foreach (range(1, 5) as $i) {
            $filler = $this->makeEligibleProduct($category, "lp-picks-giga-filler-{$i}", ['price_tier' => 2]);
            $this->setScore($filler, $feature, 50 + $i);
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($giga10->id, $pickedIds, 'GIGA 10 must be picked (model key "giga10")');
        $this->assertContains($gigaX8->id, $pickedIds, 'GIGA X8 is a different machine (model key "gigax8") and must remain selectable');
    }

    /** @test */
    public function it_falls_back_to_the_similarity_guard_when_neither_product_has_a_model_token(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-model-key-fallback']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Neither name contains a digit anywhere -> modelKey() is null on both
        // sides -> Addendum A §2b's original normalized-name similarity guard
        // must still apply, completely unchanged.
        $winner = $this->makeEligibleProduct($category, 'lp-picks-gaggia-cadorna', [
            'name'       => 'Gaggia Cadorna Prestige',
            'price_tier' => 2,
        ]);
        $this->setScore($winner, $feature, 99);

        $duplicate = $this->makeEligibleProduct($category, 'lp-picks-gaggia-cadorna-dup', [
            'name'       => 'Gaggia Cadorna Prestige - Black',
            'price_tier' => 2,
        ]);
        $this->setScore($duplicate, $feature, 95);

        $fillerIds = [];
        foreach (range(1, 5) as $i) {
            $filler = $this->makeEligibleProduct($category, "lp-picks-gaggia-filler-{$i}", ['price_tier' => 2]);
            $this->setScore($filler, $feature, 50 + $i);
            $fillerIds[] = $filler->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertContains($winner->id, $pickedIds, 'Gaggia Cadorna Prestige must win Best Overall');
        $this->assertNotContains($duplicate->id, $pickedIds, 'The near-duplicate name must still be caught by the unchanged similarity fallback when neither product has a model token');
        foreach ($fillerIds as $fillerId) {
            $this->assertContains($fillerId, $pickedIds);
        }
    }

    // =========================================================================
    // Spec 034 §2 — brand cap (soft, keyed by brand_id)
    // =========================================================================

    /** @test */
    public function it_caps_picks_per_brand_at_three_when_alternatives_exist(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-brand-cap-alternatives']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $acme = Brand::factory()->create(['name' => 'Acme']);

        // The 5 top-scored products in the whole pool all share one brand.
        $acmeIds = [];
        foreach ([100, 95, 90, 85, 80] as $i => $raw) {
            $p = $this->makeEligibleProduct($category, "lp-picks-brand-cap-acme-{$i}", [
                'brand_id'   => $acme->id,
                'price_tier' => 2,
            ]);
            $this->setScore($p, $feature, $raw);
            $acmeIds[] = $p->id;
        }

        // 5 alternative-brand fillers (factory default: a fresh, distinct brand
        // per product), each scoring below every Acme product.
        foreach ([70, 65, 60, 55, 50] as $i => $raw) {
            $p = $this->makeEligibleProduct($category, "lp-picks-brand-cap-alt-{$i}", ['price_tier' => 2]);
            $this->setScore($p, $feature, $raw);
        }

        $picks      = (new SelectLandingPagePicks())->execute($category);
        $pickedIds  = collect($picks)->pluck('product_id')->all();
        $acmePicked = collect($pickedIds)->intersect($acmeIds);

        $this->assertCount(7, $pickedIds, 'All 10 eligible products exist; MAX_PICKS still caps the page at 7');
        $this->assertLessThanOrEqual(3, $acmePicked->count(), 'No more than MAX_PICKS_PER_BRAND (3) picks may share a brand when under-quota alternatives exist');
        $this->assertGreaterThan(0, $acmePicked->count(), 'Acme is genuinely the top-scored brand and must still contribute picks up to the cap');
    }

    /** @test */
    public function it_softly_exceeds_the_brand_cap_when_only_one_brand_is_viable_and_logs(): void
    {
        Log::spy();

        $category = Category::factory()->create(['slug' => 'lp-picks-brand-cap-monoculture']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        $onlyBrand = Brand::factory()->create(['name' => 'OnlyBrand']);

        // Exactly MIN_PICKS eligible products, ALL one brand — a category with
        // only one viable brand must still produce a page.
        $ids = [];
        foreach ([90, 85, 80, 75, 70] as $i => $raw) {
            $p = $this->makeEligibleProduct($category, "lp-picks-brand-cap-mono-{$i}", [
                'brand_id'   => $onlyBrand->id,
                'price_tier' => 2,
            ]);
            $this->setScore($p, $feature, $raw);
            $ids[] = $p->id;
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertCount(5, $pickedIds, 'The only 5 eligible products must all be picked to reach MIN_PICKS, even though all 5 share one brand');
        sort($ids);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($ids, $sortedPicked);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, $category->name) && str_contains($message, 'brand cap'))
            ->atLeast()->once();
    }

    /** @test */
    public function the_brand_cap_counts_by_brand_id_not_brand_name_string(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-picks-brand-cap-by-id']);
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        // Two DISTINCT Brand rows that happen to share a display name — the
        // cap must key off brand_id, never the name string, or these would be
        // wrongly treated as one brand and over-capped.
        $brandA = Brand::factory()->create(['name' => 'Acme']);
        $brandB = Brand::factory()->create(['name' => 'Acme']);

        $ids = [];
        foreach ([$brandA, $brandB] as $brand) {
            foreach ([90, 85, 80] as $i => $raw) {
                $p = $this->makeEligibleProduct($category, "lp-picks-brand-cap-id-{$brand->id}-{$i}", [
                    'brand_id'   => $brand->id,
                    'price_tier' => 2,
                ]);
                $this->setScore($p, $feature, $raw);
                $ids[] = $p->id;
            }
        }

        $picks     = (new SelectLandingPagePicks())->execute($category);
        $pickedIds = collect($picks)->pluck('product_id')->all();

        $this->assertCount(6, $pickedIds, 'All 6 products (3 per distinct brand_id, split across 2 brand rows sharing a name) fit within the cap and must all be picked');
        sort($ids);
        $sortedPicked = $pickedIds;
        sort($sortedPicked);
        $this->assertSame($ids, $sortedPicked);
    }
}
