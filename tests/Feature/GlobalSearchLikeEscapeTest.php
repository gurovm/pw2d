<?php

namespace Tests\Feature;

use App\Livewire\GlobalSearch;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchLikeEscapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_with_percent_wildcard_does_not_match_everything(): void
    {
        $category = Category::factory()->create(['name' => 'Speakers']);
        Product::factory()->create([
            'name' => 'Sony Speaker XB10',
            'slug' => 'sony-speaker-xb10',
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);
        Product::factory()->create([
            'name' => 'Bose SoundLink',
            'slug' => 'bose-soundlink',
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);

        // Search with a LIKE wildcard character -- should not match all products
        $component = Livewire::test(GlobalSearch::class)
            ->set('query', '%test%')
            ->call('search');

        $dbResults = $component->get('dbResults');
        $productResults = array_filter($dbResults, fn ($r) => $r['type'] === 'product');

        $this->assertEmpty($productResults, 'LIKE wildcard injection should not return products');
    }

    public function test_search_with_underscore_wildcard_is_escaped(): void
    {
        $category = Category::factory()->create(['name' => 'Audio']);
        Product::factory()->create([
            'name' => 'Test Product ABC',
            'slug' => 'test-product-abc',
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);

        // Underscore should not act as single-char wildcard
        $component = Livewire::test(GlobalSearch::class)
            ->set('query', 'T_st Product')
            ->call('search');

        $dbResults = $component->get('dbResults');
        $productResults = array_filter($dbResults, fn ($r) => $r['type'] === 'product');

        $this->assertEmpty($productResults, 'Underscore wildcard should be escaped and not match');
    }
}
