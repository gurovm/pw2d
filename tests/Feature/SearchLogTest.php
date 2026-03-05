<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\Category;
use App\Models\Product;
use App\Models\SearchLog;
use App\Livewire\GlobalSearch;
use App\Livewire\Home;
use App\Livewire\ProductCompare;
use Livewire\Livewire;

class SearchLogTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function global_search_logs_query_and_results_count()
    {
        $category = Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops']);
        Category::factory()->create(['name' => 'Lap desks', 'slug' => 'lap-desks']);
        Product::factory()->create(['name' => 'Lap desk max', 'category_id' => $category->id, 'slug' => 'lap-desk-max']);
        // 3 results matching "lap"
        
        Livewire::test(GlobalSearch::class)
            ->set('search', 'lap')
            ->assertDispatched('global_search_used');
            
        $this->assertDatabaseHas('search_logs', [
            'type' => 'global_search',
            'query' => 'lap',
            'results_count' => 3,
            'category_name' => null,
        ]);
        
        // Ensure < 3 characters does not log
        Livewire::test(GlobalSearch::class)
            ->set('search', 'la')
            ->assertDispatched('global_search_used');
            
        $this->assertDatabaseMissing('search_logs', [
            'type' => 'global_search',
            'query' => 'la',
        ]);
    }

    /** @test */
    public function home_ai_search_logs_query()
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"slug": "laptops"}']]]
                ]]
            ], 200)
        ]);
        
        $category = Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops', 'description' => 'Test']);
        Product::factory()->create(['category_id' => $category->id, 'slug' => 'test-product']);
        
        Livewire::test(Home::class)
            ->set('searchQuery', 'gaming computer')
            ->call('searchCategory');
            
        $this->assertDatabaseHas('search_logs', [
            'type' => 'homepage_ai',
            'query' => 'gaming computer',
            'category_name' => null,
        ]);
    }

    /** @test */
    public function product_compare_ai_concierge_logs_query_and_summary()
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => '{"status": "complete", "message": "I set the price slider.", "weights": {}}']]]
                ]]
            ], 200)
        ]);
        
        $category = Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops']);
        
        Livewire::test(ProductCompare::class, ['slug' => 'laptops'])
            ->set('userInput', 'I need a cheap one')
            ->call('analyzeUserNeeds');
            
        $this->assertDatabaseHas('search_logs', [
            'type' => 'category_ai',
            'query' => 'I need a cheap one',
            'category_name' => 'Laptops',
            'response_summary' => 'I set the price slider.',
        ]);
    }
}
