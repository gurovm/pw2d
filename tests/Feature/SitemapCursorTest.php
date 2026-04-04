<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapCursorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create(['id' => 'test-tenant', 'name' => 'Test']);
        tenancy()->initialize(Tenant::find('test-tenant'));
    }

    public function test_sitemap_generates_successfully(): void
    {
        $category = Category::factory()->create(['name' => 'Microphones']);
        Product::factory()->count(5)->create([
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
    }

    public function test_sitemap_includes_product_urls(): void
    {
        $category = Category::factory()->create([
            'name' => 'Keyboards',
            'slug' => 'keyboards',
        ]);
        $product = Product::factory()->create([
            'name' => 'Test Keyboard',
            'slug' => 'test-keyboard',
            'category_id' => $category->id,
            'is_ignored' => false,
            'status' => null,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee('test-keyboard');
    }

    public function test_sitemap_excludes_ignored_products(): void
    {
        $category = Category::factory()->create(['name' => 'Mice']);
        Product::factory()->create([
            'name' => 'Ignored Mouse',
            'slug' => 'ignored-mouse',
            'category_id' => $category->id,
            'is_ignored' => true,
            'status' => null,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertDontSee('ignored-mouse');
    }
}
