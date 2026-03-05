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
