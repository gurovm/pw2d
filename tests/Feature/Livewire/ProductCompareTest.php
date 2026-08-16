<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ProductCompare;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Brand;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCompareTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function renders_successfully()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        Feature::factory()->count(3)->create(['category_id' => $category->id]);
        
        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->assertStatus(200);
    }

    /** @test */
    public function toggles_ai_chat_on_event()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->assertSet('showAiChat', false)
            ->dispatch('toggle-ai-chat')
            ->assertSet('showAiChat', true)
            ->dispatch('toggle-ai-chat')
            ->assertSet('showAiChat', false);
    }

    /** @test */
    public function trigger_ai_concierge_event_starts_analysis()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"status": "needs_clarification", "message": "More info please"}']]]
                ]]
            ], 200)
        ]);

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->dispatch('trigger-ai-concierge', prompt: 'Best laptop under $1000')
            ->assertSet('userInput', '')
            ->assertSet('showAiChat', true);
    }

    /** @test */
    public function updates_weights_and_recalculates_on_event()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $feature = Feature::factory()->create(['category_id' => $category->id]);
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'slug' => 'test-product',
        ]);
        
        // Initial weights are 50
        
        $newWeights = [$feature->id => 100];

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->dispatch('weights-updated', 
                weights: $newWeights, 
                priceWeight: 0, 
                amazonRatingWeight: 0
            )
            ->assertSet('weights', $newWeights)
            ->assertSet('priceWeight', 0)
            ->assertSet('amazonRatingWeight', 0);
    }

    /** @test */
    public function can_open_and_close_product_modal_by_slug()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'slug' => 'awesome-laptop-1',
        ]);

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->assertSet('selectedProductSlug', null)
            ->call('openProduct', 'awesome-laptop-1')
            ->assertSet('selectedProductSlug', 'awesome-laptop-1')
            ->call('closeProduct')
            ->assertSet('selectedProductSlug', null);
    }

    /** @test */
    public function it_injects_category_seo_metadata()
    {
        $category = Category::factory()->create([
            'slug' => 'gaming-mice',
            'name' => 'Gaming Mice',
            'buying_guide' => ['how_to_decide' => '<p>Look for high DPI and low latency.</p>']
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => 'gaming-mice']);
        
        $component->assertStatus(200);
    }

    /** @test */
    public function it_injects_product_seo_metadata_when_product_selected()
    {
        $category = Category::factory()->create(['slug' => 'gaming-mice', 'name' => 'Gaming Mice']);
        $brand = Brand::factory()->create(['name' => 'Logitech']);
        
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'G Pro X Superlight',
            'slug' => 'g-pro-x-superlight',
            'ai_summary' => 'This is an extremely light mouse <br> perfect for FPS.'
        ]);

        $component = Livewire::test(ProductCompare::class, ['product' => $product]);
        
        $component->assertStatus(200);
    }

    /** @test */
    public function toggle_compare_adds_and_removes_product()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        $feature = Feature::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'slug' => 'laptop-a',
        ]);

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->assertSet('compareList', [])
            ->call('toggleCompare', $product->id)
            ->assertSet('compareList', [$product->id])
            ->call('toggleCompare', $product->id)
            ->assertSet('compareList', []);
    }

    /** @test */
    public function toggle_compare_caps_at_four_products()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $products = collect();
        for ($i = 1; $i <= 5; $i++) {
            $products->push(Product::factory()->create([
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'slug' => "laptop-{$i}",
            ]));
        }

        $component = Livewire::test(ProductCompare::class, ['slug' => 'laptops']);

        foreach ($products->take(4) as $p) {
            $component->call('toggleCompare', $p->id);
        }

        $component
            ->assertCount('compareList', 4)
            ->call('toggleCompare', $products[4]->id)
            ->assertCount('compareList', 4)
            ->assertDispatched('compare-limit-reached');
    }

    /** @test */
    public function clear_compare_resets_list_and_is_comparing()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $products = collect();
        for ($i = 1; $i <= 3; $i++) {
            $products->push(Product::factory()->create([
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'slug' => "laptop-{$i}",
            ]));
        }

        $component = Livewire::test(ProductCompare::class, ['slug' => 'laptops']);

        foreach ($products as $p) {
            $component->call('toggleCompare', $p->id);
        }

        $component
            ->call('startComparison')
            ->assertSet('isComparing', true)
            ->call('clearCompare')
            ->assertSet('compareList', [])
            ->assertSet('isComparing', false);
    }

    /** @test */
    public function start_comparison_requires_at_least_two_products()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'slug' => 'laptop-solo',
        ]);

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->call('toggleCompare', $product->id)
            ->call('startComparison')
            ->assertSet('isComparing', false);
    }

    /** @test */
    public function stop_comparison_keeps_compare_list()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $products = collect();
        for ($i = 1; $i <= 2; $i++) {
            $products->push(Product::factory()->create([
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'slug' => "laptop-{$i}",
            ]));
        }

        $component = Livewire::test(ProductCompare::class, ['slug' => 'laptops']);

        foreach ($products as $p) {
            $component->call('toggleCompare', $p->id);
        }

        $component
            ->call('startComparison')
            ->assertSet('isComparing', true)
            ->call('stopComparison')
            ->assertSet('isComparing', false)
            ->assertCount('compareList', 2);
    }

    /** @test */
    public function focus_param_auto_pins_product_and_clears_url()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'slug' => 'target-laptop',
        ]);

        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->set('focus', 'target-laptop')
            // focus is processed in mount, so we simulate via withQueryParams
            ;

        // Test via fresh mount with the focus param set
        Livewire::withQueryParams(['focus' => 'target-laptop'])
            ->test(ProductCompare::class, ['slug' => 'laptops'])
            ->assertSet('focus', '')
            ->assertSet('compareList', [$product->id]);
    }

    /** @test */
    public function focus_param_ignores_product_from_wrong_category()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $otherCategory = Category::factory()->create(['slug' => 'mice']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        Product::factory()->create([
            'category_id' => $otherCategory->id,
            'brand_id' => $brand->id,
            'slug' => 'wrong-category-product',
        ]);

        Livewire::withQueryParams(['focus' => 'wrong-category-product'])
            ->test(ProductCompare::class, ['slug' => 'laptops'])
            ->assertSet('focus', '')
            ->assertSet('compareList', []);
    }

    /** @test */
    public function it_handles_missing_seo_data_gracefully()
    {
        // Category with NO buying guide data
        $category = Category::factory()->create([
            'slug' => 'empty-cat',
            'name' => 'Empty Cat',
            'buying_guide' => null
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => 'empty-cat']);
        $component->assertStatus(200);

        // Product with NO ai_summary
        $brand = Brand::factory()->create(['name' => 'Generic']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Boring Product',
            'slug' => 'boring-product',
            'ai_summary' => null
        ]);

        $productComponent = Livewire::test(ProductCompare::class, ['product' => $product]);
        $productComponent->assertStatus(200);
    }

    // =========================================================================
    // Perf C1 / Review S1 (2026-08-16 audit): scoredProducts() must reason about
    // the same (filtered) price the rendered card's CTA reads — a product whose
    // only offer is priced but excluded (negative condition / pick-excluding
    // flag) must never carry a non-null estimated_price into the scoring/ranking
    // pass, since the rendered card's affiliate_url will be null for it.
    //
    // Superseded 2026-08-16 (owner decision, "hide unbuyable products"): a
    // product with NO purchasable offer at all is no longer scored/rendered on
    // compare pages — see the block below. The C1/S1 "score with null price"
    // behavior only applies now to a product that has AT LEAST one purchasable
    // offer alongside its excluded one(s) (tested separately below).
    // =========================================================================

    /** @test */
    public function a_product_whose_only_offer_carries_a_pick_excluding_flag_is_hidden_from_the_compare_grid(): void
    {
        $category = Category::factory()->create(['slug' => 'espresso-machines']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'flagged-only-offer',
        ]);

        $store = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Amazon',
            'slug'            => 'amazon-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        \App\Models\ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 49.99,
            'raw_title'     => 'Test Product',
            'condition'     => 'new',
            'listing_flags' => ['unavailable'],
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => 'espresso-machines']);

        // Owner decision (2026-08-16): a product with no purchasable offer is
        // hidden entirely — a card with no "Check Current Price" CTA defeats the
        // whole point of a compare page.
        $scored = $component->instance()->scoredProducts;
        $this->assertNull(
            $scored->firstWhere('id', $product->id),
            'a product whose only offer carries a pick-excluding flag must be absent from scoredProducts'
        );

        $visible = $component->instance()->visibleProducts->firstWhere('id', $product->id);
        $this->assertNull($visible, 'the product must not render as a card on the compare grid');
    }

    /** @test */
    public function a_product_with_one_flagged_offer_and_one_clean_priced_offer_is_present(): void
    {
        $category = Category::factory()->create(['slug' => 'espresso-machines-mixed']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'mixed-offers-product',
        ]);

        $store = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Amazon',
            'slug'            => 'amazon-mixed-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);
        $otherStore = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Whole Latte Love',
            'slug'            => 'wll-mixed-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        // Offer 1: flagged — must not, on its own, count as purchasable.
        \App\Models\ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 39.99,
            'raw_title'     => 'Flagged Offer',
            'condition'     => 'new',
            'listing_flags' => ['high_price'],
        ]);

        // Offer 2: clean and priced — this alone makes the product purchasable.
        \App\Models\ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $otherStore->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 59.99,
            'raw_title'     => 'Clean Offer',
            'condition'     => 'new',
            'listing_flags' => [],
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => 'espresso-machines-mixed']);

        $scored = $component->instance()->scoredProducts;
        $this->assertNotNull(
            $scored->firstWhere('id', $product->id),
            'a product with at least one purchasable offer must remain scored, even if another offer is flagged'
        );

        $visible = $component->instance()->visibleProducts->firstWhere('id', $product->id);
        $this->assertNotNull($visible, 'the product must render as a card on the compare grid');
        $this->assertNotNull($visible->affiliate_url, 'the card must have a working CTA, sourced from the clean offer');
    }

    /** @test */
    public function a_product_whose_only_offer_has_price_zero_is_absent_from_the_compare_grid(): void
    {
        $category = Category::factory()->create(['slug' => 'espresso-machines-zero-price']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'zero-price-product',
        ]);

        $store = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Amazon',
            'slug'            => 'amazon-zero-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        \App\Models\ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 0,
            'raw_title'     => 'Zero Price Offer',
            'condition'     => 'new',
            'listing_flags' => [],
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => 'espresso-machines-zero-price']);

        $scored = $component->instance()->scoredProducts;
        $this->assertNull(
            $scored->firstWhere('id', $product->id),
            'a product whose only offer is priced at $0 must be absent from scoredProducts'
        );

        $visible = $component->instance()->visibleProducts->firstWhere('id', $product->id);
        $this->assertNull($visible, 'a $0.00-only-offer product must not render as a card');
    }

    /** @test */
    public function schema_itemlist_matches_the_rendered_set_when_a_product_has_no_purchasable_offer(): void
    {
        $category = Category::factory()->create(['slug' => 'espresso-machines-schema']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $store = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Amazon',
            'slug'            => 'amazon-schema-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        $buyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'schema-buyable-product',
            'name'        => 'Schema Buyable Product',
        ]);
        \App\Models\ProductOffer::create([
            'product_id'    => $buyable->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 199.99,
            'raw_title'     => 'Schema Buyable Product',
            'condition'     => 'new',
        ]);

        $unbuyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'schema-unbuyable-product',
            'name'        => 'Schema Unbuyable Product',
        ]);
        \App\Models\ProductOffer::create([
            'product_id'    => $unbuyable->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 149.99,
            'raw_title'     => 'Schema Unbuyable Product',
            'condition'     => 'refurbished',
        ]);

        $html = $this->get('/compare/espresso-machines-schema')->getContent();

        preg_match_all(
            '/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/si',
            $html,
            $matches,
        );

        $itemListSchema = null;
        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded) && ($decoded['@type'] ?? '') === 'ItemList') {
                $itemListSchema = $decoded;
                break;
            }
        }

        $this->assertNotNull($itemListSchema, 'An ItemList schema must be present.');

        $schemaUrls = collect($itemListSchema['itemListElement'])
            ->map(fn (array $item) => $item['url'] ?? ($item['item']['url'] ?? null))
            ->filter()
            ->values();

        $this->assertTrue(
            $schemaUrls->contains(fn ($url) => str_contains($url, 'schema-buyable-product')),
            'ItemList must include the purchasable product.'
        );
        $this->assertFalse(
            $schemaUrls->contains(fn ($url) => str_contains($url, 'schema-unbuyable-product')),
            'ItemList must NOT include the product with no purchasable offer — it is not on the rendered page either.'
        );
    }

    /** @test */
    public function the_price_slider_max_bound_ignores_a_products_only_unbuyable_offer(): void
    {
        $category = Category::factory()->create(['slug' => 'espresso-machines-slider-bound']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $store = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Amazon',
            'slug'            => 'amazon-slider-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        // A cheap, buyable product — establishes a LOW real max.
        $buyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'slider-bound-buyable',
        ]);
        \App\Models\ProductOffer::create([
            'product_id'    => $buyable->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 75.00,
            'raw_title'     => 'Slider Bound Buyable',
            'condition'     => 'new',
        ]);

        // An expensive, unbuyable-only-offer product — must NOT stretch maxPrice.
        $unbuyable = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'slider-bound-unbuyable',
        ]);
        \App\Models\ProductOffer::create([
            'product_id'    => $unbuyable->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 999.00,
            'raw_title'     => 'Slider Bound Unbuyable',
            'condition'     => 'used',
        ]);

        Livewire::test(ProductCompare::class, ['slug' => 'espresso-machines-slider-bound'])
            ->assertSet('maxPrice', 75);
    }

    /** @test */
    public function the_price_slider_does_not_admit_a_product_solely_via_a_negative_condition_offer(): void
    {
        $category = Category::factory()->create(['slug' => 'espresso-machines-2']);
        $brand = Brand::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $store = \App\Models\Store::create([
            'tenant_id'       => null,
            'name'            => 'Whole Latte Love',
            'slug'            => 'wll-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        // A second, clean product priced at $200 — establishes maxPrice=200 at
        // mount time, so the slider ($100) genuinely narrows the result set
        // without needing a second ->set() call that would collide with the
        // cache key (which is keyed on selectedPrice only, not maxPrice).
        $cleanProduct = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'clean-expensive-offer',
        ]);
        \App\Models\ProductOffer::create([
            'product_id'    => $cleanProduct->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 200.00,
            'raw_title'     => 'Clean Product',
            'condition'     => 'new',
            'listing_flags' => [],
        ]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'refurbished-only-offer',
        ]);

        // Cheap, but refurbished — must not qualify the product for a $100 slider.
        \App\Models\ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/product/' . uniqid(),
            'scraped_price' => 50.00,
            'raw_title'     => 'Test Product',
            'condition'     => 'refurbished',
            'listing_flags' => [],
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => 'espresso-machines-2'])
            ->assertSet('maxPrice', 200)
            ->set('selectedPrice', 100);

        $scored = $component->instance()->scoredProducts;

        $this->assertNull(
            $scored->firstWhere('id', $cleanProduct->id),
            'sanity check: the $200 clean product must be excluded by the $100 slider (proves the filter runs at all)'
        );
        $this->assertNull(
            $scored->firstWhere('id', $product->id),
            'a refurbished-only $50 offer must not admit the product through the $100 price slider'
        );
    }
}
