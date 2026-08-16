<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\View\Components\SimilarProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner decision (2026-08-16, "hide unbuyable products"): the "Similar Products"
 * rail on the product modal/page renders the same CTA-per-card grid as the
 * compare page, so it must apply the same purchasable-offer filter.
 */
class SimilarProductsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOffer(Product $product, Store $store, array $overrides = []): ProductOffer
    {
        return ProductOffer::create(array_merge([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 99.99,
            'raw_title'     => $product->name,
            'condition'     => 'new',
            'listing_flags' => [],
        ], $overrides));
    }

    /** @test */
    public function similar_products_excludes_a_product_with_no_purchasable_offer(): void
    {
        $category = Category::factory()->create();
        $brand    = Brand::factory()->create();
        $store    = Store::create([
            'tenant_id' => null,
            'name'      => 'Similar Store',
            'slug'      => 'similar-store-' . uniqid(),
            'is_active' => true,
        ]);

        $anchor = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 2,
        ]);

        $buyableSibling = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 2,
        ]);
        $this->makeOffer($buyableSibling, $store);

        $unbuyableSibling = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 2,
        ]);
        $this->makeOffer($unbuyableSibling, $store, ['condition' => 'refurbished']);

        $component = new SimilarProducts($anchor);
        $ids       = $component->similar->pluck('id');

        $this->assertTrue($ids->contains($buyableSibling->id), 'a buyable sibling must appear in the similar-products rail');
        $this->assertFalse($ids->contains($unbuyableSibling->id), 'a sibling with no purchasable offer must be excluded');
    }

    /** @test */
    public function similar_products_fills_from_other_price_tiers_when_the_same_tier_has_too_few_buyable_siblings(): void
    {
        $category = Category::factory()->create();
        $brand    = Brand::factory()->create();
        $store    = Store::create([
            'tenant_id' => null,
            'name'      => 'Similar Store 2',
            'slug'      => 'similar-store-2-' . uniqid(),
            'is_active' => true,
        ]);

        $anchor = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 2,
        ]);

        // Only one buyable same-tier sibling (below the 4-slot fill threshold).
        $sameTierBuyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 2,
        ]);
        $this->makeOffer($sameTierBuyable, $store);

        // A same-tier sibling with NO purchasable offer — must never fill a slot.
        $sameTierUnbuyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 2,
        ]);
        $this->makeOffer($sameTierUnbuyable, $store, ['scraped_price' => 0]);

        // A different-tier buyable sibling — should be pulled in to fill the gap.
        $otherTierBuyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'price_tier'  => 3,
        ]);
        $this->makeOffer($otherTierBuyable, $store);

        $component = new SimilarProducts($anchor);
        $ids       = $component->similar->pluck('id');

        $this->assertTrue($ids->contains($sameTierBuyable->id));
        $this->assertTrue($ids->contains($otherTierBuyable->id), 'a buyable product from another tier must fill the remaining slot');
        $this->assertFalse($ids->contains($sameTierUnbuyable->id), 'an unbuyable same-tier sibling must never fill a slot');
    }
}
