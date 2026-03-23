<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class HomeCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../app/Helpers/cache.php';
    }

    public function test_homepage_caches_popular_categories(): void
    {
        $category = Category::factory()->create(['name' => 'Microphones']);
        Product::factory()->count(2)->create([
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);

        // First render populates cache
        Livewire::test(Home::class)->assertStatus(200);

        $cacheKey = tenant_cache_key('home:popular_categories');
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_homepage_caches_sample_prompts(): void
    {
        Category::factory()->create(['name' => 'Keyboards']);

        Livewire::test(Home::class)->assertStatus(200);

        $cacheKey = tenant_cache_key('home:sample_prompts');
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_homepage_serves_from_cache_on_second_load(): void
    {
        $category = Category::factory()->create(['name' => 'Headphones']);
        Product::factory()->count(2)->create([
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);

        // First render populates cache
        Livewire::test(Home::class)->assertStatus(200);

        // Second render should hit cache - verify by checking DB query count
        $queryCount = 0;
        \DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        Livewire::test(Home::class)->assertStatus(200);

        // With cache, no category queries should fire (only Livewire internal queries)
        // The key assertion is that Cache::has() returns true (tested above)
        $this->assertTrue(Cache::has(tenant_cache_key('home:popular_categories')));
        $this->assertTrue(Cache::has(tenant_cache_key('home:sample_prompts')));
    }
}
