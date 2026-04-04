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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for POST /api/products/batch-import (BatchImportController).
 *
 * NOTE: Several tests that exercise the controller body are marked as requiring
 * MySQL because the duplicate-ASIN detection query uses SUBSTRING_INDEX, which
 * is a MySQL-only function not available in the SQLite in-memory test database.
 * Those tests will be skipped automatically on SQLite and run on MySQL CI/CD.
 */
class BatchImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([VerifyExtensionToken::class, InitializeTenancyFromPayload::class]);

        $this->category = Category::factory()->create([
            'budget_max'   => 50,
            'midrange_max' => 150,
        ]);
        Feature::factory()->count(3)->create(['category_id' => $this->category->id]);
    }

    /**
     * Skip the test if the current database driver is not MySQL.
     * The batch import controller uses SUBSTRING_INDEX (MySQL-only) for ASIN dedup.
     */
    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'This test requires MySQL — SUBSTRING_INDEX is used for ASIN dedup and is unavailable on SQLite.'
            );
        }
    }

    /**
     * Build a valid batch import payload with sensible defaults.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'products'    => [
                [
                    'asin'          => 'B0ABC12345',
                    'title'         => 'Sony WH-1000XM5 Wireless Headphones',
                    'price'         => 349.99,
                    'rating'        => 4.7,
                    'reviews_count' => 12500,
                    'image_url'     => 'https://m.media-amazon.com/images/I/test.jpg',
                ],
            ],
        ], $overrides);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Happy path (require MySQL — controller body uses SUBSTRING_INDEX)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function valid_batch_creates_products_offers_and_dispatches_jobs(): void
    {
        $this->requireMysql();
        Queue::fake();

        $response = $this->postJson('/api/products/batch-import', $this->validPayload());

        $response->assertOk()
            ->assertJson([
                'success'   => true,
                'created'   => 1,
                'refreshed' => 0,
            ]);

        $this->assertDatabaseHas('products', [
            'category_id' => $this->category->id,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->assertDatabaseHas('product_offers', [
            'url' => 'https://www.amazon.com/dp/B0ABC12345',
        ]);

        $this->assertDatabaseHas('stores', [
            'slug' => 'amazon',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function batch_creates_multiple_products_and_dispatches_one_job_per_product(): void
    {
        $this->requireMysql();
        Queue::fake();

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0AAA00001',
                    'title' => 'Product Alpha Wireless Headphones',
                    'price' => 199.99,
                ],
                [
                    'asin'  => 'B0AAA00002',
                    'title' => 'Product Beta Over-Ear Headphones',
                    'price' => 299.99,
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['created' => 2, 'refreshed' => 0]);

        $this->assertDatabaseCount('products', 2);
        Queue::assertPushed(ProcessPendingProduct::class, 2);
    }

    /** @test */
    public function amazon_store_is_auto_created_on_first_import(): void
    {
        $this->requireMysql();
        Queue::fake();

        $this->assertDatabaseMissing('stores', ['slug' => 'amazon']);

        $this->postJson('/api/products/batch-import', $this->validPayload());

        $this->assertDatabaseHas('stores', ['slug' => 'amazon', 'name' => 'Amazon']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Duplicate ASIN handling (require MySQL)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function duplicate_asin_refreshes_existing_offer_and_does_not_create_new_product(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Pre-seed an existing product with an Amazon offer for the same ASIN
        $store = Store::create([
            'tenant_id' => $this->category->tenant_id,
            'slug'      => 'amazon',
            'name'      => 'Amazon',
        ]);

        $existingProduct = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'name'        => 'Old Product Name',
        ]);

        ProductOffer::create([
            'tenant_id'     => $this->category->tenant_id,
            'product_id'    => $existingProduct->id,
            'store_id'      => $store->id,
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'scraped_price' => 299.99,
            'raw_title'     => 'Old Product Name',
        ]);

        $response = $this->postJson('/api/products/batch-import', $this->validPayload());

        $response->assertOk()->assertJson([
            'success'   => true,
            'created'   => 0,
            'refreshed' => 1,
        ]);

        // No new product was created — still exactly 1
        $this->assertDatabaseCount('products', 1);

        // Offer price was updated to the new scraped value
        $this->assertDatabaseHas('product_offers', [
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'scraped_price' => 349.99,
        ]);

        // No AI job dispatched for a mere price refresh
        Queue::assertNothingPushed();
    }

    /** @test */
    public function duplicate_asin_with_empty_price_marks_product_ignored(): void
    {
        $this->requireMysql();
        Queue::fake();

        $store = Store::create([
            'tenant_id' => $this->category->tenant_id,
            'slug'      => 'amazon',
            'name'      => 'Amazon',
        ]);

        $existingProduct = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'is_ignored'  => false,
        ]);

        ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Some Product',
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0ABC12345',
                    'title' => 'Some Product',
                    'price' => null, // null price signals out-of-stock/removed listing
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload);

        $this->assertDatabaseHas('products', [
            'id'         => $existingProduct->id,
            'is_ignored' => true,
        ]);

        Queue::assertNothingPushed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Price filtering — suspiciously cheap items are skipped (require MySQL)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function new_product_below_half_of_budget_max_is_skipped(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Category budget_max is 50, so the cheap-item threshold is 50 * 0.5 = $25
        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0CHEAP001',
                    'title' => 'Suspiciously Cheap Cable or Accessory',
                    'price' => 10.00, // below $25 threshold
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['created' => 0]);
        $this->assertDatabaseCount('products', 0);
        Queue::assertNothingPushed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Validation failures — these never reach the SUBSTRING_INDEX query
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function missing_category_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['category_id']);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function nonexistent_category_id_returns_422(): void
    {
        $this->postJson('/api/products/batch-import', $this->validPayload(['category_id' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function missing_products_array_returns_422(): void
    {
        $this->postJson('/api/products/batch-import', ['category_id' => $this->category->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    /** @test */
    public function empty_products_array_returns_422(): void
    {
        $this->postJson('/api/products/batch-import', $this->validPayload(['products' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    /** @test */
    public function product_missing_asin_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [['title' => 'No ASIN Product', 'price' => 99.99]],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.asin']);
    }

    /** @test */
    public function product_missing_title_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [['asin' => 'B0NOTITLE1', 'price' => 99.99]],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.title']);
    }

    /** @test */
    public function product_with_invalid_rating_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'   => 'B0BADRATE1',
                    'title'  => 'Bad Rating Product',
                    'price'  => 99.99,
                    'rating' => 9.9, // above max of 5
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.rating']);
    }

    /** @test */
    public function category_with_no_features_returns_400(): void
    {
        // This test does NOT require MySQL — the features check happens before the
        // SUBSTRING_INDEX dedup query is executed
        Queue::fake();

        $emptyCategory = Category::factory()->create();

        $this->postJson('/api/products/batch-import', $this->validPayload(['category_id' => $emptyCategory->id]))
            ->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error'   => 'No Features',
            ]);

        Queue::assertNothingPushed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Auth — extension token middleware (short-circuits before controller body)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function request_without_extension_token_returns_403(): void
    {
        $this->withMiddleware([VerifyExtensionToken::class]);
        config(['services.extension.token' => 'valid-token']);

        $this->postJson('/api/products/batch-import', $this->validPayload())
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function request_with_wrong_extension_token_returns_403(): void
    {
        $this->withMiddleware([VerifyExtensionToken::class]);
        config(['services.extension.token' => 'correct-token']);

        $this->postJson('/api/products/batch-import', $this->validPayload(), [
            'X-Extension-Token' => 'wrong-token',
        ])
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function request_with_valid_extension_token_is_not_blocked_by_auth(): void
    {
        // Only testing that the valid token passes the 403 gate.
        // This test still requires MySQL because the controller body uses SUBSTRING_INDEX.
        $this->requireMysql();
        Queue::fake();

        // Re-enable token middleware only; keep tenancy middleware bypassed
        $this->withMiddleware([VerifyExtensionToken::class]);
        $this->withoutMiddleware([InitializeTenancyFromPayload::class]);
        config(['services.extension.token' => 'valid-token']);

        $this->postJson('/api/products/batch-import', $this->validPayload(), [
            'X-Extension-Token' => 'valid-token',
        ])
            ->assertStatus(200);
    }
}
