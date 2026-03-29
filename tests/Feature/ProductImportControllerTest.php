<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyFromPayload;
use App\Http\Middleware\VerifyExtensionToken;
use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([VerifyExtensionToken::class, InitializeTenancyFromPayload::class]);

        $this->category = Category::factory()->create();
        Feature::factory()->count(3)->create(['category_id' => $this->category->id]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id'   => $this->category->id,
            'external_id'   => 'B0ABC12345',
            'title'         => 'Sony WH-1000XM5 Wireless Headphones',
            'price'         => 349.99,
            'rating'        => 4.7,
            'reviews_count' => 12500,
            'image_url'     => 'https://m.media-amazon.com/images/I/test.jpg',
        ], $overrides);
    }

    /** @test */
    public function valid_import_creates_product_with_pending_ai_status_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/product-import', $this->validPayload());

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action'  => 'queued_new',
            ]);

        $this->assertDatabaseHas('products', [
            'category_id'  => $this->category->id,
            'status'       => 'pending_ai',
            'is_ignored'   => false,
        ]);

        // Verify offer was created
        $this->assertDatabaseHas('product_offers', [
            'url' => 'https://www.amazon.com/dp/B0ABC12345',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function duplicate_asin_updates_existing_product_and_requeues(): void
    {
        Queue::fake();

        $existing = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'name'        => 'Old Name',
        ]);

        $store = Store::firstOrCreate(
            ['slug' => 'amazon', 'tenant_id' => $existing->tenant_id],
            ['name' => 'Amazon']
        );

        ProductOffer::create([
            'product_id'  => $existing->id,
            'tenant_id'   => $existing->tenant_id,
            'store_id'    => $store->id,
            'url'         => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'   => 'Old Name',
        ]);

        $response = $this->postJson('/api/product-import', $this->validPayload());

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action'  => 'queued_rescan',
            ]);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'id'     => $existing->id,
            'status' => 'pending_ai',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function missing_category_id_returns_422(): void
    {
        $response = $this->postJson(
            '/api/product-import',
            $this->validPayload(['category_id' => null])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function missing_external_id_returns_422(): void
    {
        $response = $this->postJson(
            '/api/product-import',
            $this->validPayload(['external_id' => null])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_id']);
    }

    /** @test */
    public function missing_title_returns_422(): void
    {
        $response = $this->postJson(
            '/api/product-import',
            $this->validPayload(['title' => null])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /** @test */
    public function category_with_no_features_returns_400(): void
    {
        Queue::fake();

        $emptyCategory = Category::factory()->create();

        $response = $this->postJson(
            '/api/product-import',
            $this->validPayload(['category_id' => $emptyCategory->id])
        );

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error'   => 'No Features',
            ]);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function nonexistent_category_id_returns_422(): void
    {
        $response = $this->postJson(
            '/api/product-import',
            $this->validPayload(['category_id' => 99999])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function import_without_optional_fields_succeeds(): void
    {
        Queue::fake();

        $payload = [
            'category_id' => $this->category->id,
            'external_id' => 'B0XYZ99999',
            'title'       => 'Minimal Product Import',
        ];

        $response = $this->postJson('/api/product-import', $payload);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('products', [
            'status'               => 'pending_ai',
            'amazon_reviews_count' => 0,
        ]);

        $this->assertDatabaseHas('product_offers', [
            'url' => 'https://www.amazon.com/dp/B0XYZ99999',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }
}
