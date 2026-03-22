<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ComparisonHeader;
use App\Livewire\ProductCompare;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
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
