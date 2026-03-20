<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\Category;
use App\Models\Product;
use App\Models\SearchLog;
use App\Livewire\GlobalSearch;
use App\Livewire\ProductCompare;
use Livewire\Livewire;

class SearchLogTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function global_search_db_returns_matching_results()
    {
        Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops']);
        Category::factory()->create(['name' => 'Lap desks', 'slug' => 'lap-desks']);

        Livewire::test(GlobalSearch::class)
            ->set('query', 'Lap')
            ->assertSet('open', true)
            ->assertSet('dbResults', fn ($v) => count($v) > 0);
    }

    /** @test */
    public function global_search_short_query_does_not_open()
    {
        Livewire::test(GlobalSearch::class)
            ->set('query', 'la')
            ->assertSet('open', false)
            ->assertSet('dbResults', []);
    }

    /** @test */
    public function global_search_ai_logs_successful_match()
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"suggested_category_slug": "laptops", "reasoning": "Best fit"}']]]
                ]]
            ], 200)
        ]);

        $category = Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops']);

        Livewire::test(GlobalSearch::class)
            ->set('query', 'gaming computer')
            ->call('triggerAiSearch')
            ->assertSet('isAiSearching', false)
            ->assertNotSet('aiSuggestion', null);

        $this->assertDatabaseHas('search_logs', [
            'type'          => 'global_search',
            'query'         => 'gaming computer',
            'category_name' => 'Laptops',
            'results_count' => 1,
        ]);
    }

    /** @test */
    public function global_search_ai_logs_failed_match()
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"suggested_category_slug": ""}']]]
                ]]
            ], 200)
        ]);

        Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops']);

        Livewire::test(GlobalSearch::class)
            ->set('query', 'xyz nonexistent thing')
            ->call('triggerAiSearch')
            ->assertSet('isAiSearching', false)
            ->assertNotSet('aiError', null);

        $this->assertDatabaseHas('search_logs', [
            'type'          => 'global_search',
            'query'         => 'xyz nonexistent thing',
            'category_name' => null,
            'results_count' => 0,
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
            'type'          => 'category_ai',
            'query'         => 'I need a cheap one',
            'category_name' => 'Laptops',
            'response_summary' => 'I set the price slider.',
        ]);
    }
}
