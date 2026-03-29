<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyFromPayload;
use App\Http\Middleware\VerifyExtensionToken;
use App\Jobs\ProcessPendingProduct;
use App\Models\AiMatchingDecision;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OfferIngestionTest extends TestCase
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
            'url'           => 'https://clivecoffee.com/products/lelit-bianca-v3',
            'store_slug'    => 'clive-coffee',
            'raw_title'     => 'Lelit Bianca V3 Dual Boiler Espresso Machine',
            'brand'         => 'Lelit',
            'scraped_price' => 2799.00,
            'image_url'     => 'https://clivecoffee.com/images/bianca.jpg',
            'category_id'   => $this->category->id,
        ], $overrides);
    }

    /** @test */
    public function new_product_is_created_and_queued_for_ai(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/extension/ingest-offer', $this->validPayload());

        $response->assertOk()->assertJson(['success' => true, 'action' => 'created']);

        $this->assertDatabaseHas('products', [
            'status' => 'pending_ai',
        ]);

        $this->assertDatabaseHas('product_offers', [
            'url' => 'https://clivecoffee.com/products/lelit-bianca-v3',
        ]);

        $this->assertDatabaseHas('stores', [
            'slug' => 'clive-coffee',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function existing_offer_url_refreshes_price(): void
    {
        Queue::fake();

        $store = Store::create(['name' => 'Clive Coffee', 'slug' => 'clive-coffee']);
        $product = Product::factory()->create(['category_id' => $this->category->id, 'status' => null]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://clivecoffee.com/products/lelit-bianca-v3',
            'scraped_price' => 2599.00,
            'raw_title'     => 'Lelit Bianca V3',
        ]);

        $response = $this->postJson('/api/extension/ingest-offer', $this->validPayload());

        $response->assertOk()->assertJson(['success' => true, 'action' => 'refreshed']);

        $this->assertDatabaseHas('product_offers', [
            'url'           => 'https://clivecoffee.com/products/lelit-bianca-v3',
            'scraped_price' => 2799.00,
        ]);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function matched_product_gets_new_offer_without_ai_evaluation(): void
    {
        Queue::fake();

        $brand = Brand::factory()->create(['name' => 'Lelit']);
        $existing = Product::factory()->create([
            'name'        => 'Lelit Bianca V3',
            'brand_id'    => $brand->id,
            'category_id' => $this->category->id,
            'status'      => null,
        ]);

        // Pre-seed AI memory so it doesn't actually call Gemini
        AiMatchingDecision::create([
            'scraped_raw_name'    => 'Lelit Bianca V3 Dual Boiler Espresso Machine',
            'existing_product_id' => $existing->id,
            'is_match'            => true,
        ]);

        $response = $this->postJson('/api/extension/ingest-offer', $this->validPayload());

        $response->assertOk()->assertJson(['success' => true, 'action' => 'matched']);

        // New offer attached to existing product
        $this->assertDatabaseHas('product_offers', [
            'product_id' => $existing->id,
            'url'        => 'https://clivecoffee.com/products/lelit-bianca-v3',
        ]);

        // No AI evaluation dispatched
        Queue::assertNothingPushed();

        // Still only 1 product
        $this->assertDatabaseCount('products', 1);
    }

    /** @test */
    public function validation_rejects_missing_required_fields(): void
    {
        $response = $this->postJson('/api/extension/ingest-offer', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url', 'store_slug', 'raw_title', 'category_id']);
    }

    /** @test */
    public function store_is_auto_created_from_slug(): void
    {
        Queue::fake();

        $this->postJson('/api/extension/ingest-offer', $this->validPayload([
            'store_slug' => 'whole-latte-love',
        ]));

        $this->assertDatabaseHas('stores', [
            'slug' => 'whole-latte-love',
            'name' => 'Whole Latte Love',
        ]);
    }
}
