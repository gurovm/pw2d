<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the Product::bestOffer accessor (P8).
 *
 * Verifies that offers with scraped_price === null are excluded before sorting,
 * so a null-priced offer can never win over a priced one.
 */
class ProductBestOfferTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a minimal product with no offers attached.
     * slug must be set explicitly — the factory does not generate one.
     */
    private function makeProduct(): Product
    {
        return Product::factory()->create([
            'slug' => 'test-product-' . uniqid(),
        ]);
    }

    /**
     * Create a Store row directly (no StoreFactory exists).
     * tenant_id is null — safe for central-domain tests.
     */
    private function makeStore(float $commissionRate = 0.0, int $priority = 0): Store
    {
        return Store::create([
            'tenant_id'       => null,
            'name'            => 'Store ' . uniqid(),
            'slug'            => 'store-' . uniqid(),
            'commission_rate' => $commissionRate,
            'priority'        => $priority,
            'is_active'       => true,
        ]);
    }

    /**
     * Attach a ProductOffer to a product for the given store and price.
     */
    private function makeOffer(Product $product, Store $store, ?float $price, ?string $condition = null, array $listingFlags = []): ProductOffer
    {
        return ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => $price,
            'raw_title'     => 'Test Product Raw Title',
            'condition'     => $condition,
            'listing_flags' => $listingFlags,
        ]);
    }

    // -------------------------------------------------------------------------

    /** @test */
    public function null_price_offer_is_not_returned_when_a_priced_offer_exists(): void
    {
        $product   = $this->makeProduct();
        $storeA    = $this->makeStore();
        $storeB    = $this->makeStore();

        $nullOffer  = $this->makeOffer($product, $storeA, null);
        $pricedOffer = $this->makeOffer($product, $storeB, 99.99);

        // Reload so the `offers` relation is fresh and includes store.
        $product->load('offers.store');

        $best = $product->best_offer;

        $this->assertNotNull($best, 'bestOffer must not be null when a priced offer exists');
        $this->assertSame($pricedOffer->id, $best->id, 'The priced offer should win over the null-price one');
        $this->assertNotSame($nullOffer->id, $best->id, 'The null-price offer must not be selected');
    }

    /** @test */
    public function all_null_prices_returns_null_best_offer(): void
    {
        $product = $this->makeProduct();
        $storeA  = $this->makeStore();
        $storeB  = $this->makeStore();

        $this->makeOffer($product, $storeA, null);
        $this->makeOffer($product, $storeB, null);

        $product->load('offers.store');

        $this->assertNull(
            $product->best_offer,
            'bestOffer must be null when every offer has a null price'
        );
    }

    /** @test */
    public function cheapest_priced_offer_is_returned_as_best(): void
    {
        $product = $this->makeProduct();
        $storeA  = $this->makeStore();
        $storeB  = $this->makeStore();
        $storeC  = $this->makeStore();

        $this->makeOffer($product, $storeA, 149.99);
        $cheapOffer = $this->makeOffer($product, $storeB, 99.99);
        $this->makeOffer($product, $storeC, 129.99);

        $product->load('offers.store');

        $best = $product->best_offer;

        $this->assertNotNull($best);
        $this->assertSame($cheapOffer->id, $best->id, 'The offer with the lowest price must be selected');
        $this->assertEquals('99.99', $best->scraped_price);
    }

    /** @test */
    public function product_with_no_offers_returns_null_best_offer(): void
    {
        $product = $this->makeProduct();
        $product->load('offers.store');

        $this->assertNull(
            $product->best_offer,
            'bestOffer must be null when the product has no offers at all'
        );
    }

    /** @test */
    public function higher_commission_rate_wins_tiebreaker_when_prices_equal(): void
    {
        $product     = $this->makeProduct();
        $lowCommStore  = $this->makeStore(commissionRate: 5.0);
        $highCommStore = $this->makeStore(commissionRate: 10.0);

        $this->makeOffer($product, $lowCommStore, 99.99);
        $highCommOffer = $this->makeOffer($product, $highCommStore, 99.99);

        $product->load('offers.store');

        $best = $product->best_offer;

        $this->assertNotNull($best);
        $this->assertSame(
            $highCommOffer->id,
            $best->id,
            'When prices are equal, the offer from the store with higher commission_rate should win'
        );
    }

    /** @test */
    public function higher_priority_wins_tiebreaker_when_price_and_commission_are_equal(): void
    {
        $product      = $this->makeProduct();
        $lowPriStore  = $this->makeStore(commissionRate: 5.0, priority: 1);
        $highPriStore = $this->makeStore(commissionRate: 5.0, priority: 10);

        $this->makeOffer($product, $lowPriStore, 49.99);
        $highPriOffer = $this->makeOffer($product, $highPriStore, 49.99);

        $product->load('offers.store');

        $best = $product->best_offer;

        $this->assertNotNull($best);
        $this->assertSame(
            $highPriOffer->id,
            $best->id,
            'When price and commission are equal, the store with higher priority should win'
        );
    }

    /** @test */
    public function mixed_null_and_priced_offers_picks_cheapest_priced(): void
    {
        // Regression guard: null offers mixed throughout the list must not interfere
        // with correct cheapest-price selection.
        $product = $this->makeProduct();
        $storeA  = $this->makeStore();
        $storeB  = $this->makeStore();
        $storeC  = $this->makeStore();

        $this->makeOffer($product, $storeA, null);
        $cheapOffer = $this->makeOffer($product, $storeB, 59.99);
        $this->makeOffer($product, $storeC, 79.99);

        $product->load('offers.store');

        $best = $product->best_offer;

        $this->assertNotNull($best);
        $this->assertSame($cheapOffer->id, $best->id);
    }

    // =========================================================================
    // Fix 3 (2026-08-15 live-QA hole) — negative-condition / pick-excluding-flag
    // offers must never win bestOffer, even when cheaper.
    // =========================================================================

    /** @test */
    public function a_cheaper_refurbished_offer_is_skipped_in_favor_of_a_pricier_clean_offer(): void
    {
        // Mirrors prod: product 3500 (De'Longhi ECAM22110SB) — a clean Amazon
        // offer at $670 and a refurbished Whole Latte Love offer at $720... but
        // here the refurbished offer is made CHEAPER to prove condition, not
        // price, decides the winner.
        $product     = $this->makeProduct();
        $amazonStore = $this->makeStore();
        $wllStore    = $this->makeStore();

        $cleanOffer = $this->makeOffer($product, $amazonStore, 670.00, condition: 'new');
        $this->makeOffer($product, $wllStore, 550.00, condition: 'refurbished');

        $product->load('offers.store');

        $best = $product->best_offer;

        $this->assertNotNull($best);
        $this->assertSame($cleanOffer->id, $best->id, 'the refurbished offer must never win, even cheaper');
        $this->assertEquals('670.00', (string) $best->scraped_price);
    }

    /**
     * @test
     * @dataProvider negativeConditionProvider
     */
    public function each_negative_condition_is_excluded_from_best_offer(string $condition): void
    {
        $product     = $this->makeProduct();
        $cleanStore  = $this->makeStore();
        $badStore    = $this->makeStore();

        $cleanOffer = $this->makeOffer($product, $cleanStore, 100.00, condition: 'new');
        $this->makeOffer($product, $badStore, 50.00, condition: $condition);

        $product->load('offers.store');

        $this->assertSame($cleanOffer->id, $product->best_offer?->id, "condition={$condition} must be excluded");
    }

    public static function negativeConditionProvider(): array
    {
        return [
            ['renewed'],
            ['refurbished'],
            ['open_box'],
            ['used'],
        ];
    }

    /**
     * @test
     * @dataProvider pickExcludingFlagProvider
     */
    public function offers_carrying_a_pick_excluding_flag_are_excluded_from_best_offer(string $flag): void
    {
        $product    = $this->makeProduct();
        $cleanStore = $this->makeStore();
        $flagStore  = $this->makeStore();

        $cleanOffer = $this->makeOffer($product, $cleanStore, 100.00, condition: 'new');
        $this->makeOffer($product, $flagStore, 50.00, condition: 'new', listingFlags: [$flag]);

        $product->load('offers.store');

        $this->assertSame($cleanOffer->id, $product->best_offer?->id, "flag={$flag} must be excluded");
    }

    public static function pickExcludingFlagProvider(): array
    {
        return [
            ['high_price'],
            ['unavailable'],
        ];
    }

    /** @test */
    public function best_offer_is_null_when_every_offer_is_condition_or_flag_excluded(): void
    {
        // Mirrors prod: product 3648 (Gaggia Magenta Prestige) — ONLY a
        // refurbished Whole Latte Love offer, nothing clean to fall back to.
        $product = $this->makeProduct();
        $storeA  = $this->makeStore();
        $storeB  = $this->makeStore();

        $this->makeOffer($product, $storeA, 500.00, condition: 'refurbished');
        $this->makeOffer($product, $storeB, 600.00, condition: 'new', listingFlags: ['unavailable']);

        $product->load('offers.store');

        $this->assertNull($product->best_offer, 'no purchasable offer remains — bestOffer must be null');
    }

    /** @test */
    public function best_price_and_affiliate_url_derive_from_the_same_excluded_best_offer(): void
    {
        $product     = $this->makeProduct();
        $amazonStore = $this->makeStore();
        $wllStore    = $this->makeStore();

        $cleanOffer = $this->makeOffer($product, $amazonStore, 670.00, condition: 'new');
        $this->makeOffer($product, $wllStore, 550.00, condition: 'refurbished');

        $product->load('offers.store');

        // best_price must match the SAME offer affiliate_url links to — never a
        // cheaper excluded offer's price paired with a different offer's link.
        $this->assertEquals('670.00', (string) $product->best_price);
        $this->assertSame($cleanOffer->affiliate_url, $product->affiliate_url);
    }
}
