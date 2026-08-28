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
    public function renewed_title_is_skipped_not_imported(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/product-import', $this->validPayload([
            'external_id' => 'B0RENEWED1',
            'title'       => 'Logitech G915 TKL (Amazon Renewed)',
        ]));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action'  => 'skipped_condition',
            ]);

        $this->assertDatabaseMissing('products', ['category_id' => $this->category->id]);
        $this->assertDatabaseMissing('product_offers', ['url' => 'https://www.amazon.com/dp/B0RENEWED1']);
        Queue::assertNothingPushed();
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
    public function long_title_produces_bounded_stub_slug(): void
    {
        Queue::fake();

        $longTitle = 'Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard with Keychron K Pro Brown Switch LED Backlit Rechargeable Battery 68 Keys Compact Layout for Mac and Windows';

        $response = $this->postJson('/api/product-import', $this->validPayload([
            'external_id' => 'B0LONGTTL1',
            'title'       => $longTitle,
        ]));

        $response->assertOk();

        $product = Product::where('category_id', $this->category->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($product);
        // First-8-words slug stem + '-' + lowercased ASIN comfortably stays well under 75 chars.
        $this->assertLessThan(75, mb_strlen($product->slug), "Slug too long: \"{$product->slug}\"");
        $this->assertStringEndsWith('-b0longttl1', $product->slug);
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

        // Spec 029 §A1: a missing reviews_count must store null, never coerce to 0 —
        // 0 would falsely claim "we checked, there are zero reviews."
        $product = Product::where('category_id', $this->category->id)->firstOrFail();
        $this->assertNull($product->amazon_reviews_count);

        $this->assertDatabaseHas('product_offers', [
            'url' => 'https://www.amazon.com/dp/B0XYZ99999',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Spec 029 — listing-health (condition + listing_flags)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function a_negative_condition_flags_the_product_and_skips_ai_dispatch(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/product-import', $this->validPayload([
            'external_id' => 'B0REFURB01',
            'title'       => 'A Clean-Sounding Title With No Marker',
            'condition'   => 'refurbished',
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'flagged_condition']);

        // Spec 038 B3: never dispatched (never "pending"), so the stub status
        // must be cleared — not left stuck at 'pending_ai' forever.
        $this->assertDatabaseHas('products', ['is_ignored' => true, 'status' => null]);
        $this->assertDatabaseHas('product_offers', ['condition' => 'refurbished']);
        Queue::assertNothingPushed();
    }

    /**
     * Review fix M1 (2026-08-28): an existing product's 'pending_ai' status
     * (written earlier in this same request, line ~134, independent of listing
     * health) must NOT survive an ACTION_FLAGGED_CONDITION outcome — that status
     * was just set by THIS request and nothing will ever dispatch to clear it,
     * so leaving it would strand the row exactly like the bug the migration
     * cleared. ProcessPendingProduct unconditionally overwrites 'status' on
     * every outcome, so clearing here can never strand an in-flight job.
     */
    /** @test */
    public function an_existing_products_status_is_cleared_when_a_rescan_flags_its_condition(): void
    {
        Queue::fake();

        $existing = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'is_ignored'  => false,
        ]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
        ]);

        $response = $this->postJson('/api/product-import', $this->validPayload([
            'title'     => 'A Clean-Sounding Title With No Marker',
            'condition' => 'refurbished',
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'flagged_condition']);

        $this->assertDatabaseHas('products', ['id' => $existing->id, 'is_ignored' => true, 'status' => null]);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function high_price_flag_does_not_ignore_the_product(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/product-import', $this->validPayload([
            'external_id'   => 'B0HIGHPRC1',
            'condition'     => 'new',
            'listing_flags' => ['high_price'],
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'queued_new']);

        $this->assertDatabaseHas('products', ['is_ignored' => false]);
        $this->assertDatabaseHas('product_offers', ['condition' => 'new']);
        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function unavailable_flag_is_stored_and_coerces_stock_status_without_ignoring_the_product(): void
    {
        Queue::fake();

        // "Currently unavailable" page: null price, no explicit stock_status — the
        // flag alone must set out_of_stock; the product stays visible (Spec 029).
        $response = $this->postJson('/api/product-import', $this->validPayload([
            'external_id'   => 'B0UNAVAIL1',
            'price'         => null,
            'condition'     => 'new',
            'listing_flags' => ['unavailable'],
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'queued_new']);

        $this->assertDatabaseHas('products', ['is_ignored' => false]);
        $this->assertDatabaseHas('product_offers', [
            'url'           => 'https://www.amazon.com/dp/B0UNAVAIL1',
            'condition'     => 'new',
            'listing_flags' => json_encode(['unavailable']),
            'stock_status'  => 'out_of_stock',
        ]);
    }

    /** @test */
    public function refresh_updates_image_url_and_leaves_it_untouched_when_omitted(): void
    {
        Queue::fake();

        $existing = Product::factory()->create(['category_id' => $this->category->id, 'status' => null]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        $offer = ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
            'image_url'  => 'https://example.com/old.jpg',
        ]);

        $this->postJson('/api/product-import', $this->validPayload(['image_url' => null]));

        $this->assertSame('https://example.com/old.jpg', $offer->fresh()->image_url);
    }

    /** @test */
    public function s2_a_rating_less_refresh_does_not_wipe_a_previously_known_rating(): void
    {
        Queue::fake();

        $existing = Product::factory()->create([
            'category_id'   => $this->category->id,
            'status'        => null,
            'amazon_rating' => 4.5,
        ]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
        ]);

        // Rescan payload omits `rating` entirely — must NOT null out the known 4.5.
        $payload = $this->validPayload();
        unset($payload['rating']);

        $this->postJson('/api/product-import', $payload)->assertOk();

        $this->assertEquals(4.5, $existing->fresh()->amazon_rating);
    }

    /** @test */
    public function b4_a_pre_owned_title_on_an_existing_offer_rescan_maps_to_canonical_used_and_ignores_the_product(): void
    {
        Queue::fake();

        $existing = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'is_ignored'  => false,
        ]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
        ]);

        // Rescan payload: NO explicit `condition` — the title's "Pre-Owned" marker
        // must map to the canonical 'used' (029B-B4); before the fix the raw marker
        // 'pre-owned' missed NEGATIVE_CONDITIONS and was stored verbatim via the
        // clean branch with the product left visible.
        $response = $this->postJson('/api/product-import', $this->validPayload([
            'title' => 'Sony WH-1000XM5 Wireless Headphones (Pre-Owned)',
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'flagged_condition']);

        $this->assertDatabaseHas('products', ['id' => $existing->id, 'is_ignored' => true]);
        $this->assertDatabaseHas('product_offers', [
            'product_id' => $existing->id,
            'condition'  => 'used',
        ]);
    }

    /** @test */
    public function fix1_a_refurbished_title_wins_over_an_explicit_payload_new_and_ignores_the_products_only_offer(): void
    {
        Queue::fake();

        $existing = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        $offer = ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Delonghi ECAM22110SB Magnifica XS',
        ]);

        // Client wrongly reports 'new' — WLL's own refurbished line puts
        // "Refurbished" literally in the title. The title marker must win.
        $response = $this->postJson('/api/product-import', $this->validPayload([
            'title'     => 'Refurbished Delonghi ECAM22110SB Magnifica XS',
            'condition' => 'new',
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'flagged_condition']);

        $offer->refresh();
        $this->assertSame('refurbished', $offer->condition);
        $this->assertTrue($existing->fresh()->is_ignored, 'the only offer went bad — product must be ignored');
        // Don't burn an AI evaluation on a listing we just ignored for condition.
        Queue::assertNotPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function fix2_a_negative_condition_on_one_offer_leaves_the_product_visible_when_another_offer_is_clean(): void
    {
        Queue::fake();

        $existing = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);

        // A clean, priced, non-Amazon offer that must survive this re-import.
        $otherStore = Store::create(['tenant_id' => $existing->tenant_id, 'slug' => 'whole-latte-love', 'name' => 'Whole Latte Love']);
        ProductOffer::create([
            'product_id'    => $existing->id,
            'tenant_id'     => $existing->tenant_id,
            'store_id'      => $otherStore->id,
            'url'           => 'https://example.com/wll-offer',
            'raw_title'     => 'Sony WH-1000XM5 Wireless Headphones',
            'scraped_price' => 279.99,
            'condition'     => 'new',
        ]);

        $amazonStore = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        $amazonOffer = ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $amazonStore->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
        ]);

        $response = $this->postJson('/api/product-import', $this->validPayload([
            'condition' => 'refurbished',
        ]));

        $response->assertOk()->assertJson(['success' => true, 'action' => 'flagged_offer_condition']);

        $amazonOffer->refresh();
        $this->assertSame('refurbished', $amazonOffer->condition);
        $this->assertFalse($existing->fresh()->is_ignored, 'the WLL offer is still clean — product must stay visible');
        // Fix 2: the product stays visible, so it still gets its normal AI pass.
        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function unknown_is_never_overridden_by_a_title_marker_on_rescan(): void
    {
        Queue::fake();

        $existing = Product::factory()->create(['category_id' => $this->category->id, 'status' => null]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        $offer = ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
        ]);

        $this->postJson('/api/product-import', $this->validPayload([
            'title'     => 'Refurbished Jura E8 Espresso Machine',
            'condition' => 'unknown',
        ]))->assertOk();

        $offer->refresh();
        $this->assertSame('unknown', $offer->condition, '"unknown" must never be overridden by a title marker');
        $this->assertFalse($existing->fresh()->is_ignored);
    }

    /** @test */
    public function m1_a_reimport_of_an_ignored_product_is_skipped_and_never_un_ignores_it(): void
    {
        Queue::fake();

        $existing = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'is_ignored'  => true, // previously condition-flagged or human-ignored
        ]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        ProductOffer::create([
            'product_id' => $existing->id,
            'tenant_id'  => $existing->tenant_id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Name',
        ]);

        // A re-import payload with NO explicit condition — Spec 029's non-goal is
        // exactly this: a re-import/refresh must never automatically un-ignore.
        $response = $this->postJson('/api/product-import', $this->validPayload());

        $response->assertOk()->assertJson(['success' => true, 'action' => 'skipped_ignored']);

        $this->assertDatabaseHas('products', ['id' => $existing->id, 'is_ignored' => true]);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function perf_h1_a_refresh_with_an_unchanged_price_does_not_dispatch_tier_recalc(): void
    {
        Queue::fake();

        $existing = Product::factory()->create(['category_id' => $this->category->id, 'status' => null]);
        $store = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $existing->tenant_id], ['name' => 'Amazon']);
        ProductOffer::create([
            'product_id'    => $existing->id,
            'tenant_id'     => $existing->tenant_id,
            'store_id'      => $store->id,
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'     => 'Old Name',
            'scraped_price' => 349.99,
        ]);

        // validPayload()'s default price (349.99) matches what's already stored.
        $this->postJson('/api/product-import', $this->validPayload())->assertOk();

        Queue::assertNotPushed(\App\Jobs\RecalculateCategoryPriceTiers::class);
    }

    /** @test */
    public function invalid_condition_value_returns_422(): void
    {
        $this->postJson('/api/product-import', $this->validPayload(['condition' => 'mint']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['condition']);
    }

    /** @test */
    public function unrecognized_listing_flag_returns_422(): void
    {
        $this->postJson('/api/product-import', $this->validPayload(['listing_flags' => ['made_up_flag']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['listing_flags.0']);
    }
}
