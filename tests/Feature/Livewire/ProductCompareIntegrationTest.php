<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ComparisonHeader;
use App\Livewire\ProductCompare;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCompareIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_loads_the_product_compare_page_and_header_component()
    {
        // Setup Data
        $category = Category::factory()->create(['name' => 'Smartphones', 'slug' => 'smartphones']);
        $brand = Brand::factory()->create(['name' => 'TechBrand']);
        $features = Feature::factory()->count(3)->create(['category_id' => $category->id]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Super Phone 3000',
            'slug' => 'super-phone-3000',
        ]);

        // Owner decision (2026-08-16): a product with no purchasable offer is now
        // hidden from the compare grid — give it a clean, priced offer so it still
        // renders (this test is about the header component wiring, not buyability).
        $store = Store::create([
            'tenant_id'  => null,
            'name'       => 'TechStore',
            'slug'       => 'techstore-' . uniqid(),
            'is_active'  => true,
        ]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://example.com/super-phone-3000',
            'scraped_price' => 599.00,
            'raw_title'     => 'Super Phone 3000',
            'condition'     => 'new',
        ]);

        // Hit the Route
        $response = $this->get('/compare/' . $category->slug);

        // Assert Page Loads
        $response->assertStatus(200);
        $response->assertSee('Smartphones');
        $response->assertSee('Super Phone 3000');

        // Assert Header Component is Present
        $response->assertSeeLivewire(ComparisonHeader::class);
    }

    /** @test */
    public function it_updates_products_when_header_emits_events()
    {
        $category = Category::factory()->create(['slug' => 'laptops']);
        $features = Feature::factory()->count(1)->create(['category_id' => $category->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'slug' => 'test-laptop'
        ]);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // Emit event from child component (simulated)
        $component->dispatch('weights-updated', 
            weights: [$features[0]->id => 100], 
            priceWeight: 10, 
            amazonRatingWeight: 10
        );

        // Verify state update in parent
        $this->assertEquals(10, $component->get('priceWeight'));
        $this->assertEquals(10, $component->get('amazonRatingWeight'));
        $this->assertEquals(100, $component->get('weights')[$features[0]->id]);
    }
}
