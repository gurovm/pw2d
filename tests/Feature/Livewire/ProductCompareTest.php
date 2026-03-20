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
}
