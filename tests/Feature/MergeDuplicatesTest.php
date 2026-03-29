<?php

namespace Tests\Feature;

use App\Models\AiMatchingDecision;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private function createDuplicatePair(
        ?Brand $brand = null,
        ?Category $category = null,
        string $name = 'Rode NT-USB Mini',
    ): array {
        $brand ??= Brand::factory()->create(['name' => 'Rode']);
        $category ??= Category::factory()->create();

        $canonical = Product::factory()->create([
            'name'        => $name,
            'brand_id'    => $brand->id,
            'category_id' => $category->id,
            'status'      => null,
            'is_ignored'  => false,
        ]);

        $duplicate = Product::factory()->create([
            'name'        => $name,
            'brand_id'    => $brand->id,
            'category_id' => $category->id,
            'status'      => null,
            'is_ignored'  => false,
        ]);

        return [$canonical, $duplicate, $brand, $category];
    }

    /** @test */
    public function merge_duplicates_combines_offers(): void
    {
        [$canonical, $duplicate] = $this->createDuplicatePair();

        $storeA = Store::create(['name' => 'Amazon', 'slug' => 'amazon', 'tenant_id' => $canonical->tenant_id]);
        $storeB = Store::create(['name' => 'Best Buy', 'slug' => 'best-buy', 'tenant_id' => $canonical->tenant_id]);

        ProductOffer::create([
            'tenant_id'     => $canonical->tenant_id,
            'product_id'    => $canonical->id,
            'store_id'      => $storeA->id,
            'url'           => 'https://amazon.com/dp/B001',
            'scraped_price' => 99.99,
            'raw_title'     => 'Rode NT-USB Mini',
        ]);

        ProductOffer::create([
            'tenant_id'     => $duplicate->tenant_id,
            'product_id'    => $duplicate->id,
            'store_id'      => $storeB->id,
            'url'           => 'https://bestbuy.com/rode-nt-usb-mini',
            'scraped_price' => 89.99,
            'raw_title'     => 'Rode NT-USB Mini',
        ]);

        $this->artisan('pw2d:merge-duplicates')
            ->assertExitCode(0);

        // Canonical should remain, duplicate should be gone
        $this->assertDatabaseHas('products', ['id' => $canonical->id]);
        $this->assertDatabaseMissing('products', ['id' => $duplicate->id]);

        // Both offers should belong to canonical
        $this->assertCount(2, ProductOffer::where('product_id', $canonical->id)->get());
    }

    /** @test */
    public function merge_duplicates_dry_run(): void
    {
        [$canonical, $duplicate] = $this->createDuplicatePair();

        $this->artisan('pw2d:merge-duplicates', ['--dry-run' => true])
            ->assertExitCode(0);

        // Both products should still exist
        $this->assertDatabaseHas('products', ['id' => $canonical->id]);
        $this->assertDatabaseHas('products', ['id' => $duplicate->id]);
    }

    /** @test */
    public function merge_duplicates_keeps_lower_price_on_store_conflict(): void
    {
        [$canonical, $duplicate] = $this->createDuplicatePair();

        $store = Store::create(['name' => 'Amazon', 'slug' => 'amazon', 'tenant_id' => $canonical->tenant_id]);

        ProductOffer::create([
            'tenant_id'     => $canonical->tenant_id,
            'product_id'    => $canonical->id,
            'store_id'      => $store->id,
            'url'           => 'https://amazon.com/dp/B001',
            'scraped_price' => 129.99,
            'raw_title'     => 'Rode NT-USB Mini',
        ]);

        ProductOffer::create([
            'tenant_id'     => $duplicate->tenant_id,
            'product_id'    => $duplicate->id,
            'store_id'      => $store->id,
            'url'           => 'https://amazon.com/dp/B002',
            'scraped_price' => 89.99,
            'raw_title'     => 'Rode NT-USB Mini',
        ]);

        $this->artisan('pw2d:merge-duplicates')
            ->assertExitCode(0);

        // Only one offer should remain (canonical product, lower price)
        $offers = ProductOffer::where('product_id', $canonical->id)->get();
        $this->assertCount(1, $offers);
        $this->assertEquals('89.99', $offers->first()->scraped_price);
    }
}
